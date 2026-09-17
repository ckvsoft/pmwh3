<?php

namespace pmwh3\Utils;

use ckvsoft\mvc\Config;
use pmwh3\Config\LazyConfig;
use pmwh3\Utils\Db\MysqlDbAdapter;
use pmwh3\Utils\Db\AbstractDbAdapter;
use RuntimeException;

/**
 * Database management facade.
 *
 * Source of truth is the MariaDB server itself -- we don't keep
 * our own pmwh3_databases bookkeeping table. Listing databases
 * is `SHOW DATABASES` filtered by the per-customer prefix
 * convention <customer>_<suffix>; listing users is `mysql.db`
 * for the per-DB grants.
 *
 * That mirrors what pmwh2 did and avoids the bookkeeping table
 * drifting out of sync with reality (someone creates a DB by
 * hand, etc).
 */
class DatabaseManager
{

    private static ?AbstractDbAdapter $adapter = null;

    public static function adapter(): AbstractDbAdapter
    {
        if (self::$adapter === null) {
            $type = (string) LazyConfig::get('DB_TYPE', 'mysql');
            $cfg  = [
                'host' => (string) LazyConfig::get('DB_HOST', 'mariadb'),
                'port' => (int)    LazyConfig::get('DB_PORT', '3306'),
                'user' => (string) LazyConfig::get('DB_ADMIN_USER', ''),
                'pass' => (string) LazyConfig::get('DB_ADMIN_PASS', ''),
            ];
            if ($cfg['user'] === '') {
                throw new RuntimeException(
                        'DB_ADMIN_USER is not configured. Set it in Options > Databases.'
                );
            }
            switch ($type) {
                case 'mysql':
                default:
                    self::$adapter = new MysqlDbAdapter($cfg);
                    break;
            }
        }
        return self::$adapter;
    }

    // ===== Read (live from MariaDB) =====================================

    /**
     * Databases visible to the viewer. Admin sees all customer
     * databases; a regular customer sees only their own.
     *
     * Returns: [ ['name' => '...', 'customer' => '...'], ... ]
     */
    public static function listAllVisible(int $viewerCid): array
    {
        $isAdmin = CustomerUtil::hasAccess('view_customers');
        $all     = self::adapter()->listDatabases();

        // System DBs to never expose
        $skip = ['information_schema', 'mysql', 'performance_schema',
                 'sys', 'pmwh3', 'pdns', 'cevian'];

        if ($isAdmin) {
            $customers = self::loadAllCustomers();
        } else {
            $name = (string) (CustomerUtil::getCustomerNameById($viewerCid) ?? '');
            $customers = $name !== '' ? [$name] : [];
        }
        if (empty($customers)) {
            return [];
        }

        $out = [];
        foreach ($all as $db) {
            if (in_array($db, $skip, true)) {
                continue;
            }
            foreach ($customers as $cust) {
                if ($db === $cust || str_starts_with($db, $cust . '_')) {
                    $out[] = ['name' => $db, 'customer' => $cust];
                    break;
                }
            }
        }
        usort($out, fn($a, $b) =>
                strcmp($a['customer'] . '/' . $a['name'],
                       $b['customer'] . '/' . $b['name']));
        return $out;
    }

    /**
     * Row for one database name, or null if not on the server.
     */
    public static function getByName(string $name): ?array
    {
        if (!in_array($name, self::adapter()->listDatabases(), true)) {
            return null;
        }
        $cust = '';
        foreach (self::loadAllCustomers() as $c) {
            if ($name === $c || str_starts_with($name, $c . '_')) {
                $cust = $c;
                break;
            }
        }
        return ['name' => $name, 'customer' => $cust];
    }

    /**
     * Users with grants on a specific database. Reads mysql.db.
     */
    public static function listUsersForDatabase(string $name): array
    {
        $pdo = self::adapter()->pdo();
        $rows = $pdo->select(
                "SELECT User, Host,
                        Select_priv, Insert_priv, Update_priv, Delete_priv,
                        Create_priv, Drop_priv, Alter_priv,
                        Index_priv, References_priv
                   FROM mysql.db
                  WHERE Db = :db
               ORDER BY User, Host",
        ['db' => $name]);

        $privCols = [
            'Select_priv'     => 'SELECT',
            'Insert_priv'     => 'INSERT',
            'Update_priv'     => 'UPDATE',
            'Delete_priv'     => 'DELETE',
            'Create_priv'     => 'CREATE',
            'Drop_priv'       => 'DROP',
            'Alter_priv'      => 'ALTER',
            'Index_priv'      => 'INDEX',
            'References_priv' => 'REFERENCES',
        ];
        $out = [];
        foreach ($rows as $r) {
            $privs = [];
            foreach ($privCols as $col => $label) {
                if (($r[$col] ?? 'N') === 'Y') {
                    $privs[] = $label;
                }
            }
            $out[] = [
                'username'   => (string) $r['User'],
                'host'       => (string) $r['Host'],
                'privileges' => count($privs) === count($privCols)
                        ? 'ALL'
                        : implode(',', $privs),
            ];
        }
        return $out;
    }

