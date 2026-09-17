<?php

namespace pmwh3\Utils;

use ckvsoft\mvc\Config;
use pmwh3\Config\LazyConfig;

/**
 * High-level domain operations.
 *
 * pmwh3_domains is the canonical table; subdomain rows live in
 * pmwh3_web_subdomains (Apache reads them via mod_perl), DNS records
 * live in pdns_* (managed via DnsManager), webroot directories on
 * disk are managed via FsManager.
 *
 * create()/delete() orchestrate all of those based on the domain's
 * `services` field and the module's options settings (master switch
 * CREATE_DIRECTORIES, DNS_TYPE, DNS_SERVERS, MX_SERVERS, ...).
 */
class DomainManager
{

    // ===== Reads =========================================================

    public static function getByName(string $domain): ?array
    {
        $row = Config::moduleDb()->selectOne(
                "SELECT * FROM pmwh3_domains WHERE domain = :d LIMIT 1",
                ['d' => $domain]
        );
        return $row ?: null;
    }

    public static function listForCustomer(int $cid): array
    {
        if ($cid <= 0) {
            return [];
        }
        return Config::moduleDb()->select(
                "SELECT * FROM pmwh3_domains WHERE cid = :c ORDER BY domain",
                ['c' => $cid]
        );
    }

    /**
     * All domains visible to the given viewer (admin sees everything,
     * resellers see their own customers' domains, customers see their
     * own).
     */
    public static function listAllVisible(int $viewerCid): array
    {
        $hierarchy = CustomerUtil::getCustomerHierarchy($viewerCid, 'all');
        $cids = array_keys($hierarchy);
        if (empty($cids)) {
            return [];
        }
        $placeholders = [];
        $bindings = [];
        foreach ($cids as $i => $c) {
            $placeholders[] = ":c{$i}";
            $bindings["c{$i}"] = $c;
        }
        $sql = "SELECT * FROM pmwh3_domains
                 WHERE cid IN (" . implode(',', $placeholders) . ")
              ORDER BY domain";
        return Config::moduleDb()->select($sql, $bindings);
    }

    // ===== Writes ========================================================

