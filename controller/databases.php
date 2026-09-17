<?php

// modules/pmwh3/controller/databases.php

use ckvsoft\mvc\BaseController;
use ckvsoft\Session;
use pmwh3\Utils\ActivityLog;
use pmwh3\Utils\CustomerUtil;
use pmwh3\Utils\DatabaseManager;

class Databases extends BaseController
{

    public function __construct()
    {
        parent::__construct();
        \pmwh3\Utils\AuthMiddleware::enforceLogin();
    }

    private function render($view, $data = null)
    {
        $pmwh3menuHelper = $this->loadHelper("pmwh3/pmwh3menu");
        $pmwh3menu = $pmwh3menuHelper->getMenu($data['activeBox'] ?? null);

        $this->renderPage([
            ['view' => '/inc/header', 'data' => ['title' => __('Database management')]],
            ['view' => 'pmwh3/inc/navigation', 'data' => ['menu' => $pmwh3menu]],
            ['view' => $view, 'data' => ['data' => $data]],
            ['view' => '/inc/footer'],
                ],
                "<style>" . $this->loadHelper("css", ['method' => 'getCss', 'args' => ['inc/css/pmwh3.css']]) . "</style>",
                "<script>" . $this->loadScript("inc/js/pmwh3.js",
                [
                    'pmwh3ConfirmChanges' => \pmwh3\Config\LazyConfig::get('CONFIRM_CHANGES', 'Y') === 'Y',
                    'pmwh3ConfirmDelete'  => \pmwh3\Config\LazyConfig::get('CONFIRM_DELETE', 'Y') === 'Y',
                ]) . "</script>"
        );
    }

    private function flash(string $kind, string $title, string $msg, string $where = 'overview'): void
    {
        $url = $where === ''
                ? BASE_URI . 'pmwh3/general/overview'
                : BASE_URI . 'pmwh3/databases/' . $where;
        \ckvsoft\Auth::sendFlashRedirect($url, $kind, $title, $msg);
    }

    public function index()
    {
        $this->overview();
    }

    // ===== Overview =====================================================

    public function overview()
    {
        if (!CustomerUtil::hasAccess('view_databases')) {
            $this->flash('error', __('Databases'), __('Permission denied'), '');
            return;
        }
        $cid = (int) Session::getNs('pmwh3', 'customer_id');
        try {
            $databases = DatabaseManager::listAllVisible($cid);
        } catch (\Throwable $e) {
            $this->render('pmwh3/databases/overview', [
                'activeBox'   => 'databases/overview',
                'tree'        => [],
                'create_perm' => false,
                'delete_perm' => false,
                'error'       => $e->getMessage(),
            ]);
            return;
        }

        // Group by customer, fetch users per database. Same shape
        // as pmwh2's overview: [customer => [db => [user, ...]]]
        $tree = [];
        foreach ($databases as $d) {
            $cust = (string) $d['customer'];
            $name = (string) $d['name'];
            if (!isset($tree[$cust])) {
                $tree[$cust] = [];
            }
            try {
                $users = DatabaseManager::listUsersForDatabase($name);
            } catch (\Throwable $e) {
                $users = [];
            }
            $tree[$cust][$name] = $users;
        }
        // Customers without any DBs (so they can be "Owner" in the
        // create form) -- admin sees all, customer only themselves.
        if (CustomerUtil::hasAccess('view_customers')) {
            foreach (\pmwh3\Utils\CustomerManager::listCustomerNames() as $c) {
                if (!isset($tree[$c])) {
                    $tree[$c] = [];
                }
            }
        } else {
            $myName = (string) (CustomerUtil::getCustomerNameById($cid) ?? '');
            if ($myName !== '' && !isset($tree[$myName])) {
                $tree[$myName] = [];
            }
        }
        ksort($tree);

        // Per-customer quota for the 'dbases' resource. Admin sees
        // a row per customer; customers see only their own.
        $quotas = [];
        if (CustomerUtil::hasAccess('view_customers')) {
            foreach (array_keys($tree) as $cust) {
                $ccid = CustomerUtil::getCustomerIdByName($cust);
                if ($ccid > 0) {
                    $quotas[$cust] = \pmwh3\Utils\CountingUtil::getQuota($ccid, 'dbases');
                }
            }
        } else {
            $myName = (string) (CustomerUtil::getCustomerNameById($cid) ?? '');
            if ($myName !== '') {
                $quotas[$myName] = \pmwh3\Utils\CountingUtil::getQuota($cid, 'dbases');
            }
        }

        $this->render('pmwh3/databases/overview', [
            'activeBox'      => 'databases/overview',
            'tree'           => $tree,
            'quotas'         => $quotas,
            'advanced'       => strtoupper((string) \pmwh3\Config\LazyConfig::get('DB_ADVANCED', 'Y')) === 'Y',
            'default_host'   => (string) \pmwh3\Config\LazyConfig::get('DB_DEFAULT_HOST', '%'),
            'create_perm'    => CustomerUtil::hasAccess('create_database'),
            'edit_perm'      => CustomerUtil::hasAccess('edit_database'),
            'delete_perm'    => CustomerUtil::hasAccess('delete_database'),
            'assign_perm'    => CustomerUtil::hasAccess('edit_database'),
        ]);
    }

