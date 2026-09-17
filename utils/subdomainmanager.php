<?php

namespace pmwh3\Utils;

use ckvsoft\mvc\Config;
use pmwh3\Config\LazyConfig;
use pmwh3\Utils\WebManager;

/**
 * Orchestrates subdomain lifecycle on top of the configured web
 * adapter (see WebManager / utils/web/).
 *
 * Responsibilities carried here (NOT in the adapter, which stays a
 * dumb row/data worker for its backend):
 *   - validation (label shape, parent domain existence, uniqueness)
 *   - quota accounting via CountingUtil ('subdomains' resource)
 *   - DNS automation via DnsManager (A for IP variant, CNAME for
 *     alias, A-to-domain-IP for directory variant)
 *   - filesystem best effort via FsManager (the mod_perl reader
 *     recreates dirs at Apache start anyway -- failures logged,
 *     never fatal)
 *   - activity log audit trail
 *
 * Result shape for the mutating operations:
 *   ['ok' => bool, 'error' => ?string, ...extra]
 */
class SubdomainManager
{

    // ===== Quota ========================================================

    /**
     * Quota display data for the subdomains UI:
     * max (-1 unlimited / 0 not-in-package / >0 hard) and consumption.
     */
    public static function quota(int $cid): ?array
    {
        if ($cid <= 0) {
            return null;
        }
        return CountingUtil::getQuota($cid, 'subdomains');
    }

    // ===== Create =======================================================

    /**
     * Create a subdomain row + supporting automation.
     *
     * $args keys:
     *   sub       label only ('news', NOT 'news.example.com')
     *   domain    parent domain (must exist in pmwh3_domains)
     *   mode      'directory' | 'ip' | 'alias'
     *   value     directory name (mode directory), IP address (ip),
     *             or target FQDN (alias)
     *   cid       owner customer id
     *   custom    custom config section (rendered between the
     *             START/END markers; empty -> configured snippet)
     *   dns       bool, create DNS records (default true)
     */
    public static function create(array $args): array
    {
        $sub     = strtolower(trim((string) ($args['sub'] ?? '')));
        $domain  = strtolower(trim((string) ($args['domain'] ?? '')));
        $mode    = (string) ($args['mode'] ?? 'directory');
        $value   = trim((string) ($args['value'] ?? ''));
        $cid     = (int) ($args['cid'] ?? 0);
        $custom  = (string) ($args['custom'] ?? '');
        $withDns = (bool) ($args['dns'] ?? true);

        if (!preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?$/', $sub)) {
            return self::fail('Invalid subdomain label');
        }
        if (CustomerUtil::isReservedName($sub)) {
            return self::fail(sprintf('Subdomain name %s is reserved', $sub));
        }
        $domainRow = DomainManager::getByName($domain);
        if ($domainRow === null) {
            return self::fail('Unknown parent domain');
        }
        $qd = strtolower((string) ($domainRow['domain'] ?? ''));
        if ($qd !== '' && !str_ends_with($domain, $qd)) {
            // Only direct children of a managed top-level domain
            // (pmwh2's "no sub-of-sub at once" rule).
            return self::fail('Subdomains of subdomains are not supported');
        }
        $fqdn = $sub . '.' . $domain;
        if (WebManager::rowExists($fqdn) || DomainManager::getByName($fqdn) !== null) {
            return self::fail('Subdomain already exists');
        }
        if (!in_array($mode, ['directory', 'ip', 'alias'], true)) {
            return self::fail('Unknown mode');
        }

        $customer = (string) ($domainRow['customer'] ?? '');
        $countAlias = strtoupper((string) LazyConfig::get('COUNT_SUBDOMAIN_ALIASES', 'N')) === 'Y';
        if ($mode !== 'alias' || $countAlias) {
            if (!CountingUtil::isAllowed($cid, 'subdomains')) {
                $q = CountingUtil::getQuota($cid, 'subdomains');
                return self::fail($q['max'] === 0
                        ? 'Subdomains are not part of this customer\'s package'
                        : sprintf('Subdomain quota exhausted (%d/%d used)',
                                  $q['used'] + $q['granted'], $q['max']));
            }
        }

        // ssl_cert: 'auto' (default) resolves a certificate for the new
        // FQDN from the cert directory (WEB_SSL_DIR, '_'-
        // wildcard aware); '' = http-only; a basename = explicit pick.
        // Ignored for the ip variant (no vhost).
        $sslArg = trim((string) ($args['ssl_cert'] ?? 'auto'));
        if ($sslArg === 'auto' || $sslArg === '1') {
            $auto = WebManager::resolveCert($fqdn);
            $sslArg = $auto !== null ? $auto : '';
        }
        try {
            return match ($mode) {
                'ip'        => self::createIpVariant($fqdn, $domain, $customer, $value, $cid, $withDns),
                'alias'     => self::createAlias($fqdn, $value, $domain, $customer, $custom, $cid, $withDns, $countAlias, $sslArg),
                default     => self::createDirectory($fqdn, $domain, $sub, $customer, $custom, $cid, $withDns, $sslArg),
            };
        } catch (\Throwable $e) {
            \pmwh3\Utils\ErrorHandler::trace("SubdomainManager::create {$fqdn}: " . $e->getMessage());
            return self::fail('Backend error: ' . $e->getMessage());
        }
    }

