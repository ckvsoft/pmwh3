<?php

namespace pmwh3\Utils\Web;

use ckvsoft\mvc\Config;
use pmwh3\Config\LazyConfig;

/**
 * Apache backend adapter.
 *
 * Reads/writes the `pmwh3_web_subdomains` table in the module's own DB
 * (Config::moduleDb() -- pmwh3 DB is the store; the live Apache picks
 * the rows up via its mod_perl SQL-include, see the server's
 * vhost.conf: SELECT * ... WHERE data <> '' -> add_config per row).
 *
 * Vhost rendering: skeleton (VirtualHost / ServerName / DocumentRoot)
 * + the row's custom section between START/END markers. Skeleton
 * regeneration keeps the custom section verbatim. Legacy columns
 * (fpext, traffic) are left untouched.
 */
class ApacheAdapter implements WebAdapterInterface
{

    public static function getKey(): string  { return 'apache'; }
    public static function getName(): string { return 'Apache'; }

    public static function isAvailable(): bool
    {
        try {
            $db = self::db();
            if (!$db->tableExists('pmwh3_web_subdomains')) {
                return false;
            }
            self::dropFpextLegacyColumn();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function capabilities(): array
    {
        return ['subdomain-write', 'vhost-data', 'subdomain-list', 'ssl'];
    }

    // ===== db ===========================================================

    private static function db(): \ckvsoft\Database
    {
        return Config::moduleDb();
    }

    /** once-per-process guard for the fpext column cleanup */
    private static ?bool $fpextChecked = null;

    /**
     * FrontPage is dead: drop the pmwh2-legacy fpext column from
     * pmwh3_web_subdomains when we first touch the table. The mod_perl
     * vhost reader only consumes subdomain/customer/path/data
     * (this adapter's render), so the
     * column is pure cruft. Guarded via information_schema (portable
     * across MariaDB/MySQL -- MySQL has no DROP COLUMN IF EXISTS),
     * goes through the framework's execDdl, and degrades silently on
     * any failure.
     */
    private static function dropFpextLegacyColumn(): void
    {
        if (self::$fpextChecked !== null) {
            return;
        }
        self::$fpextChecked = true;
        try {
            $col = Config::moduleDb()->selectOne(
                    "SELECT 1 FROM information_schema.columns
                      WHERE table_schema = DATABASE()
                        AND table_name = 'pmwh3_web_subdomains'
                        AND column_name = 'fpext'"
            );
            if (!empty($col)) {
                Config::moduleDb()->execDdl(
                        'ALTER TABLE `pmwh3_web_subdomains` DROP COLUMN `fpext`'
                );
                \pmwh3\Utils\ErrorHandler::trace('ApacheAdapter: legacy fpext column dropped from pmwh3_web_subdomains');
            }
        } catch (\Throwable $e) {
            \pmwh3\Utils\ErrorHandler::trace('ApacheAdapter: fpext cleanup skipped: ' . $e->getMessage());
        }
    }

    /** Normalize an FQDN (lowercase, trim). */
    private static function normalize(string $fqdn): string
    {
        return strtolower(trim($fqdn, ". \t\n\r\0\x0B"));
    }

    // ===== Reads ========================================================

    public static function rowExists(string $fqdn): bool
    {
        return self::getRow($fqdn) !== null;
    }

    public static function getRow(string $fqdn): ?array
    {
        $row = self::db()->selectOne(
                "SELECT subdomain, domain, customer, path, mode, ip,
                        alias_of, ssl_cert, custom, adapter, data
                   FROM pmwh3_web_subdomains
                  WHERE subdomain = :s",
                ['s' => self::normalize($fqdn)]
        );
        return $row ?: null;
    }

    public static function listByDomain(string $domain): array
    {
        $domain = self::normalize($domain);
        return self::db()->select(
                "SELECT subdomain, domain, customer, path, mode, ip,
                        alias_of, ssl_cert, custom, adapter, data
                   FROM pmwh3_web_subdomains
                  WHERE subdomain = :exact
                     OR subdomain LIKE :pattern
                     OR alias_of  = :exact
                     OR alias_of LIKE :pattern
               ORDER BY (subdomain = :exact) DESC, subdomain",
                ['exact' => $domain, 'pattern' => "%.{$domain}"]
        );
    }

    // ===== Writes =======================================================

    public static function createSubdomain(array $row): bool
    {
        $row['subdomain'] = self::normalize((string) ($row['subdomain'] ?? ''));
        if ($row['subdomain'] === '') {
            return false;
        }
        if (isset($row['data']) && $row['data'] === '') {
            unset($row['data']);
        }
        if (isset($row['alias_of']) && trim((string) $row['alias_of']) === '') {
            unset($row['alias_of']);
        }
        self::db()->insert('pmwh3_web_subdomains', $row);
        return self::rowExists($row['subdomain']);
    }

    public static function updateSubdomain(string $oldFqdn, array $fields): bool
    {
        $oldFqdn = self::normalize($oldFqdn);
        if ($oldFqdn === '' || !self::rowExists($oldFqdn)) {
            return false;
        }
        $allowed = ['subdomain', 'path', 'data', 'alias_of', 'mode', 'ip', 'ssl_cert', 'custom', 'adapter'];
        $update = [];
        foreach ($allowed as $col) {
            if (!array_key_exists($col, $fields)) {
                continue;
            }
            $val = $fields[$col];
            if ($col === 'subdomain') {
                $val = self::normalize((string) $val);
            }
            if ($col === 'alias_of' && trim((string) $val) === '') {
                $val = null;
            }
            $update[$col] = $val;
        }
        if (empty($update)) {
            return true;
        }
        self::db()->update('pmwh3_web_subdomains', $update,
                'subdomain = :s', ['s' => $oldFqdn]);
        return true;
    }

    public static function deleteSubdomain(string $fqdn): bool
    {
        $fqdn = self::normalize($fqdn);
        if ($fqdn === '') {
            return false;
        }
        self::db()->delete('pmwh3_web_subdomains', 'subdomain = :s', ['s' => $fqdn]);
        return !self::rowExists($fqdn);
    }

    // ===== vhost data ===================================================

    public static function buildVhostData(array $args): string
    {
        $fqdn     = self::normalize((string) ($args['subdomain'] ?? ''));
        $domain   = self::normalize((string) ($args['domain'] ?? ''));
        $customer = (string) ($args['customer'] ?? '');
        $path     = (string) ($args['path'] ?? '');
        $aliasOf  = isset($args['alias_of'])
                ? self::normalize((string) $args['alias_of']) : '';
        $custom   = (string) ($args['custom'] ?? '');
        $sslCert  = trim((string) ($args['ssl_cert'] ?? ''));

        if ($fqdn === '' || $path === '') {
            return '';
        }
        if ($custom === '') {
            $custom = trim((string) LazyConfig::get('APACHECONFIG', ''));
        }
        // Resolve the documented placeholders inside the snippet.
        $custom = str_replace(
                ['[DOMAIN]', '[SUBDOMAIN]', '[DOCROOT]', '[CUSTOMER]'],
                [$domain, $fqdn, rtrim((string) LazyConfig::get('WEBROOT', '/vhome'), '/'), $customer],
                $custom
        );

        $block = function (string $port, bool $tls) use ($fqdn, $domain, $path, $custom, $sslCert): string {
            $out  = "<VirtualHost {$port}>\n";
            $out .= "    ServerName {$fqdn}\n";
            if ($domain !== '' && $fqdn === 'www.' . $domain) {
                $out .= "    ServerAlias {$domain}\n";
            }
            $out .= "    DocumentRoot {$path}\n";
            $indexFile = trim((string) LazyConfig::get('CREATE_INDEXFILE', ''));
            if ($indexFile !== '') {
                $out .= "    DirectoryIndex " . $indexFile . "\n";
            }
            if ($tls) {
                $dir = rtrim((string) LazyConfig::get('WEB_SSL_DIR', '/vhome/ssl'), '/');
                $out .= "    SSLEngine on\n";
                $out .= "    SSLCertificateFile {$dir}/{$sslCert}.pem\n";
                $out .= "    SSLCertificateKeyFile {$dir}/{$sslCert}.key\n";
            }
            $out .= "    ### START CUSTOM ###\n";
            $out .= (trim($custom) !== '' ? $custom . "\n" : '');
            $out .= "    ### END CUSTOM ###\n";
            $out .= "</VirtualHost>\n";
            return $out;
        };

        $httpPort = (string) LazyConfig::get('WEB_VHOST_IP_PORT', '*:80');

        // Live-server convention: the TLS vhost first (primary), the
        // plain-http counterpart after it in the same `data` block.
        if ($sslCert !== '' && in_array('ssl', self::capabilities(), true)) {
            return $block('*:443', true) . "\n" . $block($httpPort, false);
        }
        return $block($httpPort, false);
    }

    // ===== certificates =================================================

    public static function listCerts(): array
    {
        if (!in_array('ssl', self::capabilities(), true)) {
            return [];
        }
        $dir = trim((string) LazyConfig::get('WEB_SSL_DIR', '/vhome/ssl'));
        if ($dir === '' || !is_dir($dir)) {
            return [];
        }
        $certs = [];
        foreach (glob($dir . '/*.pem') ?: [] as $pem) {
            $base = basename($pem, '.pem');
            if (is_file($dir . '/' . $base . '.key')) {
                $certs[$base] = $base;
            }
        }
        ksort($certs);
        return $certs;
    }

    public static function resolveCert(string $fqdn): ?string
    {
        if (!in_array('ssl', self::capabilities(), true)) {
            return null;
        }
        $known = self::listCerts();
        if (empty($known)) {
            return null;
        }
        $fqdn = self::normalize($fqdn);
        // exact match, then a single wildcard mask (_.b.c.t covers
        // a.b.c.t -- deeper wildcard chains are out of scope)
        if (isset($known[$fqdn])) {
            return $fqdn;
        }
        $parts = explode('.', $fqdn);
        if (count($parts) > 2) {
            $masked = '_.' . implode('.', array_slice($parts, 1));
            if (isset($known[$masked])) {
                return $masked;
            }
        }
        return null;
    }

    public static function extractCustom(string $data): string
    {
        $begin = '### START CUSTOM ###';
        $end   = '### END CUSTOM ###';
        $b = strpos($data, $begin);
        if ($b === false) {
            return '';
        }
        $b += strlen($begin);
        $e = strpos($data, $end, $b);
        if ($e === false) {
            return trim(substr($data, $b));
        }
        return trim(substr($data, $b, $e - $b));
    }

    public static function regenerateVhostData(string $fqdn): bool
    {
        $fqdn = self::normalize($fqdn);
        $row = self::getRow($fqdn);
        if (!$row || !in_array('vhost-data', self::capabilities(), true)) {
            return false;
        }
        $data = (string) ($row['data'] ?? '');
        $custom = self::extractCustom($data);
        // Preserve an existing SSL certificate binding across
        // regenerations (rendered skeleton only refreshes otherwise).
        $sslCert = '';
        if (preg_match('/SSLCertificateFile\s+[^\s]*\/([^\/\s]+)\.pem/', $data, $matches)) {
            $sslCert = (string) $matches[1];
        }
        $new = self::buildVhostData([
            'subdomain' => $row['subdomain'],
            'domain'    => $row['domain'],
            'customer'  => $row['customer'],
            'path'      => $row['path'],
            'alias_of'  => $row['alias_of'],
            'custom'    => $custom,
            'ssl_cert'  => $sslCert,
        ]);
        if ($new === '') {
            return false;
        }
        return self::updateSubdomain($fqdn, ['data' => $new]);
    }
}