    // ===== Privilege toggle (single GRANT/REVOKE) =======================

    public function toggle_privilege($database = '', $username = '', $host = '%', $privilege = '')
    {
        if (!CustomerUtil::hasAccess('edit_database')) {
            $this->flash('error', __('Databases'), __('Permission denied'));
            return;
        }
        // Decide direction based on the current state in mysql.db.
        // The view sends just the privilege name; we lookup whether
        // it's currently Y or N and toggle.
        $current = DatabaseManager::listUsersForDatabase($database);
        $isGranted = false;
        foreach ($current as $u) {
            if ($u['username'] === $username && $u['host'] === $host) {
                $isGranted = ($u['privileges'] === 'ALL')
                        || in_array(strtoupper($privilege),
                                explode(',', $u['privileges']), true);
                break;
            }
        }
        try {
            DatabaseManager::setUserPrivilege($database, $username, $host,
                    $privilege, !$isGranted);
        } catch (\Throwable $e) {
            $this->flash('error', __('Databases'), $e->getMessage());
            return;
        }
        ActivityLog::write('database',
                $isGranted ? 'priv_revoke' : 'priv_grant',
                $database,
                "user={$username}@{$host}; priv={$privilege}");
        // Stay on the overview where the toggle was clicked
        $this->flash('success', __('Databases'),
                ($isGranted ? __('Privilege revoked') : __('Privilege granted')));
    }

    // ===== Assign existing user =========================================

    public function assign_user()
    {
        if (!CustomerUtil::hasAccess('edit_database')) {
            $this->flash('error', __('Databases'), __('Permission denied'));
            return;
        }
        $payload = filter_input_array(INPUT_POST, [
            'config' => [
                'filter' => FILTER_DEFAULT,
                'flags'  => FILTER_REQUIRE_ARRAY,
            ],
        ]);
        $config = (array) ($payload['config'] ?? []);

        $database = (string) ($config['database'] ?? '');
        $mode     = (string) ($config['mode']     ?? 'new'); // 'new' or 'existing'
        $host     = trim((string) ($config['host'] ?? '%'));
        if ($host === '') {
            $host = (string) \pmwh3\Config\LazyConfig::get('DB_DEFAULT_HOST', '%');
        }
        $allFlag  = !empty($config['priv_all']);
        $privs    = $allFlag ? ['ALL'] : self::extractPrivileges($config);

        try {
            if ($mode === 'existing') {
                // user@host comes as a single string "user|host"
                $combo = explode('|', (string) ($config['existing_user'] ?? ''), 2);
                $username = $combo[0] ?? '';
                $exHost   = $combo[1] ?? $host;
                if ($username === '') {
                    throw new \RuntimeException(__('Please pick an existing user'));
                }
                DatabaseManager::assignExistingUser($database, $username, $exHost, $privs);
                $host = $exHost;
            } else {
                $username = trim((string) ($config['username'] ?? ''));
                $password =       (string) ($config['password'] ?? '');
                if ($username === '' || $password === '') {
                    throw new \RuntimeException(__('Username and password are required'));
                }
                // Apply customer prefix if not already present
                $cust = '';
                foreach (explode('_', $database, 2) as $part) {
                    $cust = $part;
                    break;
                }
                if ($cust !== '' && !str_starts_with($username, $cust . '_')) {
                    $username = $cust . '_' . $username;
                }
                DatabaseManager::addUser($database, $username, $host, $password, $privs);
            }
        } catch (\Throwable $e) {
            $this->flash('error', __('Databases'), $e->getMessage());
            return;
        }
        ActivityLog::write('database', 'user_add', $database,
                "user={$username}@{$host}");
        $this->flash('success', __('Databases'),
                __('Database user assigned'));
    }