    // ===== Update =======================================================

    /**
     * Update one subdomain. $fields keys (all optional):
     *   sub         new label (rename within the same parent)
     *   custom      replace the custom section between the markers
     *   regenerate  true -> rebuild the skeleton (identity/path/
     *               snippet resolution) around the existing custom
     *               section
     *
     * A rename on a directory-variant row also moves the docroot
     * (best effort) and follows any alias rows over to the new name.
     */
    public static function update(string $fqdn, array $fields, int $cid = 0): array
    {
        $fqdn = strtolower(trim($fqdn));
        $row = WebManager::getRow($fqdn);
        if (!$row) {
            return self::fail('Subdomain not found');
        }

        try {
            $custom     = array_key_exists('custom', $fields)
                    ? trim((string) $fields['custom']) : null;
            $regenerate = (bool) ($fields['regenerate'] ?? false);
            // ssl_cert change: '' strips the TLS block, a basename
            // binds that certificate, 'auto' re-resolves for (new) fqdn.
            $sslWanted = array_key_exists('ssl_cert', $fields)
                    ? trim((string) $fields['ssl_cert']) : null;

            $newSub  = strtolower(trim((string) ($fields['sub'] ?? '')));
            $renames = $newSub !== '' && $newSub !== self::labelOf($fqdn);
            if ($renames
                    && !preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?$/', $newSub)) {
                return self::fail('Invalid subdomain label');
            }
            if ($renames && CustomerUtil::isReservedName($newSub)) {
                return self::fail(sprintf('Subdomain name %s is reserved', $newSub));
            }
            $newFqdn = $renames ? $newSub . '.' . $row['domain'] : $fqdn;
            if ($renames
                    && (WebManager::rowExists($newFqdn)
                        || DomainManager::getByName($newFqdn) !== null)) {
                return self::fail('New subdomain name already exists');
            }

            $oldPath = (string) $row['path'];
            $data    = (string) ($row['data'] ?? '');
            $newPath = $oldPath;

            $renamesWithVhost = $renames && $data !== '' && $oldPath !== ''
                    && $oldPath !== $fqdn && !filter_var($oldPath, FILTER_VALIDATE_IP);

            // cert-only change (no rename): bake the requested cert
            // into the skeleton, keeping the custom section.
            if ($sslWanted !== null && !$renamesWithVhost && $data !== '') {
                $ssl = $sslWanted;
                if ($ssl === 'auto' || $ssl === '1') {
                    $ssl = (string) (WebManager::resolveCert($newFqdn) ?? '');
                }
                if (preg_match('/SSLCertificateFile\s+[^\s]*\/([^\/\s]+)\.pem/',
                               $data, $sslMatches)) {
                    $sslCurrent = (string) $sslMatches[1];
                    if ($ssl !== $sslCurrent) {
                        $data = self::renderFromRow($row, $custom ?? WebManager::extractCustom($data), $ssl);
                    }
                } elseif ($ssl !== '') {
                    $data = self::renderFromRow($row,
                            $custom ?? WebManager::extractCustom($data), $ssl);
                }
            }

            if ($renamesWithVhost) {
                // Directory variant: move the docroot and re-render
                // the skeleton with the new name/path.
                $newPath = self::siblingPath($oldPath, self::labelOf($fqdn), $newSub);
                if ($newPath !== $oldPath) {
                    self::fsRename($oldPath, $newPath);
                    $data = self::repathVhost($data, $oldPath, $newPath);
                }
                $rowOverride = $row;
                $rowOverride['subdomain'] = $newFqdn;
                $rowOverride['path'] = $newPath;
                $ssl = '';
                if (preg_match('/SSLCertificateFile\s+[^\s]*\/([^\/\s]+)\.pem/',
                               $data, $sslMatchesCurrent)) {
                    $ssl = (string) $sslMatchesCurrent[1];
                }
                if ($sslWanted !== null) {
                    $ssl = $sslWanted;
                }
                if ($ssl === 'auto' || $ssl === '1') {
                    $ssl = (string) (WebManager::resolveCert($newFqdn) ?? '');
                }
                $data = self::renderFromRow($rowOverride,
                        $custom ?? WebManager::extractCustom($data), $ssl);
            } elseif ($custom !== null && $data !== '') {
                $data = self::replaceCustom($data, $custom);
            }

            $update = [];
            if ($renames) {
                $update['subdomain'] = $newFqdn;
            }
            if ($newPath !== $oldPath) {
                $update['path'] = $newPath;
            }
            if ($data !== '' && $data !== ($row['data'] ?? '')) {
                $update['data'] = $data;
            }
            if ($custom !== null) {
                $update['custom'] = $custom;
            }
            if ($sslWanted !== null && $sslWanted !== 'auto') {
                $update['ssl_cert'] = $sslWanted;
            }
            if (!empty($update)) {
                WebManager::updateSubdomain($fqdn, $update);
            }

            if ($renames) {
                foreach (WebManager::listByDomain((string) $row['domain']) as $al) {
                    if (($al['alias_of'] ?? '') === $fqdn) {
                        WebManager::updateSubdomain(
                                (string) $al['subdomain'], ['alias_of' => $newFqdn]);
                    }
                }
                self::dnsRename($fqdn, $newFqdn, (string) $row['domain']);
            }
            if ($regenerate) {
                WebManager::regenerateVhostData($newFqdn);
            }

            ActivityLog::write('domain', 'subdomain_update', $fqdn,
                    $renames ? "rename->{$newFqdn}" : 'edited');
            return ['ok' => true, 'error' => null, 'fqdn' => $newFqdn];
        } catch (\Throwable $e) {
            \pmwh3\Utils\ErrorHandler::trace("SubdomainManager::update {$fqdn}: " . $e->getMessage());
            return self::fail('Backend error: ' . $e->getMessage());
        }
    }