    /**
     * Create a domain end-to-end. Honors options settings:
     *
     *   CREATE_DIRECTORIES (Y/N) -> FsManager::createWebroot()
     *   DNS_TYPE / DNS_SERVERS   -> DnsManager::createZone()
     *   apache vhost row         -> pmwh3_web_subdomains insert
     *
     * Errors in optional steps (DNS, FS) are logged but don't roll
     * back the pmwh3_domains row -- the admin can fix and re-trigger
     * via the details page.
     *
     * @return bool true on success (i.e. pmwh3_domains row created)
     */
    public static function create(array $row): bool
    {
        $domain    = strtolower(trim((string) ($row['domain'] ?? '')));
        $cid       = (int) ($row['cid'] ?? 0);
        $services  = (string) ($row['services'] ?? 'web,mail,dns');
        $ip        = (string) ($row['ip'] ?? '');
        $regStart  = (string) ($row['reg_start']  ?? date('Y-m-d'));
        $regEnd    = (string) ($row['reg_end']    ?? date('Y-m-d', strtotime('+1 year')));
        $hostStart = (string) ($row['host_start'] ?? date('Y-m-d'));
        $hostEnd   = (string) ($row['host_end']   ?? date('Y-m-d', strtotime('+1 year')));

        if ($domain === '' || $cid <= 0) {
            return false;
        }
        // Don't double-create.
        if (self::getByName($domain) !== null) {
            return false;
        }

        $customer = (string) (CustomerUtil::getCustomerNameById($cid) ?? '');
        $path     = self::resolveWebrootPath($customer, $domain);

        // 1. The canonical row.
        Config::moduleDb()->insert('pmwh3_domains', [
            'domain'     => $domain,
            'customer'   => $customer,
            'cid'        => $cid,
            'path'       => $path,
            'ip'         => $ip,
            'services'   => $services,
            'reg_start'  => $regStart,
            'reg_end'    => $regEnd,
            'host_start' => $hostStart,
            'host_end'   => $hostEnd,
        ]);

        // 2. Apache vhost row (if web service)
        if (str_contains($services, 'web')) {
            try {
                self::ensureApacheRow($customer, $domain, $path, $ip);
            } catch (\Throwable $e) {
                error_log("DomainManager::create apache: " . $e->getMessage());
            }
        }

        // 3. Filesystem webroot
        if (str_contains($services, 'web') && FsManager::isEnabled()) {
            try {
                FsManager::createWebroot($customer, $domain);
            } catch (\Throwable $e) {
                error_log("DomainManager::create fs: " . $e->getMessage());
            }
        }

        // 4. DNS zone with default records
        if (str_contains($services, 'dns') && DnsManager::isEnabled()) {
            try {
                if (!DnsManager::zoneExists($domain)) {
                    $extra = [];
                    $ns = self::getNameservers();
                    if (!empty($ns)) {
                        $extra['ns'] = $ns;
                    }
                    $mx = self::getPrimaryMx();
                    if ($mx !== '') {
                        $extra['mx'] = $mx;
                    }
                    $admin = "hostmaster@{$domain}";
                    DnsManager::createZone($domain, $ip, $admin, $extra);
                }
            } catch (\Throwable $e) {
                error_log("DomainManager::create dns: " . $e->getMessage());
            }
        }

        // 5. Standard subdomains (pmwh2 semantics: CREATE_SUBDOMAIN on
        //    AND the customer opted in via standard_subdomain == 'Y';
        //    pattern = AUTO_SUBDOMAIN setting). Best effort.
        if (str_contains($services, 'web')
                && strtoupper((string) LazyConfig::get('CREATE_SUBDOMAIN', 'Y')) === 'Y') {
            try {
                $sd = Config::moduleDb()->selectOne(
                        "SELECT standard_subdomain FROM pmwh3_customers WHERE cid = :c LIMIT 1",
                        ['c' => $cid]);
                if ($sd !== null
                        && strtoupper((string) ($sd['standard_subdomain'] ?? 'N')) === 'Y') {
                    SubdomainManager::provisionDefaults($domain, $cid);
                }
            } catch (\Throwable $e) {
                error_log("DomainManager::create subdomains: " . $e->getMessage());
            }
        }

        // 6. Mail transport row (postfix-only soft capability; other
        //    adapters no-op in MailManager::syncDomainTransport()).
        //    Best effort -- a missing row just falls back to postfix's
        //    default transport.
        if (str_contains($services, 'mail')) {
            try {
                MailManager::syncDomainTransport($domain, true);
            } catch (\Throwable $e) {
                error_log("DomainManager::create transport: " . $e->getMessage());
            }
        }

        return true;
    }

    /**
     * Update mutable fields. The domain name itself is the PK so we
     * don't allow changing it -- callers should delete and recreate.
     */
    public static function update(string $domain, array $fields): bool
    {
        $row = self::getByName($domain);
        if (!$row) {
            return false;
        }
        $allowed = [];
        foreach (['ip','services','reg_start','reg_end','host_start','host_end','path'] as $f) {
            if (array_key_exists($f, $fields)) {
                $allowed[$f] = $fields[$f];
            }
        }
        if (empty($allowed)) {
            return false;
        }
        Config::moduleDb()->update(
                'pmwh3_domains',
                $allowed,
                'domain = :d',
                ['d' => $domain]
        );

        // Services switch -> refresh the mail transport row (postfix-only
        // soft capability; no-op for other adapters). Best effort.
        if (array_key_exists('services', $allowed)) {
            try {
                MailManager::syncDomainTransport($domain, str_contains((string) $allowed['services'], 'mail'));
            } catch (\Throwable $e) {
                error_log("DomainManager::update transport: " . $e->getMessage());
            }
        }

        return true;
    }