    // ===== Details (legacy) =============================================
    // Kept as a redirect so any old bookmark / form goes to the
    // overview where the same info now lives.

    public function details($name = '')
    {
        $this->flash('info', __('Databases'),
                __('Database management is now on the overview page.'), 'overview');
    }

    // ===== Create database ==============================================

    public function new_database()
    {
        if (!CustomerUtil::hasAccess('create_database')) {
            $this->flash('error', __('Databases'), __('Permission denied'));
            return;
        }
        $cid = (int) Session::getNs('pmwh3', 'customer_id');
        $myCustomer = (string) (CustomerUtil::getCustomerNameById($cid) ?? '');

        // Admin can pick the owner; customers always create for self
        if (CustomerUtil::hasAccess('view_customers')) {
            $owners = \pmwh3\Utils\CustomerManager::listCustomerNames();
        } else {
            $owners = $myCustomer !== '' ? [$myCustomer] : [];
        }

        $this->render('pmwh3/databases/new_database', [
            'activeBox' => 'databases/overview',
            'owners'    => $owners,
            'my_owner'  => $myCustomer,
        ]);
    }

    public function insert_database()
    {
        if (!CustomerUtil::hasAccess('create_database')) {
            $this->flash('error', __('Databases'), __('Permission denied'));
            return;
        }
        $payload = filter_input_array(INPUT_POST, [
            'config' => [
                'filter' => FILTER_DEFAULT,
                'flags'  => FILTER_REQUIRE_ARRAY,
            ],
        ]);
        $config = (array) ($payload['config'] ?? []);
        $suffix = trim((string) ($config['suffix'] ?? ''));
        $owner  = trim((string) ($config['owner']  ?? ''));
        if ($suffix === '') {
            $this->flash('error', __('Databases'),
                    __('Please enter a database name'));
            return;
        }
        // Resolve owner + owner-cid: admin uses dropdown value,
        // customer always self
        $cid = (int) Session::getNs('pmwh3', 'customer_id');
        if (!CustomerUtil::hasAccess('view_customers')) {
            $owner = (string) (CustomerUtil::getCustomerNameById($cid) ?? '');
            $ownerCid = $cid;
        } else {
            if ($owner === '') {
                $this->flash('error', __('Databases'),
                        __('Please pick an owner'));
                return;
            }
            $ownerCid = (int) CustomerUtil::getCustomerIdByName($owner);
            if ($ownerCid <= 0) {
                $this->flash('error', __('Databases'),
                        __('Unknown owner'));
                return;
            }
        }

        // Quota: owner must have a free database slot
        if (!\pmwh3\Utils\CountingUtil::isAllowed($ownerCid, 'dbases')) {
            $q = \pmwh3\Utils\CountingUtil::getQuota($ownerCid, 'dbases');
            $msg = $q['max'] === 0
                    ? __('Databases are not included in this customer\'s package.')
                    : sprintf(__('Database quota exhausted (%d/%d used).'),
                              $q['used'] + $q['granted'], $q['max']);
            $this->flash('error', __('Databases'), $msg, 'new_database');
            return;
        }

        try {
            $name = DatabaseManager::createDatabase($owner, $suffix);
        } catch (\Throwable $e) {
            $this->flash('error', __('Databases'), $e->getMessage(), 'new_database');
            return;
        }
        // Quota tally
        \pmwh3\Utils\CountingUtil::increment($ownerCid, 'dbases');

        ActivityLog::write('database', 'create', $name, "owner={$owner}");
        $this->flash('success', __('Databases'),
                sprintf(__('Database "%s" created'), $name));
    }

    // ===== Delete database ==============================================