    // ===== Delete =======================================================

    /**
     * Delete one subdomain. Cascades alias rows when
     * DELETE_SUBDOMAIN_ALIASES=Y. DNS record removed by FQDN; FS rm
     * is best effort and skipped for the IP variant (path is an IP).
     */
    public static function delete(string $fqdn, int $cid = 0): array
    {
        $fqdn = strtolower(trim($fqdn));
        $row = WebManager::getRow($fqdn);
        if (!$row) {
            return self::fail('Subdomain not found');
        }

        try {
            $cascade = strtoupper((string) LazyConfig::get('DELETE_SUBDOMAIN_ALIASES', 'Y')) === 'Y';
            $deletedAliases = 0;
            if ($cascade) {
                foreach (WebManager::listByDomain((string) $row['domain']) as $al) {
                    $alFqdn = (string) $al['subdomain'];
                    if ($alFqdn === $fqdn || ($al['alias_of'] ?? '') !== $fqdn) {
                        continue;
                    }
                    WebManager::deleteSubdomain($alFqdn);
                    self::dnsDelete($alFqdn, (string) $row['domain']);
                    $deletedAliases++;
                }
            }

            self::dnsDelete($fqdn, (string) $row['domain']);
            self::fsRm((string) $row['path']);
            WebManager::deleteSubdomain($fqdn);
            // Aliases counted only when COUNT_SUBDOMAIN_ALIASES=Y.
            $delta = 1;
            if (strtoupper((string) LazyConfig::get('COUNT_SUBDOMAIN_ALIASES', 'N')) === 'Y') {
                $delta += $deletedAliases;
            }
            CountingUtil::decrement($cid, 'subdomains', $delta);

            ActivityLog::write('domain', 'subdomain_delete', $fqdn,
                    $deletedAliases > 0 ? "aliases={$deletedAliases}" : '');
            return ['ok' => true, 'error' => null, 'aliases' => $deletedAliases];
        } catch (\Throwable $e) {
            \pmwh3\Utils\ErrorHandler::trace("SubdomainManager::delete {$fqdn}: " . $e->getMessage());
            return self::fail('Backend error: ' . $e->getMessage());
        }
    }