    // ===== Writes =======================================================

    /**
     * Compose a final database name. Style comes from DB_NAME_PREFIX:
     *   'customer' (default) -> <customer>_<suffix>
     *   'none'               -> <suffix> as-is
     */
    public static function composeName(string $customer, string $suffix): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_]/', '', $suffix);
        $clean = strtolower(substr((string) $clean, 0, 32));
        $custClean = preg_replace('/[^A-Za-z0-9_]/', '', $customer);
        if ($custClean === '') {
            return $clean;
        }
        if (strtoupper((string) LazyConfig::get('DB_NAME_PREFIX', 'customer')) === 'NONE') {
            return $clean;
        }
        return strtolower($custClean) . '_' . $clean;
    }

    public static function createDatabase(string $customer, string $suffix): string
    {
        $name = self::composeName($customer, $suffix);
        if ($name === '' || str_ends_with($name, '_')) {
            throw new RuntimeException('Empty or invalid database name');
        }
        if (in_array($name, self::adapter()->listDatabases(), true)) {
            throw new RuntimeException(
                    "Database '{$name}' already exists on the server"
            );
        }
        self::adapter()->createDatabase($name);
        return $name;
    }

    public static function deleteDatabase(string $name): bool
    {
        // Revoke all grants this database has, then drop
        $users = self::listUsersForDatabase($name);
        foreach ($users as $u) {
            if ($u['username'] === LazyConfig::get('DB_ADMIN_USER', '')) {
                continue;
            }
            try {
                self::revokeUserFromDb($name, (string) $u['username'], (string) $u['host']);
            } catch (\Throwable $e) {
                \pmwh3\Utils\ErrorHandler::trace("revoke failed for {$u['username']}@{$u['host']}: " . $e->getMessage());
            }
        }
        self::adapter()->dropDatabase($name);
        return true;
    }

    public static function addUser(string $database, string $username, string $host, string $password, array $privileges): bool
    {
        if ($username === '' || $password === '') {
            throw new RuntimeException('Username and password are required');
        }
        if (!in_array($database, self::adapter()->listDatabases(), true)) {
            throw new RuntimeException("Database '{$database}' does not exist");
        }
        self::adapter()->createUserWithGrant(
                $database, $username, $host, $password, $privileges
        );
        return true;
    }

    /**
     * Grant an EXISTING DB user access to one more database. No new
     * user is created, no password change -- only GRANT.
     */
    public static function assignExistingUser(string $database, string $username, string $host, array $privileges): bool
    {
        if ($username === '') {
            throw new RuntimeException('Username is required');
        }
        if (!in_array($database, self::adapter()->listDatabases(), true)) {
            throw new RuntimeException("Database '{$database}' does not exist");
        }
        // Issue GRANT only -- skip CREATE USER (user already exists).
        // We reuse the same whitelist/marker logic as createUserWithGrant
        // but without the user-creation step.
        $whitelist = ['SELECT','INSERT','UPDATE','DELETE',
                      'CREATE','ALTER','DROP','INDEX','REFERENCES'];
        $privs = [];
        if (in_array('ALL', $privileges, true)) {
            $privs = ['ALL PRIVILEGES'];
        } else {
            foreach ($privileges as $p) {
                $p = strtoupper(trim((string) $p));
                if (in_array($p, $whitelist, true)) {
                    $privs[] = $p;
                }
            }
            if (empty($privs)) {
                $privs = ['SELECT'];
            }
        }
        $privsSql = implode(', ', $privs);

        if (!preg_match('/^[A-Za-z0-9_]+$/', $database)) {
            throw new RuntimeException("Invalid database name");
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/', $username)) {
            throw new RuntimeException("Invalid username");
        }
        $hostQ = addslashes($host);

        $pdo = self::adapter()->pdo();
        try {
            $pdo->execDdl(
                    "GRANT {$privsSql} ON `{$database}`.* "
                    . "TO `{$username}`@`{$hostQ}`"
            );
            $pdo->execDdl('FLUSH PRIVILEGES');
        } catch (\PDOException $e) {
            throw new RuntimeException(
                    "GRANT failed: " . $e->getMessage()
            );
        }
        return true;
    }

    public static function deleteUser(string $username, string $host): bool
    {
        if ($username === LazyConfig::get('DB_ADMIN_USER', '')) {
            throw new RuntimeException('Refusing to drop the DB admin user');
        }
        self::adapter()->dropUser($username, $host);
        return true;
    }

    public static function changeUserPassword(string $username, string $host, string $newPassword): bool
    {
        if ($newPassword === '') {
            throw new RuntimeException('Empty password');
        }
        self::adapter()->changeUserPassword($username, $host, $newPassword);
        return true;
    }

    /**
     * Look up a row in pmwh3_database_users by its primary key.
     * The controller layer talks in row-ids (URL slugs); the
     * MySQL-facing methods above want (username, host) -- this
     * helper bridges the two.
     */
    public static function getUserById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $row = Config::moduleDb()->selectOne(
                "SELECT * FROM pmwh3_database_users WHERE id = :i",
                ['i' => $id]
        );
        return $row ?: null;
    }

    /**
     * List all DB users that have the customer's prefix, regardless
     * of which DB they're grant'd on. Used for the "Existing user"
     * dropdown when assigning a user to a database.
     */
    public static function listDatabaseUsersByCustomer(string $customer): array
    {
        if ($customer === '') {
            return [];
        }
        $pdo = self::adapter()->pdo();
        $rows = $pdo->select(
                "SELECT DISTINCT User, Host
                   FROM mysql.user
                  WHERE User LIKE :prefix
               ORDER BY User, Host",
        ['prefix' => $customer . '_%']);
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'username' => (string) $r['User'],
                'host'     => (string) $r['Host'],
            ];
        }
        return $out;
    }

    /**
     * Toggle a single privilege for one user on one database.
     * $privilege is a MySQL keyword: SELECT / INSERT / UPDATE / ...
     * $granted true => GRANT, false => REVOKE.
     */
    public static function setUserPrivilege(string $database, string $username, string $host, string $privilege, bool $granted): bool
    {
        $whitelist = ['SELECT','INSERT','UPDATE','DELETE',
                      'CREATE','ALTER','DROP','INDEX','REFERENCES'];
        $privilege = strtoupper(trim($privilege));
        if (!in_array($privilege, $whitelist, true)) {
            throw new RuntimeException("Privilege '{$privilege}' not allowed");
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/', $database)) {
            throw new RuntimeException("Invalid database name");
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/', $username)) {
            throw new RuntimeException("Invalid username");
        }
        // Host is trickier; defer to adapter rules. Quote inline.
        $hostQ = addslashes($host);
        $pdo = self::adapter()->pdo();
        $verb = $granted ? 'GRANT' : 'REVOKE';
        $on   = $granted ? 'TO'    : 'FROM';
        try {
            $pdo->execDdl(
                    "{$verb} {$privilege} ON `{$database}`.* "
                    . "{$on} `{$username}`@`{$hostQ}`"
            );
            $pdo->execDdl('FLUSH PRIVILEGES');
        } catch (\PDOException $e) {
            throw new RuntimeException(
                    "{$verb} {$privilege} failed: " . $e->getMessage()
            );
        }
        return true;
    }

    private static function revokeUserFromDb(string $database, string $username, string $host): void
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $database)) return;
        if (!preg_match('/^[A-Za-z0-9_]+$/', $username)) return;
        $pdo = self::adapter()->pdo();
        try {
            $pdo->execDdl(
                    "REVOKE ALL PRIVILEGES ON `{$database}`.* "
                    . "FROM `{$username}`@`" . addslashes($host) . "`"
            );
            $pdo->execDdl('FLUSH PRIVILEGES');
        } catch (\PDOException $e) {
            // ignore -- grant may not have existed
        }
    }

    // ===== Internal =====================================================

    private static function loadAllCustomers(): array
    {
        $rows = Config::moduleDb()->select(
                "SELECT customer FROM pmwh3_customers WHERE customer IS NOT NULL AND customer <> ''"
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = (string) $r['customer'];
        }
        return $out;
    }
}