    public function delete_database($name = '')
    {
        if (!CustomerUtil::hasAccess('delete_database')) {
            $this->flash('error', __('Databases'), __('Permission denied'));
            return;
        }
        if ($name === '') {
            $this->flash('error', __('Databases'), __('No database specified'));
            return;
        }
        try {
            $row = DatabaseManager::getByName($name);
            if (!$row) {
                $this->flash('error', __('Databases'), __('Database not found'));
                return;
            }
            DatabaseManager::deleteDatabase($name);
        } catch (\Throwable $e) {
            $this->flash('error', __('Databases'), $e->getMessage());
            return;
        }
        // Quota tally: drop one off the owner
        $ownerCid = (int) CustomerUtil::getCustomerIdByName((string) $row['customer']);
        if ($ownerCid > 0) {
            \pmwh3\Utils\CountingUtil::decrement($ownerCid, 'dbases');
        }
        ActivityLog::write('database', 'delete', $name, '');
        $this->flash('success', __('Databases'), __('Database deleted'));
    }

    // ===== Database users ==============================================

    public function add_user($database = '')
    {
        if (!CustomerUtil::hasAccess('edit_database')) {
            $this->flash('error', __('Databases'), __('Permission denied'));
            return;
        }
        $payload = filter_input_array(INPUT_POST, [
            'config' => [
                'filter' => FILTER_DEFAULT,
                'flags'  => FILTER_REQUIRE_ARRAY,
            ],
        ]);
        $config = (array) ($payload['config'] ?? []);
        if ($database === '') {
            $database = (string) ($config['database'] ?? '');
        }
        $username = trim((string) ($config['username'] ?? ''));
        $host     = trim((string) ($config['host']     ?? '%'));
        $password =       (string) ($config['password'] ?? '');
        $allFlag  = !empty($config['priv_all']);
        $privs    = $allFlag ? ['ALL'] : self::extractPrivileges($config);
        if ($host === '') {
            $host = (string) \pmwh3\Config\LazyConfig::get('DB_DEFAULT_HOST', '%');
        }

        try {
            DatabaseManager::addUser($database, $username, $host, $password, $privs);
        } catch (\Throwable $e) {
            $this->flash('error', __('Databases'), $e->getMessage(),
                    'overview');
            return;
        }
        ActivityLog::write('database', 'user_add', $database,
                "user={$username}@{$host}");
        $this->flash('success', __('Databases'),
                __('Database user added'),
                'overview');
    }

    public function delete_user($database = '', $username = '', $host = '%')
    {
        if (!CustomerUtil::hasAccess('edit_database')) {
            $this->flash('error', __('Databases'), __('Permission denied'));
            return;
        }
        if ($database === '' || $username === '') {
            $this->flash('error', __('Databases'), __('Missing database or user'));
            return;
        }
        try {
            DatabaseManager::deleteUser($username, $host);
        } catch (\Throwable $e) {
            $this->flash('error', __('Databases'), $e->getMessage(),
                    'overview');
            return;
        }
        ActivityLog::write('database', 'user_delete', $database,
                "user={$username}@{$host}");
        $this->flash('success', __('Databases'),
                __('Database user removed'),
                'overview');
    }

    public function change_user_password($database = '', $username = '', $host = '%')
    {
        if (!CustomerUtil::hasAccess('edit_database')) {
            $this->flash('error', __('Databases'), __('Permission denied'));
            return;
        }
        $payload = filter_input_array(INPUT_POST, [
            'config' => [
                'filter' => FILTER_DEFAULT,
                'flags'  => FILTER_REQUIRE_ARRAY,
            ],
        ]);
        $config = (array) ($payload['config'] ?? []);
        $newPassword = (string) ($config['password'] ?? '');
        if ($newPassword === '') {
            $this->flash('error', __('Databases'),
                    __('Password cannot be empty'));
            return;
        }
        try {
            DatabaseManager::changeUserPassword($username, $host, $newPassword);
        } catch (\Throwable $e) {
            $this->flash('error', __('Databases'), $e->getMessage(),
                    'overview');
            return;
        }
        ActivityLog::write('database', 'user_password', $database,
                "user={$username}@{$host}");
        $this->flash('success', __('Databases'),
                __('Password changed'),
                'overview');
    }

    // ===== Helpers =====================================================

    private static function extractPrivileges(array $config): array
    {
        $out = [];
        foreach (['select','insert','update','delete','create','alter','drop','index','references'] as $p) {
            if (!empty($config['priv_' . $p])) {
                $out[] = strtoupper($p);
            }
        }
        return $out;
    }
}