    // ===== create variants ==============================================

    private static function createDirectory(
            string $fqdn, string $domain, string $sub, string $customer,
            string $custom, int $cid, bool $withDns, string $sslCert = ''
    ): array {
        if ($sub === '' || str_contains($sub, '/')) {
            return self::fail('Directory name must be a plain name (no slashes)');
        }
        $webroot = FsManager::resolveWebroot($customer, $domain);
        $path = rtrim($webroot, '/') . '/' . $sub . '/';

        self::fsCreateDocroot($path, $fqdn);

        $data = WebManager::buildVhostData([
            'subdomain' => $fqdn,
            'domain'    => $domain,
            'customer'  => $customer,
            'path'      => $path,
            'custom'    => $custom,
            'ssl_cert'  => $sslCert,
        ]);
        WebManager::createSubdomain([
            'subdomain' => $fqdn,
            'domain'    => $domain,
            'customer'  => $customer,
            'path'      => $path,
            'mode'      => 'directory',
            'ssl_cert'  => ($sslCert !== '' ? $sslCert : null),
            'custom'    => ($custom !== '' ? $custom : null),
            'data'      => $data !== '' ? $data : null,
        ]);

        if ($withDns) {
            $ip = DomainUtil::getIpByDomain($domain);
            if ($ip !== '') {
                self::dnsAdd($domain, $fqdn, 'A', $ip);
            }
        }

        CountingUtil::increment($cid, 'subdomains');
        ActivityLog::write('domain', 'subdomain_create', $fqdn,
                'mode=directory; path=' . $path);
        return ['ok' => true, 'error' => null, 'fqdn' => $fqdn, 'path' => $path];
    }