    /**
     * Delete a domain and all its dependencies:
     *   - pmwh3_web_subdomains row + alias rows
     *   - DNS zone + records
     *   - filesystem webroot
     *   - pmwh3_domains row
     *
     * Optional cascade is best-effort -- a failure in DNS or FS
     * doesn't block the row delete.
     */
    public static function delete(string $domain, bool $deleteFs = true): bool
    {
        $row = self::getByName($domain);
        if (!$row) {
            return false;
        }
        $services = (string) ($row['services'] ?? '');
        $customer = (string) ($row['customer'] ?? '');

        // 1. apache rows -- subdomain matches the apex, plus aliases
        try {
            $db = Config::moduleDb();
            $db->delete('pmwh3_web_subdomains', 'subdomain = :s', ['s' => $domain]);
            $db->delete('pmwh3_web_subdomains', 'alias_of = :s',  ['s' => $domain]);
        } catch (\Throwable $e) {
            error_log("DomainManager::delete apache: " . $e->getMessage());
        }

        // 2. DNS zone
        if (str_contains($services, 'dns') && DnsManager::isEnabled()) {
            try {
                DnsManager::deleteZone($domain);
            } catch (\Throwable $e) {
                error_log("DomainManager::delete dns: " . $e->getMessage());
            }
        }

        // 2b. Mail transport row (postfix-only soft capability).
        if (str_contains($services, 'mail')) {
            try {
                MailManager::syncDomainTransport($domain, false);
            } catch (\Throwable $e) {
                error_log("DomainManager::delete transport: " . $e->getMessage());
            }
        }

        // 3. Webroot
        if ($deleteFs && str_contains($services, 'web') && FsManager::isEnabled()) {
            try {
                FsManager::deleteWebroot($customer, $domain);
            } catch (\Throwable $e) {
                error_log("DomainManager::delete fs: " . $e->getMessage());
            }
        }

        // 4. The canonical row.
        Config::moduleDb()->delete('pmwh3_domains', 'domain = :d', ['d' => $domain]);
        return true;
    }

    /**
     * Default registration / hosting dates for a fresh domain form.
     * Matches what the legacy controller used to compute inline.
     */
    public static function getDomainDates(): array
    {
        $today = date('Y-m-d');
        $oneMonth = date('Y-m-d', strtotime('+1 month'));
        $oneYear  = date('Y-m-d', strtotime('+1 year'));
        return [
            'reg_start'  => $today,
            'reg_end'    => $oneYear,
            'host_start' => $today,
            'host_end'   => $oneMonth,
        ];
    }

    // ===== Internals =====================================================

    private static function resolveWebrootPath(string $customer, string $domain): string
    {
        // Match the existing FsManager::resolveWebroot convention.
        // We compute it ourselves (without filesystem) because callers
        // need it even when CREATE_DIRECTORIES is off.
        if (FsManager::isEnabled()) {
            return FsManager::resolveWebroot($customer, $domain);
        }
        $base = (string) LazyConfig::get('FS_WEBROOT_BASE', '/var/www');
        return rtrim($base, '/') . "/{$customer}/{$domain}";
    }

    private static function ensureApacheRow(string $customer, string $domain, string $path, string $ip): void
    {
        $db = Config::moduleDb();
        $existing = $db->selectOne(
                "SELECT 1 FROM pmwh3_web_subdomains WHERE subdomain = :s",
                ['s' => $domain]
        );
        if ($existing) {
            return;
        }
        // Schema we know: subdomain (PK-ish), domain, customer,
        // alias_of NULL, path, ip. Insert minimal columns; missing
        // ones get DB defaults. Row shape == SubdomainManager rows:
        // subdomain holds the FQDN, domain the (self) parent.
        $cols = [
            'subdomain' => $domain,
            'customer'  => $customer,
        ];
        // Optional cols -- only insert if columns exist (Apache's
        // pmwh2 schema has slight per-install variation).
        if (self::columnExists('pmwh3_web_subdomains', 'domain')) {
            $cols['domain'] = $domain;
        }
        if (self::columnExists('pmwh3_web_subdomains', 'path')) {
            $cols['path'] = $path;
        }
        if (self::columnExists('pmwh3_web_subdomains', 'ip')) {
            $cols['ip'] = $ip;
        }
        $db->insert('pmwh3_web_subdomains', $cols);
    }

    private static function columnExists(string $table, string $column): bool
    {
        try {
            $r = Config::moduleDb()->selectOne(
                    "SELECT 1 FROM information_schema.columns
                      WHERE table_schema = DATABASE()
                        AND table_name = :t
                        AND column_name = :c
                      LIMIT 1",
                    ['t' => $table, 'c' => $column]
            );
            return !empty($r);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function getNameservers(): array
    {
        $raw = (string) LazyConfig::get('DNS_SERVERS', '');
        if ($raw === '') {
            return [];
        }
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $out = [];
        foreach ($lines as $l) {
            $l = trim($l);
            if ($l !== '') {
                $out[] = $l;
            }
        }
        return $out;
    }

    private static function getPrimaryMx(): string
    {
        $raw = (string) LazyConfig::get('MX_SERVERS', '');
        if ($raw === '') {
            return '';
        }
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        foreach ($lines as $l) {
            $l = trim($l);
            if ($l !== '') {
                return $l;
            }
        }
        return '';
    }
}
