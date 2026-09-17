<?php

use ckvsoft\Session;

/**
 * General model for PMWH3 module.
 * Provides system overview, resource, server, security, and group information.
 */
class General_Model extends \ckvsoft\mvc\Model
{

    private int $customer_id;
    private $customer_role_id;

    public function __construct()
    {
        parent::__construct();
        $this->customer_id = (int) Session::getNs('pmwh3', 'customer_id');
        $this->customer_role_id = Session::getNs('pmwh3', 'role_id');
        $this->moduleDb = $this->moduleDb();
    }

    /**
     * Formats bytes into human-readable string.
     */
    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));
        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    /**
     * Returns record count of a table using module DB wrapper.
     */
    private function recordCount(string $table): int
    {
        $result = $this->moduleDb->selectOne("SELECT COUNT(*) AS count FROM {$table}");
        return (int) ($result['count'] ?? 0);
    }

    /**
     * Returns full overview data.
     */
    public function overview(): array
    {
        return [
            'resources' => $this->getResources(),
            'server' => $this->getServerData(),
            'security' => $this->getSecurityData(),
            'groups' => $this->getGroups($this->customer_id),
            'pmwh' => $this->getPmwhData(),
            'applications' => $this->getApplications(),
        ];
    }

    /**
     * Customer Applications tiles (pmwh2 "Customer Applications"
     * widget on the index page). APPS_PER_ROW: tiles per row;
     * OFF/0 disables the widget entirely.
     */
    public function getApplications(): array
    {
        $perRow = strtoupper(trim((string) \pmwh3\Config\LazyConfig::get('APPS_PER_ROW', '2')));
        if ($perRow === 'OFF' || (int) $perRow <= 0) {
            return [];
        }
        $rows = $this->moduleDb()->select(
                "SELECT name, link, sort FROM pmwh3_applications ORDER BY sort, name", []);
        $apps = [];
        foreach ($rows as $r) {
            $name = trim((string) ($r['name'] ?? ''));
            $link = trim((string) ($r['link'] ?? ''));
            if ($name === '' || $link === '') {
                continue;
            }
            $apps[] = ['name' => $name, 'link' => $link];
        }
        if ($apps === []) {
            return [];
        }
        return ['apps' => $apps, 'perRow' => (int) $perRow];
    }

    /**
     * Returns system resources overview.
     */
    public function getResources(): array
    {
        $resources = [];

        $serverName = filter_input(INPUT_SERVER, 'SERVER_NAME', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? 'n/a';
        $resources[] = ["key" => __("Servername"), "value" => $serverName];

        if (\pmwh3\Utils\CustomerUtil::hasAccess("view_customer_count")) {
            $resources[] = ["key" => __("# Customers"), "value" => $this->recordCount("pmwh3_customers")];
        }
        if (\pmwh3\Utils\CustomerUtil::hasAccess("view_domain_count")) {
            $resources[] = ["key" => __("# Domains"), "value" => $this->recordCount("pmwh3_domains")];
        }
        if (\pmwh3\Utils\CustomerUtil::hasAccess("view_subdomain_count")) {
            $resources[] = ["key" => __("# Subdomains"), "value" => $this->recordCount("pmwh3_web_subdomains")];
        }
        if (\pmwh3\Utils\CustomerUtil::hasAccess("view_email_count")) {
            $resources[] = ["key" => __("# Email addresses"), "value" => $this->recordCount("pmwh3_mail_accounts")];
        }
        if (\pmwh3\Utils\CustomerUtil::hasAccess("view_ftp_count")) {
            $resources[] = ["key" => __("# FTP accounts"), "value" => $this->recordCount("pmwh3_ftp_accounts")];
        }
        if (\pmwh3\Utils\CustomerUtil::hasAccess("view_free_space")) {
            $documentRoot = filter_input(INPUT_SERVER, 'DOCUMENT_ROOT', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? '/';
            $resources[] = ["key" => __("Free disk space"), "value" => $this->formatBytes(disk_free_space($documentRoot))];
        }
        if (\pmwh3\Utils\CustomerUtil::hasAccess("view_database_count")) {
            $databases = $this->moduleDb->select("SHOW DATABASES");
            $resources[] = ["key" => __("Count of MySQL databases"), "value" => count($databases) - 1];
        }

        return $resources;
    }

    /**
     * Returns server-related data.
     */
    public function getServerData(): array
    {
        $server = [];

        if (\pmwh3\Utils\CustomerUtil::hasAccess("view_server_variable_apacheversion")) {
            $server[] = ["key" => __("Server software"), "value" => filter_input(INPUT_SERVER, 'SERVER_SOFTWARE', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? 'n/a'];
        }
        if (\pmwh3\Utils\CustomerUtil::hasAccess("view_server_variable_phpversion")) {
            $server[] = ["key" => __("PHP version"), "value" => phpversion()];
        }
        if (\pmwh3\Utils\CustomerUtil::hasAccess("view_server_variable_phplimit")) {
            $server[] = ["key" => __("PHP memory limit"), "value" => ini_get("memory_limit")];
        }
        if (\pmwh3\Utils\CustomerUtil::hasAccess("view_server_variable_mysqlversion")) {
            $version = $this->moduleDb->selectOne("SELECT VERSION() AS version");
            $server[] = ["key" => __("MySQL server version"), "value" => $version['version'] ?? 'n/a'];
        }

        $timezone = date_default_timezone_get();
        $dt = new DateTime("now", new DateTimeZone($timezone));
        $server[] = ["key" => __("Server Time"), "value" => $dt->format("H:i:s")];
        $server[] = ["key" => __("Timezone"), "value" => $timezone];

        return $server;
    }

    /**
     * Returns security-related checks.
     */
    public function getSecurityData(): array
    {
        $security = [];

        if (\pmwh3\Utils\CustomerUtil::hasAccess("view_security_recommends")) {
            $settings = [
                'display_errors' => (bool) ini_get("display_errors"),
                'expose_php' => (bool) ini_get("expose_php"),
                'session.cookie_httponly' => (bool) ini_get("session.cookie_httponly"),
                'session.cookie_secure' => (bool) ini_get("session.cookie_secure"),
            ];

            foreach ($settings as $key => $enabled) {
                $good = match ($key) {
                    'display_errors' => !$enabled,
                    'expose_php' => !$enabled,
                    'session.cookie_httponly' => $enabled,
                    'session.cookie_secure' => $enabled,
                    default => true
                };
                $security[] = ['key' => $key, 'value' => $enabled ? __("On") : __("Off"), 'good' => $good];
            }
        }

        return $security;
    }

    /**
     * Returns all customer-groups visible to the overview page.
     *
     * Reads from the framework `roles` table (module='pmwh3').
     * Previously this filtered by creator to show each reseller
     * only their own sub-groups; framework `roles` doesn't track
     * a creator, so until that's reintroduced as a separate
     * concept this returns every pmwh3 customer-group.
     *
     * @param int $customerId  kept in the signature for backwards
     *                         compatibility, currently unused.
     */
    public function getGroups(int $customerId): array
    {
        $groups = [];
        $rows = \ckvsoft\mvc\Config::db()->select(
                "SELECT id, roleName
                   FROM roles
                  WHERE module = 'pmwh3'
                    AND depth = 1
                  ORDER BY lft"
        );

        foreach ($rows as $row) {
            $countRow = $this->moduleDb->selectOne(
                    "SELECT COUNT(*) AS count FROM pmwh3_customers WHERE role_id = :rid",
                    ['rid' => (int) $row['id']]
            );

            $groups[] = [
                "gid"       => (int) $row['id'],
                "groupname" => (string) $row['roleName'],
                "count"     => (int) ($countRow['count'] ?? 0)
            ];
        }

        return $groups;
    }

    /**
     * Returns PMWH module information.
     */
    public function getPmwhData(): array
    {
        return [
            ["key" => __("PMWH version"), "value" => \pmwh3\Config\Version::getVersion()],
            ["key" => __("PMWH Locale"), "value" => \pmwh3\i18n\Pmwh3I18n::getCurrentLang() . " (" . \pmwh3\Config\LazyConfig::get("DEFAULT_LANGUAGE", "en_US") . ")"],
            ["key" => __("PMWH Encoding"), "value" => \pmwh3\Config\LazyConfig::get("DEFAULT_ENCODING", "UTF-8")],
            ["key" => __("PMWH Locale bound to"), "value" => bindtextdomain('pmwh3', null)],
            ["key" => __("PMWH Webroot"), "value" => \pmwh3\Config\LazyConfig::get("WEBROOT", "/vhome")],
            ["key" => __("Framework"), "value" => \ckvsoft\Version::name()],
            ["key" => __("Framework version"), "value" => \ckvsoft\Version::version()],
        ];
    }

    /**
     * Returns the inbox of the logged-in customer.
     *
     * The pmwh3_messages table joins on customer NAMES (sender,
     * recipient), so we resolve the cid to a name first via
     * CustomerUtil. The view treats msg_read='N' as "new".
     */

    /**
     * Active sessions in pmwh3_activity. A row counts as "active"
     * when it was updated in the last 15 minutes (configurable).
     *
     * Admin (cid=1) sees every session; everyone else only sees
     * their own.
     *
     * Defensive: if pmwh3_activity is missing (older DBs that pre-date
     * the migration, or the activity middleware was never deployed),
     * we surface that to the view rather than throwing a 500.
     */
    public function sessions(): array
    {
        $cutoffMinutes = 15;

        // Check the table exists before querying. Cheap, runs once
        // per page hit, avoids the whole try/catch song-and-dance.
        $exists = $this->moduleDb->select(
                "SHOW TABLES LIKE 'pmwh3_activity'", []
        );
        if (empty($exists)) {
            return [
                'sessions'      => [],
                'cutoff'        => $cutoffMinutes,
                'is_admin'      => $this->customer_id === 1,
                'table_missing' => true,
            ];
        }

        if ($this->customer_id === 1) {
            $rows = $this->moduleDb->select(
                    "SELECT id, cid, customer, session_id, ip, user_agent,
                            last_url, last_module, last_action,
                            created_at, updated_at
                       FROM pmwh3_activity
                      WHERE updated_at >= NOW() - INTERVAL :m MINUTE
                   ORDER BY updated_at DESC",
                    ['m' => $cutoffMinutes]
            );
        } else {
            $rows = $this->moduleDb->select(
                    "SELECT id, cid, customer, session_id, ip, user_agent,
                            last_url, last_module, last_action,
                            created_at, updated_at
                       FROM pmwh3_activity
                      WHERE cid = :c
                        AND updated_at >= NOW() - INTERVAL :m MINUTE
                   ORDER BY updated_at DESC",
                    ['c' => $this->customer_id, 'm' => $cutoffMinutes]
            );
        }
        return [
            'sessions'      => $rows,
            'cutoff'        => $cutoffMinutes,
            'is_admin'      => $this->customer_id === 1,
            'table_missing' => false,
        ];
    }
}