    private static function createAlias(
            string $fqdn, string $target, string $domain, string $customer,
            string $custom, int $cid, bool $withDns, bool $countAlias, string $sslCert = ''
    ): array {
        $target = strtolower(trim($target, '.'));
        if ($target === '' || !str_contains($target, '.')) {
            return self::fail('Alias target must be a full name like target.example.com');
        }
        if (!str_contains($target, '.' . $domain)) {
            return self::fail('Alias target must belong to the same domain tree');
        }
        $row = WebManager::getRow($target);
        if (!$row) {
            return self::fail('Alias target not found');
        }
        $targetPath = (string) $row['path'];
        $targetData = (string) ($row['data'] ?? '');
        if ($targetData === '' || $targetPath === '') {
            // IP variant has no vhost -- aliasing it makes no sense.
            return self::fail('Alias target has no local vhost');
        }

        $data = WebManager::buildVhostData([
            'subdomain' => $fqdn,
            'domain'    => $domain,
            'customer'  => $customer,
            'path'      => $targetPath,
            'alias_of'  => $target,
            'custom'    => WebManager::extractCustom($targetData) ?: $custom,
            'ssl_cert'  => $sslCert,
        ]);
        WebManager::createSubdomain([
            'subdomain' => $fqdn,
            'domain'    => $domain,
            'customer'  => $customer,
            'path'      => $targetPath,
            'mode'      => 'alias',
            'alias_of'  => $target,
            'ssl_cert'  => ($sslCert !== '' ? $sslCert : null),
            'custom'    => ($custom !== '' ? $custom : null),
            'data'      => $data !== '' ? $data : null,
        ]);

        if ($withDns) {
            self::dnsAdd($domain, $fqdn, 'CNAME', $target);
        }

        if ($countAlias) {
            CountingUtil::increment($cid, 'subdomains');
        }
        ActivityLog::write('domain', 'subdomain_create', $fqdn,
                "mode=alias; alias_of={$target}");
        return ['ok' => true, 'error' => null, 'fqdn' => $fqdn, 'alias_of' => $target];
    }

