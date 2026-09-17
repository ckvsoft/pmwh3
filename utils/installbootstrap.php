<?php

namespace pmwh3\Utils;

use ckvsoft\mvc\Config;
use ckvsoft\CkvException;

/**
 * pmwh3 first-install bootstrap ("installer engine").
 *
 * Everything a copied module needs before it works:
 *   1. module.json written from the install form (main DB + optional
 *      separate DNS DB -- replaces the placeholder config that ships
 *      in the repo).
 *   2. Baseline-only schema via the framework Updater fresh-path
 *      (0.0.0_baseline.sql = current schema state; legacy chain is
 *      skipped + stamped).
 *   3. RBAC roles: parent 'pmwh3' + children (Ultimate Admin /
 *      Reseller / Customer), pmwh3.* permission keys, role_perms:
 *      Ultimate Admin gets every pmwh3 permission.
 *   4. The first customer row: 'admin' (Ultimate Admin role,
 *      unlimited quota counters).
 *
 * Usable from the web (controller/install.php) AND from the CLI
 * script (scripts/bootstrap_rbac.php shares the permission-key
 * list). All steps are idempotent-safe to re-run.
 */
class InstallBootstrap
{

    public const PARENT_ROLE = 'pmwh3';
    public const ADMIN_ROLE  = 'Ultimate Admin';
    public const ADMIN_NAME  = 'admin';

    /** @var string[]|null cached permission-key list */
    private static ?array $permKeys = null;

    // ----------------------------------------------------------------
    // State checks
    // ----------------------------------------------------------------

    /**
     * True when an install must run: module.json still holds the
     * placeholder example values (repo version) OR the pmwh3_menu
     * table is missing (baseline not played for this module DB).
     * See installBlocker() for the reason (empty = installable ok).
     */
    public static function needsInstall(): bool
    {
        return self::installBlocker() !== '';
    }

    /** Human-readable "why" for needsInstall(); '' when configured. */
    public static function installBlocker(): string
    {
        try {
            $module = self::moduleJson();
            $db = (array) ($module['database'] ?? []);
            if (($db['name'] ?? '') === '' || $db['name'] === 'DB_NAME'
                    || ($db['pass'] ?? '') === '' || ($db['pass'] ?? '') === 'DB_PASS') {
                return 'module.json placeholder values';
            }
            $mod = Config::moduleDb();
            if (!$mod->tableExists('pmwh3_menu')) {
                return 'pmwh3_menu missing (baseline not played)';
            }
            return '';
        } catch (\Throwable $e) {
            return 'probe failed: ' . $e->getMessage();
        }
    }

    // ----------------------------------------------------------------
    // Installer security token (same mechanism as the cevian core
    // installer: a random "pmwh3_install_<hex>.txt" must appear in the
    // Cevian root BEFORE the wizard opens -- proof that the operator
    // really has server access, not just the URL).
    // ----------------------------------------------------------------

    private static function stateFile(): string
    {
        // Cevian-root var/ (php-fpm writable there -- fdpm error logs
        // live in the same folder). The module directory is NOT
        // php-writable on operator-managed deployments (the token file
        // exists precisely because the OPERATOR has access, PHP may
        // not) -- storing the state there regenerated the token name
        // every request and the freshly created token file "never
        // existed". Form memory + token state therefore both live in
        // the cevian root; write failures are logged clearly and the
        // token itself can be re-created from the displayed name.
        return rtrim(getcwd(), '/') . '/var/pmwh3_install_state.json';
    }

    private static function installState(): array
    {
        $stateFile = self::stateFile();
        $state = [];
        if (is_file($stateFile)) {
            $state = json_decode((string) file_get_contents($stateFile), true);
            $state = is_array($state) ? $state : [];
        }
        $changed = false;
        if (empty($state['token_name'])) {
            $state['token_name'] = 'pmwh3_install_' . bin2hex(random_bytes(8)) . '.txt';
            $changed = true;
        }
        if (empty($state['token_code'])) {
            $state['token_code'] = 'pmwh3-' . bin2hex(random_bytes(12));
            $changed = true;
        }
        if ($changed) {
            self::writeState($stateFile, $state);
        }
        return $state;
    }

