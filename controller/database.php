<?php

// modules/pmwh3/controller/database.php

use ckvsoft\mvc\BaseController;
use ckvsoft\Session;
use pmwh3\Utils\ActivityLog;
use pmwh3\Utils\CustomerUtil;
use pmwh3\Utils\DatabaseManager;

class Database extends BaseController
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
                : BASE_URI . 'pmwh3/database/' . $where;
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
        $databases = DatabaseManager::listAllVisible($cid);

        $this->render('pmwh3/database/overview', [
            'activeBox'   => 'database/overview',
            'databases'   => $databases,
            'create_perm' => CustomerUtil::hasAccess('create_database'),
            'edit_perm'   => CustomerUtil::hasAccess('edit_database'),
            'delete_perm' => CustomerUtil::hasAccess('delete_database'),
        ]);
    }

    // ===== Details (one DB + its users) =================================

    public function details($id = 0)
    {
        if (!CustomerUtil::hasAccess('view_databases')) {
            $this->flash('error', __('Databases'), __('Permission denied'), '');
            return;
        }
        $id = (int) $id;
        $row = DatabaseManager::getById($id);
        if (!$row) {
            $this->flash('error', __('Databases'), __('Database not found'));
            return;
        }
        $this->render('pmwh3/database/details', [
            'activeBox'   => 'database/overview',
            'row'         => $row,
            'users'       => DatabaseManager::listUsersForDatabase($id),
            'advanced'    => strtoupper((string) \pmwh3\Config\LazyConfig::get('DB_ADVANCED', 'Y')) === 'Y',
            'default_host' => (string) \pmwh3\Config\LazyConfig::get('DB_DEFAULT_HOST', '%'),
            'edit_perm'   => CustomerUtil::hasAccess('edit_database'),
            'delete_perm' => CustomerUtil::hasAccess('delete_database'),
        ]);
    }

    // ===== Create database ==============================================

    public function new_database()
    {
        if (!CustomerUtil::hasAccess('create_database')) {
            $this->flash('error', __('Databases'), __('Permission denied'));
            return;
        }
        $cid = (int) Session::getNs('pmwh3', 'customer_id');
        $customer = (string) (CustomerUtil::getCustomerNameById($cid) ?? '');
        $this->render('pmwh3/database/new_database', [
            'activeBox' => 'database/overview',
            'customer'  => $customer,
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
        if ($suffix === '') {
            $this->flash('error', __('Databases'),
                    __('Please enter a database name'));
            return;
        }

        $cid = (int) Session::getNs('pmwh3', 'customer_id');
        $customer = (string) (CustomerUtil::getCustomerNameById($cid) ?? '');

        try {
            $row = DatabaseManager::createDatabase($cid, $customer, $suffix);
        } catch (\Throwable $e) {
            $this->flash('error', __('Databases'), $e->getMessage(), 'new_database');
            return;
        }
        ActivityLog::write('database', 'create', (string) $row['name'], '');
        $this->flash('success', __('Databases'),
                sprintf(__('Database "%s" created'), $row['name']));
    }

    // ===== Delete database ==============================================

    public function delete_database($id = 0)
    {
        if (!CustomerUtil::hasAccess('delete_database')) {
            $this->flash('error', __('Databases'), __('Permission denied'));
            return;
        }
        $id = (int) $id;
        $row = DatabaseManager::getById($id);
        if (!$row) {
            $this->flash('error', __('Databases'), __('Database not found'));
            return;
        }
        try {
            DatabaseManager::deleteDatabase($id);
        } catch (\Throwable $e) {
            $this->flash('error', __('Databases'), $e->getMessage());
            return;
        }
        ActivityLog::write('database', 'delete', (string) $row['name'], '');
        $this->flash('success', __('Databases'), __('Database deleted'));
    }

    // ===== Database users ==============================================

    public function add_user($databaseId = 0)
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

        $databaseId = (int) ($databaseId ?: ($config['database_id'] ?? 0));
        $row = DatabaseManager::getById($databaseId);
        if (!$row) {
            $this->flash('error', __('Databases'), __('Database not found'));
            return;
        }
        $username = trim((string) ($config['username'] ?? ''));
        $host     = trim((string) ($config['host']     ?? '%'));
        $password =       (string) ($config['password'] ?? '');
        $allFlag  = !empty($config['priv_all']);
        $privs = $allFlag ? ['ALL'] : self::extractPrivileges($config);

        if ($host === '') {
            $host = (string) \pmwh3\Config\LazyConfig::get('DB_DEFAULT_HOST', '%');
        }

        try {
            DatabaseManager::addUser($databaseId, $username, $host, $password, $privs);
        } catch (\Throwable $e) {
            $this->flash('error', __('Databases'), $e->getMessage(),
                    'details/' . $databaseId);
            return;
        }
        ActivityLog::write('database', 'user_add', (string) $row['name'],
                "user={$username}@{$host}");
        $this->flash('success', __('Databases'),
                __('Database user added'), 'details/' . $databaseId);
    }

    public function delete_user($userId = 0)
    {
        if (!CustomerUtil::hasAccess('edit_database')) {
            $this->flash('error', __('Databases'), __('Permission denied'));
            return;
        }
        $userId = (int) $userId;
        $u = DatabaseManager::getUserById($userId);
        if (!$u) {
            $this->flash('error', __('Databases'), __('User not found'));
            return;
        }
        try {
            DatabaseManager::deleteUser(
                    (string) $u['username'],
                    (string) $u['host']
            );
        } catch (\Throwable $e) {
            $this->flash('error', __('Databases'), $e->getMessage(),
                    'details/' . (int) $u['database_id']);
            return;
        }
        $db = DatabaseManager::getById((int) $u['database_id']);
        ActivityLog::write('database', 'user_delete',
                (string) ($db['name'] ?? ''),
                "user={$u['username']}@{$u['host']}");
        $this->flash('success', __('Databases'),
                __('Database user removed'),
                'details/' . (int) $u['database_id']);
    }

    public function change_user_password($userId = 0)
    {
        if (!CustomerUtil::hasAccess('edit_database')) {
            $this->flash('error', __('Databases'), __('Permission denied'));
            return;
        }
        $userId = (int) $userId;
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
        $u = DatabaseManager::getUserById($userId);
        if (!$u) {
            $this->flash('error', __('Databases'), __('User not found'));
            return;
        }
        try {
            DatabaseManager::changeUserPassword(
                    (string) $u['username'],
                    (string) $u['host'],
                    $newPassword
            );
        } catch (\Throwable $e) {
            $this->flash('error', __('Databases'), $e->getMessage(),
                    'details/' . (int) $u['database_id']);
            return;
        }
        $db = DatabaseManager::getById((int) $u['database_id']);
        ActivityLog::write('database', 'user_password',
                (string) ($db['name'] ?? ''),
                "user={$u['username']}@{$u['host']}");
        $this->flash('success', __('Databases'),
                __('Password changed'),
                'details/' . (int) $u['database_id']);
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