    private static function createIpVariant(
            string $fqdn, string $domain, string $customer,
            string $ip, int $cid, bool $withDns
    ): array {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return self::fail('Invalid IP address');
        }
        // IP variant: DNS-only reference to a (possibly foreign) host.
        // path=<ip>, data NULL -> invisible to the vhost reader.
        WebManager::createSubdomain([
            'subdomain' => $fqdn,
            'domain'    => $domain,
            'customer'  => $customer,
            'mode'      => 'ip',
            'ip'        => $ip,
            'path'      => $ip,
            'data'      => null,
        ]);
        if ($withDns) {
            self::dnsAdd($domain, $fqdn, 'A', $ip);
        }
        CountingUtil::increment($cid, 'subdomains');
        ActivityLog::write('domain', 'subdomain_create', $fqdn, "mode=ip; ip={$ip}");
        return ['ok' => true, 'error' => null, 'fqdn' => $fqdn, 'ip' => $ip];
    }

    // ===== dns/fs helpers (best effort, never fatal) ====================

    private static function dnsAdd(string $domain, string $fqdn, string $type, string $content): void
    {
        if (!DnsManager::supports('record-write')) {
            return;
        }
        if (DnsManager::addRecord($domain, $fqdn, $type, $content) === 0) {
            \pmwh3\Utils\ErrorHandler::trace("SubdomainManager: DNS record for {$fqdn} not created");
        }
    }

    private static function dnsDelete(string $fqdn, string $domain = ''): void
    {
        if (!DnsManager::supports('record-write')) {
            return;
        }
        // The records live in the zone of the parent domain -- use the
        // domain from the row, not the TLD derivation (a subdomain of
        // a subdomain would otherwise resolve to the wrong zone).
        $zone = $domain !== '' ? strtolower($domain)
                : DomainUtil::getToplevelDomain($fqdn);
        foreach (DnsManager::listRecords($zone) as $rec) {
            if (strtolower((string) $rec['name']) === $fqdn) {
                DnsManager::deleteRecord((int) $rec['id']);
            }
        }
    }

    private static function dnsRename(string $oldFqdn, string $newFqdn, string $domain): void
    {
        if (!DnsManager::supports('record-write')) {
            return;
        }
        foreach (DnsManager::listRecords($domain) as $rec) {
            if (strtolower((string) $rec['name']) !== $oldFqdn) {
                continue;
            }
            $fields = ['name' => $newFqdn];
            if (strtoupper((string) $rec['type']) === 'CNAME') {
                $fields['content'] = $newFqdn;
            }
            DnsManager::updateRecord((int) $rec['id'], $fields);
        }
    }

    private static function fsCreateDocroot(string $path, string $fqdn): void
    {
        if (!FsManager::isEnabled()) {
            return; // backend (e.g. mod_perl) creates dirs on its own
        }
        try {
            if (!FsManager::mkdir($path)) {
                \pmwh3\Utils\ErrorHandler::trace("SubdomainManager: mkdir {$path} failed (non-fatal)");
            }
            FsManager::writeIndexFile($path, $fqdn);
        } catch (\Throwable $e) {
            \pmwh3\Utils\ErrorHandler::trace("SubdomainManager: docroot {$path}: " . $e->getMessage());
        }
    }

    private static function fsRename(string $oldPath, string $newPath): void
    {
        if (!FsManager::isEnabled()) {
            return;
        }
        try {
            if (self::pathUnderWebroot($oldPath) && self::pathParentUnderWebroot($newPath)
                    && FsManager::exists($oldPath)) {
                FsManager::rename($oldPath, $newPath);
            }
        } catch (\Throwable $e) {
            \pmwh3\Utils\ErrorHandler::trace("SubdomainManager: rename {$oldPath}: " . $e->getMessage());
        }
    }

    private static function fsRm(string $path): void
    {
        if (!FsManager::isEnabled() || $path === ''
                || filter_var($path, FILTER_VALIDATE_IP)) {
            return; // IP variant has no directory
        }
        try {
            if (self::pathUnderWebroot($path) && FsManager::exists($path)) {
                FsManager::rmdir($path);
            }
        } catch (\Throwable $e) {
            \pmwh3\Utils\ErrorHandler::trace("SubdomainManager: rmdir {$path}: " . $e->getMessage());
        }
    }

    /** Safety rail: only touch directories inside the configured webroot. */
    private static function pathUnderWebroot(string $path): bool
    {
        $real = realpath($path);
        if ($real === false) {
            return false; // nonexistent paths are not ours to touch
        }
        $base = rtrim((string) LazyConfig::get('WEBROOT', '/vhome'), '/');
        $baseReal = realpath($base);
        if ($baseReal === false) {
            $baseReal = $base;
        }
        return str_starts_with($real, $baseReal . '/');
    }

    /** Same as pathUnderWebroot() but tolerant of a not-yet-existing target. */
    private static function pathParentUnderWebroot(string $path): bool
    {
        $real = realpath(dirname($path));
        if ($real === false) {
            return false;
        }
        $base = rtrim((string) LazyConfig::get('WEBROOT', '/vhome'), '/');
        $baseReal = realpath($base) ?: $base;
        return str_starts_with($real, $baseReal . '/');
    }

    // ===== data text helpers ============================================

    private static function labelOf(string $fqdn): string
    {
        return explode('.', $fqdn, 2)[0];
    }

    private static function siblingPath(string $oldSubPath, string $oldLabel, string $newLabel): string
    {
        $needle = '/' . $oldLabel;
        $pos = strrpos($oldSubPath, $needle);
        if ($oldLabel === '' || $pos === false) {
            return $oldSubPath;
        }
        return substr($oldSubPath, 0, $pos) . '/' . $newLabel
                . substr($oldSubPath, $pos + strlen($needle));
    }

    private static function repathVhost(string $data, string $oldPath, string $newPath): string
    {
        return str_replace($oldPath, $newPath, $data);
    }

    private static function renderFromRow(array $row, string $custom, string $sslCert = ''): string
    {
        return WebManager::buildVhostData([
            'subdomain' => $row['subdomain'],
            'domain'    => $row['domain'],
            'customer'  => $row['customer'],
            'path'      => $row['path'],
            'alias_of'  => $row['alias_of'],
            'custom'    => $custom,
            'ssl_cert'  => $sslCert,
        ]);
    }

    /** Custom section of a data block, marker-aware. */

    /**
     * Replace the custom section between the markers, preserving the
     * indentation of the marker lines. Data without markers is left
     * untouched (hand-maintained rows must not be silently mangled).
     */
    private static function replaceCustom(string $data, string $newCustom): string
    {
        $begin = '### START CUSTOM ###';
        $end   = '### END CUSTOM ###';
        $b = strpos($data, $begin);
        $e = strpos($data, $end);
        if ($b === false || $e === false || $e < $b) {
            return $data;
        }
        $lineStart = strrpos(substr($data, 0, $b), "\n");
        $indent = '';
        if ($lineStart !== false) {
            $prefix = substr($data, $lineStart + 1, $b - $lineStart - strlen($begin) - 1);
            if (preg_match('/^[ \t]*$/', $prefix)) {
                $indent = $prefix;
            }
        }
        $lines = array_map(
                fn($l) => $indent . $l,
                preg_split('/\R/', trim($newCustom)) ?: []
        );
        return substr($data, 0, $b + strlen($begin)) . "\n"
                . implode("\n", $lines) . "\n"
                . $indent . substr($data, $e);
    }

    private static function fail(string $msg): array
    {
        return ['ok' => false, 'error' => $msg];
    }

    // ===== Standard subdomain provision (G7) ============================

    /**
     * Create the standard subdomains for a newly created domain --
     * pmwh2 insertDomain semantics, ported:
     *
     *   pattern "www(ftp);smtp(imap,pop3,mail)" (AUTO_SUBDOMAIN) means
     *     - www.example.com  as directory variant (docroot)
     *     - ftp.example.com  as alias of www.example.com
     *     - smtp.example.com as directory variant
     *     - imap/pop3/mail.example.com as aliases of smtp.example.com
     *   bare "name" entries without parentheses are directories.
     *
     * Best effort per entry: already-existing / reserved / quota-
     * blocked labels are skipped and reported, never fatal.
     *
     * @param string $domain  fully qualified parent domain
     * @param int    $cid     customer id
     * @param string $pattern override pattern ('' -> AUTO_SUBDOMAIN setting)
     * @return array ['created' => [fqdn...], 'skipped' => [fqdn => err, ...]]
     */
    public static function provisionDefaults(string $domain, int $cid, string $pattern = ''): array
    {
        $created = [];
        $skipped = [];
        $pattern = trim($pattern !== '' ? $pattern
                : (string) LazyConfig::get('AUTO_SUBDOMAIN', 'www(ftp);smtp(imap,pop3,mail)'));
        if ($pattern === '') {
            return ['created' => $created, 'skipped' => $skipped];
        }
        foreach (explode(';', $pattern) as $raw) {
            $element = str_replace(' ', '', trim($raw));
            if ($element === '') {
                continue;
            }
            $open  = strpos($element, '(');
            $close = strpos($element, ')');
            if ($open !== false && $close !== false && $close > $open) {
                $name    = substr($element, 0, $open);
                $aliases = array_filter(array_map('trim',
                        explode(',', substr($element, $open + 1, $close - $open - 1))));
            } else {
                $name    = $element;
                $aliases = [];
            }
            if ($name === '' || !preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?$/i', $name)) {
                continue;
            }
            $res = self::create([
                'sub'    => strtolower($name),
                'domain' => $domain,
                'cid'    => $cid,
                'mode'   => 'directory',
                'dns'    => true,
            ]);
            if (!empty($res['ok'])) {
                $created[] = strtolower($name) . '.' . $domain;
            } else {
                $skipped[$name . '.' . $domain] = (string) ($res['error'] ?? '?');
                continue; // aliases of a failed base make no sense
            }
            foreach ($aliases as $alias) {
                if ($alias === '' || !preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?$/i', $alias)) {
                    continue;
                }
                $resAlias = self::create([
                    'sub'    => strtolower($alias),
                    'domain' => $domain,
                    'cid'    => $cid,
                    'mode'   => 'alias',
                    'value'  => strtolower($name) . '.' . $domain,
                    'dns'    => true,
                ]);
                if (!empty($resAlias['ok'])) {
                    $created[] = strtolower($alias) . '.' . $domain;
                } else {
                    $skipped[$alias . '.' . $domain] = (string) ($resAlias['error'] ?? '?');
                }
            }
        }
        return ['created' => $created, 'skipped' => $skipped];
    }
}