    private static function writeState(string $stateFile, array $state): void
    {
        @mkdir(dirname($stateFile), 0775, true);
        if (@file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT)) === false) {
            error_log('pmwh3 installer: cannot write pmwh3_install_state.json ('
                    . $stateFile . ') -- form memory disabled (permissions?)');
        }
    }

    /** Token file name (stable in var/pmwh3_install_state.json). */
    public static function securityTokenName(): string
    {
        return (string) self::installState()['token_name'];
    }

    /** Exact content the file must contain (server-side generated). */
    public static function securityTokenCode(): string
    {
        return (string) self::installState()['token_code'];
    }

    /**
     * Absolute path of the token file the operator has to create --
     * inside the PMWH3 MODULE directory (next to module.json), NOT
     * the cevian root: the operator must have write access to the
     * module folder itself, which is what a legitimate deploy needs.
     */
    public static function securityTokenPath(): string
    {
        return __DIR__ . '/../' . self::securityTokenName();
    }

    /**
     * True when the token file EXISTS in the Cevian root -- EXACTLY
     * like the cevian core installer's step 1 (create the displayed
     * empty file; the "Check again" button verifies the presence).
     * A random 16-byte hex file name can only be created with real
     * server file system access.
     */
    public static function securityTokenOk(): bool
    {
        return is_file(self::securityTokenPath());
    }

    /** Drop state + token (after a successful install). */
    public static function clearSecurityToken(): void
    {
        @unlink(self::securityTokenPath());
        @unlink(self::stateFile());
        // cleanup of the wrongly located copy (regression window only)
        @unlink(__DIR__ . '/../var/pmwh3_install_state.json');
    }

    /**
     * Non-SECRET form memory (host/name/user + dns nodes), state file
     * based. Secrets (db_pass/dns_pass/admin_password) are NEVER
     * stored. On an empty post the previous memory is NOT wiped, so a
     * validation failure can never blank all fields.
     */
    public static function formRemember(?array $in = null): array
    {
        $stateFile = self::stateFile();
        $state = self::installState();
        $fields = ['db_host', 'db_name', 'db_user', 'dns_host', 'dns_name', 'dns_user', 'dns_same'];
        if (is_array($in)) {
            $filled = false;
            foreach ($fields as $f) {
                $v = trim((string) ($in[$f] ?? ''));
                $state['form'][$f] = (string) ($in[$f] ?? '');
                if ($v !== '') {
                    $filled = true;
                }
            }
            if ($filled) {
                self::writeState($stateFile, $state);
            }
        }
        return (array) ($state['form'] ?? []);
    }

    /** Read modules/pmwh3/module.json as an array ([] when missing). */
    private static function moduleJson(): array
    {
        $path = self::findModuleJsonPath();
        if ($path === null) {
            return [];
        }
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    /** Paths of the module.json candidates (relative to cwd). */
    private static function moduleJsonCandidates(): array
    {
        $out = [];
        foreach ([trim(\MODULES_URI, '/') . '/', trim(\CORE_MODULES_URI, '/') . '/'] as $base) {
            $out[] = rtrim(getcwd(), '/') . '/' . $base . 'pmwh3/module.json';
        }
        return $out;
    }

    private static function findModuleJsonPath(): ?string
    {
        // primary: module-relative (works in web AND CLI, the module
        // json lives next to this file: modules/pmwh3/module.json)
        $self = __DIR__ . '/../module.json';
        if (is_file($self)) {
            return realpath($self);
        }
        foreach (self::moduleJsonCandidates() as $p) {
            if (is_file($p)) {
                return $p;
            }
        }
        return null;
    }

    // ----------------------------------------------------------------
    // Run the whole bootstrap
    // ----------------------------------------------------------------

    /**
     * @param array $in installer input: db_host/db_name/db_user/db_pass,
     *   dns_same (+dns_* when separate), admin_password.
     * @return array ['ok' => bool, 'steps' => [label => detail]]
     */
    /**
     * PHASE 1: only write module.json from the form input. The DB
     * bootstrap intentionally happens in a SEPARATE request (phase 2)
     * -- the module DB caches are per-request and would otherwise
     * still hold the half-initialized placeholder connection.
     * @return array ['ok' => bool, 'steps' => [label => detail]]
     */
    public static function runPhase1(array $in): array
    {
        $steps = [];
        self::assertInput($in, 'config');

        try {
            $n = self::writeModuleJson($in);
            $steps['module.json'] = 'ok (' . $n . ' config nodes)';
        } catch (\Throwable $e) {
            $steps['module.json'] = 'FAIL: ' . $e->getMessage();
            return ['ok' => false, 'steps' => $steps];
        }
        // (form-remember happens controller-side, session only)
        return ['ok' => true, 'steps' => $steps];
    }

    /**
     * PHASE 2: fresh request; module.json real by now. Baseline +
     * RBAC + admin customer. */
    public static function runPhase2(array $in): array
    {
        $steps = [];
        self::assertInput($in, 'bootstrap');

        // 0. system prerequisites (Cevian version, PHP extensions, dirs)
        $checks = self::systemChecks($in);
        if (!$checks['ok']) {
            foreach ($checks['rows'] as $row) {
                if (!$row['ok']) {
                    $steps['requirement: ' . $row['label']]
                            = 'FAIL: ' . $row['detail'];
                }
            }
            return ['ok' => false, 'steps' => $steps];
        }

        // 2. schema (updater fresh-path: baseline only)
        try {
            $upd = new \ckvsoft\Update\Updater('pmwh3');
            if ($upd->needsUpdate()) {
                $upd->runUpdate();
            }
            $steps['schema (baseline)'] = 'ok';
        } catch (\Throwable $e) {
            $steps['schema (baseline)'] = 'FAIL: ' . $e->getMessage();
            return ['ok' => false, 'steps' => $steps];
        }
        try {
            $mod = Config::moduleDb();
            if (!$mod->tableExists('pmwh3_menu')) {
                $steps['schema (baseline)'] = 'FAIL: pmwh3_news missing after updater';
                return ['ok' => false, 'steps' => $steps];
            }
        } catch (\Throwable $e) {
            $steps['schema (baseline)'] = 'FAIL: ' . $e->getMessage();
            return ['ok' => false, 'steps' => $steps];
        }

        // 3. RBAC roles (idempotent)
        try {
            self::createRoles();
            $steps['roles'] = 'ok';
        } catch (\Throwable $e) {
            $steps['roles'] = 'FAIL: ' . $e->getMessage();
            return ['ok' => false, 'steps' => $steps];
        }

        // 4. permissions + Ultimate Admin grants
        try {
            $created = Acl::ensurePermissions(self::permissionKeys());
            self::grantAllToAdminRole();
            $steps['permissions'] = 'ok (' . $created . ' new keys)';
        } catch (\Throwable $e) {
            $steps['permissions'] = 'FAIL: ' . $e->getMessage();
            return ['ok' => false, 'steps' => $steps];
        }

        // 5. ultimate admin customer + unlimited counters
        try {
            self::createAdminCustomer($in);
            $steps['admin customer'] = 'ok';
        } catch (\Throwable $e) {
            $steps['admin customer'] = 'FAIL: ' . $e->getMessage();
            return ['ok' => false, 'steps' => $steps];
        }

        // 6. the installer is done -- drop the updater's fresh flag
        //    and the security token state (cleanup)
        $varDir = rtrim(getcwd(), '/') . '/var';
        if (is_file($varDir . '/pmwh3_freshly_installed.flag')) {
            @unlink($varDir . '/pmwh3_freshly_installed.flag');
        }
        self::clearSecurityToken();

        return ['ok' => true, 'steps' => $steps];
    }

    // ----------------------------------------------------------------
    // System prerequisites (checked by the installer, not separately)
    // ----------------------------------------------------------------

    /**
     * Pre-install requirement checks -- surfaced in the wizard and
     * enforced by run() as step 0. $in (when provided from the POST
     * form) additionally performs a live DB-connect probe with those
     * credentials.
     *
     * @return array{ok:bool, rows:list<array{label:string,ok:bool,detail:string}>}
     */
    public static function systemChecks(?array $in = null): array
    {
        $rows = [];

        // Cevian framework version (>= 0.18.3: moduleDb node API +
        // baseline-only fresh path + Database DDL helpers). The
        // library Version class is the RUNTIME truth (the update.json
        // stamp may lag behind the shipped library on some installs).
        $fwVersion = '';
        try {
            $fwVersion = (string) explode(' ',
                    \ckvsoft\Version::version())[0]; // strip git-suffix
        } catch (\Throwable $e) {
            // fall through to the update.json stamp as a hint
        }
        if ($fwVersion === '') {
            $updateJson = rtrim(getcwd(), '/') . '/var/update.json';
            if (is_file($updateJson)) {
                $cfg = json_decode((string) file_get_contents($updateJson), true);
                $fwVersion = (string) ($cfg['framework_updated_version'] ?? '0.0.0');
            }
        }
        // numeric part only for the compare (e.g. "0.18.3-260913")
        $fwNum = preg_replace('/^(\d+(?:\.\d+)*).*$/', '$1', $fwVersion);
        $fwOk  = version_compare($fwNum ?: '0.0.0', '0.18.3', '>=');
        $rows[] = [
            'label'  => 'Cevian >= 0.18.3',
            'ok'     => $fwOk,
            'detail' => $fwOk
                    ? 'version found: ' . ($fwVersion !== '' ? $fwVersion : '?')
                    : 'version found: ' . ($fwVersion !== '' ?: '?') . ' (too old)',
        ];
        if ($fwVersion === '0.0.0' && $fwOk === false) {
            // Fresh framework install without a stamped version: probe
            // the library directly for the 0.18.x APIs (moduleDb +
            // execDdl). Only 0.18.3+ ships them.
            $probeFile = __DIR__ . '/../../../library/ckvsoft/database.php';
            if (is_file($probeFile)) {
                $probe = (string) @file_get_contents($probeFile)
                    . (string) @file_get_contents(
                        __DIR__ . '/../../../library/ckvsoft/mvc/config.php');
                $hasApi = str_contains($probe, 'function moduleDb(')
                        && str_contains($probe, 'function execDdl(');
                $rows[count($rows) - 1]['ok'] = $hasApi;
                $rows[count($rows) - 1]['detail'] = $hasApi
                    ? 'update.json missing, API probe OK (moduleDb + execDdl found)'
                    : 'framework too old: moduleDb/execDdl missing in library/ckvsoft/mvc/Database.php';
            }
        }

        // PHP extensions
        foreach (['pdo', 'pdo_mysql', 'mbstring', 'gettext', 'intl'] as $i => $ext) {
            $required = $i < 3;
            $loaded = extension_loaded($ext);
            $rows[] = [
                'label'  => 'PHP extension ' . $ext . ($required ? ' (required)' : ' (optional)'),
                'ok'     => $loaded || !$required,
                'detail' => $loaded ? 'loaded' : ($required ? 'MISSING' : 'not loaded (optional)'),
            ];
        }

        // Directory writability (framework var relative to cwd)
        $varDir = rtrim(getcwd(), '/') . '/var';
        if (!is_dir($varDir)) {
            @mkdir($varDir, 0775, true);
        }
        $rows[] = [
            'label'  => 'var/ writable',
            'ok'     => is_dir($varDir) && is_writable($varDir),
            // pre-login page: never leak absolute server paths
            'detail' => is_dir($varDir)
                    ? (is_writable($varDir) ? 'writable' : 'not writable')
                    : 'missing (create failed)',
        ];

        // DB-connect probe (when installer form data available)
        if (is_array($in) && !empty($in['db_host'])) {
            $dsn = 'mysql:host=' . (string) $in['db_host'] . ';port='
                    . ((int) ($in['db_port'] ?? 3306));
            try {
                $pdo = new \PDO($dsn, (string) $in['db_user'],
                        (string) $in['db_pass'], [\PDO::ATTR_TIMEOUT => 5]);
                $rows[] = ['label' => 'Database connect', 'ok' => true,
                    'detail' => (string) $in['db_host']];
            } catch (Throwable $e) {
                $rows[] = ['label' => 'Database connect',
                    'ok'    => false,
                    // pre-login page: generic detail, no server/host info
                    'detail' => 'connect failed',
                ];
                \pmwh3\Utils\ErrorHandler::trace(
                        '[InstallBootstrap.systemChecks] DB probe failed: '
                        . $e->getMessage());
            }
        }

        $ok = true;
        foreach ($rows as $row) {
            if (!$row['ok']) {
                $ok = false;
                break;
            }
        }
        return ['ok' => $ok, 'rows' => $rows];
    }

    private static function assertInput(array $in, string $phase = 'config'): void
    {
        $required = ['db_host', 'db_name', 'db_user', 'db_pass'];
        if ($phase === 'bootstrap') {
            $required = ['admin_password'];
        }
        foreach ($required as $f) {
            if (trim((string) ($in[$f] ?? '')) === '') {
                throw new CkvException(__('Missing fields') . " ({$f})");
            }
        }
        if ($phase === 'config' && empty($in['dns_same'])) {
            foreach (['dns_host', 'dns_name', 'dns_user', 'dns_pass'] as $f) {
                if (trim((string) ($in[$f] ?? '')) === '') {
                    throw new CkvException(__('Missing fields') . " ({$f})");
                }
            }
        }
        if (trim((string) ($in['admin_password'] ?? '')) === '') {
            throw new CkvException('Admin password required');
        }
    }

    private static function writeModuleJson(array $in): int
    {
        $path = self::findModuleJsonPath();
        if ($path === null) {
            // Fresh deployment where module.json is intentionally NOT
            // shipped (repo ships module.json.example): write it new
            // in the module directory itself.
            $path = __DIR__ . '/../module.json';
        }
        if (!is_writable(dirname($path))) {
            throw new \RuntimeException(
                'module directory not writable by PHP ('
                . basename(dirname($path))
                . '/): fix the filesystem permissions (e.g. chown to the php-fpm user) so the installer can write module.json'
            );
        }
        $mainDb = [
            'type' => 'mysql',
            'host' => (string) $in['db_host'],
            'name' => (string) $in['db_name'],
            'user' => (string) $in['db_user'],
            'pass' => (string) $in['db_pass'],
        ];
        if (!empty($in['db_port']) && (int) $in['db_port'] !== 3306) {
            $mainDb['port'] = (int) $in['db_port'];
        }
        $data = [
            'name' => 'pmwh3',
            'version' => '3.0.82',
            'description' => 'PhpMyWebHosting',
            'core' => false,
            'database' => $mainDb,
            'dns' => [
                'table_prefix' => '',
                // "same connection" = module DB host/user/pass, but the
                // DNS database NAME is still the operator's choice (an
                // own pdns database on the same server); empty name
                // falls back to the module DB name.
                'database' => !empty($in['dns_same'])
                    ? array_merge($mainDb, [
                        'name' => trim((string) ($in['dns_name'] ?? '')) !== ''
                            ? (string) $in['dns_name'] : $mainDb['name'],
                    ])
                    : [
                        'type' => 'mysql',
                        'host' => (string) $in['dns_host'],
                        'name' => (string) $in['dns_name'],
                        'user' => (string) $in['dns_user'],
                        'pass' => (string) $in['dns_pass'],
                    ],
            ],
        ];
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (@file_put_contents($path, $json . "\n") === false) {
            throw new \RuntimeException(
                    "cannot write {$path} (permissions?) -- make modules/pmwh3 writable for the web user and retry");
        }
        return 2;
    }

    // ----------------------------------------------------------------
    // RBAC pieces
    // ----------------------------------------------------------------

    /**
     * Create the pmwh3 role tree if missing: root role 'pmwh3' plus
     * Ultimate Admin / Reseller / Customer children (nested set via
     * ACL::addRole). Idempotent by name-probing.
     */
    private static function createRoles(): void
    {
        $acl = new \ckvsoft\ACL();

        $rootId = self::roleIdByName(self::PARENT_ROLE);
        if ($rootId === null) {
            $rootId = (int) $acl->addRole(self::PARENT_ROLE, null, self::PARENT_ROLE);
        }
        foreach ([self::ADMIN_ROLE, 'Reseller', 'Customer'] as $child) {
            if (self::roleIdByName($child) === null) {
                $acl->addRole($child, $rootId, self::PARENT_ROLE);
            }
        }
    }

    private static function roleIdByName(string $name): ?int
    {
        $row = Config::db()->selectOne(
                "SELECT id FROM roles WHERE roleName = :n AND module = 'pmwh3' LIMIT 1",
                ['n' => $name]);
        return $row ? (int) $row['id'] : null;
    }

    /**
     * Grant every pmwh3.* permission to the Ultimate Admin role.
     * Duplicate (roleID, permID) pairs are tolerated as no-ops.
     */
    private static function grantAllToAdminRole(): void
    {
        $frame = Config::db(); // roles/permissions live in the framework DB
        $role = $frame->selectOne(
                "SELECT id FROM roles WHERE roleName = :n AND module = 'pmwh3' LIMIT 1",
                ['n' => self::ADMIN_ROLE]);
        if (!$role) {
            throw new \RuntimeException("role '" . self::ADMIN_ROLE . "' not found");
        }
        $roleId = (int) $role['id'];
        $perms = $frame->select("SELECT id FROM permissions WHERE module = 'pmwh3'", []);
        $granted = 0;
        foreach ($perms as $p) {
            try {
                $frame->insert('role_perms', [
                        'roleID' => $roleId,
                        'permID' => (int) $p['id'],
                        'value'  => 1,
                ]);
                $granted++;
            } catch (\Throwable $e) {
                // duplicate key => already granted, fine
            }
        }
    }

    /**
     * The first customer: 'admin' with the Ultimate Admin role and
     * unlimited counters (max = -1 for every resource).
     */
    private static function createAdminCustomer(array $in): void
    {
        $mod = Config::moduleDb();
        $role = $mod->selectOne(
                "SELECT id FROM roles WHERE roleName = :n AND module = 'pmwh3' LIMIT 1",
                ['n' => self::ADMIN_ROLE]);
        $roleId = (int) ($role['id'] ?? 0);
        if ($roleId <= 0) {
            throw new \RuntimeException('Ultimate Admin role not found (roles step failed)');
        }

        // CustomerManager::insert(fields, limits, password, creatorCid)
        // -- $limits is the flat [resource => max] map; seedCountings
        // adds used/granted(0) rows itself.
        $limitMap = [];
        foreach (\pmwh3\Utils\CountingUtil::getResources() ?: [] as $res) {
            $limitMap[$res] = -1; // unlimited for the ultimate admin
        }
        CustomerManager::insert(
                [
                    'customer' => self::ADMIN_NAME,
                    'realname' => 'Ultimate Admin',
                    'role_id'  => $roleId,
                    'email'    => '',
                ],
                $limitMap,
                (string) $in['admin_password'],
                0);
    }

    // ----------------------------------------------------------------
    // Permission key list (shared with scripts/bootstrap_rbac.php)
    // ----------------------------------------------------------------

    /**
     * Bare permission keys (no prefix; the Acl wrapper adds the
     * 'pmwh3.' prefix on insert). Mirrors the live RBAC state.
     */
    public static function permissionKeys(): array
    {
        if (self::$permKeys !== null) {
            return self::$permKeys;
        }
        self::$permKeys = [
            'view_permissions', 'change_permissions',

            'view_customers', 'view_customer_limits',
            'create_customer', 'edit_customer', 'delete_customer',

            'view_groups', 'create_group', 'edit_group', 'delete_group',

            'view_domains', 'view_domain_advanced',
            'create_domain', 'edit_domain', 'edit_domain_advanced', 'delete_domain',
            'view_subdomain', 'create_subdomain', 'edit_subdomain', 'delete_subdomain',
            'view_subdomain_alias', 'create_subdomain_alias',
            'edit_subdomain_alias', 'delete_subdomain_alias',

            'view_email',
            'create_email', 'edit_email', 'delete_email',
            'create_email_filtering', 'edit_email_filtering', 'delete_email_filtering',
            'create_email_wblist', 'edit_email_wblist', 'delete_email_wblist',

            'view_ftp', 'create_ftp', 'edit_ftp', 'delete_ftp',

            'view_databases', 'create_database', 'edit_database', 'delete_database',

            'view_packages', 'create_package', 'edit_package', 'delete_package',

            'view_news', 'create_news', 'edit_news', 'delete_news',
            'view_applications', 'create_application', 'edit_application', 'delete_application',
            'view_backup', 'create_backup',
            'view_errorlog',
            'view_tools_menu', 'create_menu_row', 'edit_menu_row', 'delete_menu_row',

            'delete_message',

            'view_menu_customers', 'view_menu_customers_overview',
            'view_menu_databases', 'view_menu_databases_overview', 'view_menu_databases_phpmyadmin',
            'view_menu_domains', 'view_menu_domains_overview',
            'view_menu_email', 'view_menu_email_overview',
            'view_menu_email_catchall', 'view_menu_email_email',
            'view_menu_email_filtering', 'view_menu_email_forward',
            'view_menu_email_wblist',
            'view_menu_ftp', 'view_menu_ftp_overview', 'view_menu_ftp_accounts',
            'view_menu_general_overview', 'view_menu_general_password',
            'view_menu_general_sessions', 'view_menu_general_traffic',
            'view_menu_general_messages',
            'view_menu_general',
            'view_menu_contributions',
            'view_menu_options',
            'view_menu_tools', 'view_menu_tools_overview',
            'view_menu_tools_applications', 'view_menu_tools_backup',
            'view_menu_tools_errorlog', 'view_menu_tools_menu',
            'view_menu_tools_news',
        ];
        return self::$permKeys;
    }
}
