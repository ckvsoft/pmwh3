<?php

namespace pmwh3\Utils\Db;

use ckvsoft\mvc\Config;
use PDO;
use PDOException;
use RuntimeException;

/**
 * MySQL / MariaDB adapter. Opens its own PDO with the DB admin
 * credentials from Options (DB_HOST, DB_PORT, DB_ADMIN_USER,
 * DB_ADMIN_PASS) -- NOT Config::moduleDb(), which is the module's
 * data connection.
 *
 * Identifier sanitisation is strict: db names and user names must
 * match a tight pattern. We do NOT use prepared placeholders for
 * identifiers (MySQL doesn't allow that), so the pattern check is
 * the only barrier against injection.
 *
 * Passwords ARE passed as parameters (PDO).
 *
 * On any backend error, throws RuntimeException with the server
 * message. Callers can catch and flash.
 */
class MysqlDbAdapter extends AbstractDbAdapter
{

    private ?PDO $pdo = null;
    private array $cfg;

    public function __construct(array $cfg)
    {
        // cfg: ['host'=>..., 'port'=>..., 'user'=>..., 'pass'=>...]
        $this->cfg = $cfg;
    }

    private function connect(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }
        $host = (string) ($this->cfg['host'] ?? 'localhost');
        $port = (int)    ($this->cfg['port'] ?? 3306);
        $user = (string) ($this->cfg['user'] ?? '');
        $pass = (string) ($this->cfg['pass'] ?? '');

        try {
            // Connection created (and cached) by the framework's
            // Config::cachedDatabase() -- no self-built PDO.
            // 'name' is intentionally omitted: this is an admin
            // connection that must connect without a default dbname
            // (database/user membership is managed via SQL below).
            $pdo = Config::cachedDatabase([
                'type' => 'mysql',
                'host' => $host,
                'port' => $port,
                'user' => $user,
                'pass' => $pass,
            ]);
        } catch (\Throwable $e) {
            throw new RuntimeException(
                    "Cannot connect as DB admin '{$user}@{$host}:{$port}': "
                    . $e->getMessage()
            );
        }
        return $this->pdo = $pdo;
    }

    public function ping(): bool
    {
        try {
            $this->connect()->select("SELECT 1");
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Direct access to the underlying PDO -- for DatabaseManager
     * to run mysql.* introspection queries (mysql.db, mysql.user)
     * that aren't worth abstracting into adapter methods of their
     * own.
     */
    public function pdo(): PDO
    {
        return $this->connect();
    }

    /**
     * Identifier checker. Names must be 1..64 chars, letters /
     * digits / underscore. Hyphens are technically legal in MySQL
     * but require backtick-quoting AND make GRANT statements
     * trickier; safer to reject.
     */
    private static function safeIdent(string $name): string
    {
        if ($name === '') {
            throw new RuntimeException('Empty identifier');
        }
        if (strlen($name) > 64) {
            throw new RuntimeException('Identifier too long (max 64 chars)');
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            throw new RuntimeException(
                    "Invalid identifier '{$name}' (letters, digits, underscore only)"
            );
        }
        return $name;
    }

    /**
     * Host part of user@host. Allows hostnames, IPs, '%' wildcards.
     * Reject newlines and quotes; everything else passes.
     */
    private static function safeHost(string $host): string
    {
        $host = trim($host);
        if ($host === '') {
            return '%';
        }
        if (preg_match('/[\'"\\\\\\x00-\\x1f\\x7f]/', $host)) {
            throw new RuntimeException(
                    "Invalid host '{$host}'"
            );
        }
        return $host;
    }

    public function createDatabase(string $name, string $charset = 'utf8mb4', string $collation = 'utf8mb4_unicode_ci'): bool
    {
        $name = self::safeIdent($name);
        $charset   = self::safeIdent($charset);
        $collation = self::safeIdent($collation);
        try {
            $this->connect()->execDdl(
                    "CREATE DATABASE `{$name}` "
                    . "CHARACTER SET {$charset} COLLATE {$collation}"
            );
            return true;
        } catch (PDOException $e) {
            throw new RuntimeException(
                    "CREATE DATABASE failed: " . $e->getMessage()
            );
        }
    }

    public function dropDatabase(string $name): bool
    {
        $name = self::safeIdent($name);
        try {
            $this->connect()->execDdl("DROP DATABASE IF EXISTS `{$name}`");
            return true;
        } catch (PDOException $e) {
            throw new RuntimeException(
                    "DROP DATABASE failed: " . $e->getMessage()
            );
        }
    }

    /**
     * Privileges is an array of MySQL privilege keywords
     * ('SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'ALTER',
     * 'DROP', 'INDEX', 'REFERENCES'). Empty -> SELECT only. Special
     * marker 'ALL' grants ALL PRIVILEGES.
     */
    public function createUserWithGrant(string $database, string $user, string $host, string $password, array $privileges): bool
    {
        $database = self::safeIdent($database);
        $user     = self::safeIdent($user);
        $host     = self::safeHost($host);

        $whitelist = ['SELECT', 'INSERT', 'UPDATE', 'DELETE',
                      'CREATE', 'ALTER', 'DROP', 'INDEX', 'REFERENCES'];
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

        $pdo = $this->connect();
        try {
            // CREATE USER first (idempotent: IF NOT EXISTS available
            // on MariaDB 10.1.3+ and MySQL 5.7.6+).
            $pdo->execDdl(
                    "CREATE USER IF NOT EXISTS :user@:host IDENTIFIED BY :pw",
                    ['user' => $user, 'host' => $host, 'pw' => $password]
            );

            // GRANT can't use placeholders for identifiers, but the
            // user/host strings are already safeIdent/safeHost'd.
            $this->connect()->execDdl(
                    "GRANT {$privsSql} ON `{$database}`.* "
                    . "TO `{$user}`@`{$host}`"
            );
            $this->connect()->execDdl('FLUSH PRIVILEGES');
            return true;
        } catch (PDOException $e) {
            throw new RuntimeException(
                    "GRANT failed: " . $e->getMessage()
            );
        }
    }

    public function changeUserPassword(string $user, string $host, string $newPassword): bool
    {
        $user = self::safeIdent($user);
        $host = self::safeHost($host);
        try {
            // ALTER USER works on MariaDB 10.2+ and MySQL 5.7.6+.
            // Pre-2017 servers would need SET PASSWORD instead.
            $this->connect()->execDdl(
                    "ALTER USER :user@:host IDENTIFIED BY :pw",
                    ['user' => $user, 'host' => $host, 'pw' => $newPassword]
            );
            $this->connect()->execDdl('FLUSH PRIVILEGES');
            return true;
        } catch (PDOException $e) {
            throw new RuntimeException(
                    "ALTER USER failed: " . $e->getMessage()
            );
        }
    }

    public function dropUser(string $user, string $host): bool
    {
        $user = self::safeIdent($user);
        $host = self::safeHost($host);
        try {
            $this->connect()->execDdl(
                    "DROP USER IF EXISTS `{$user}`@`{$host}`"
            );
            $this->connect()->execDdl('FLUSH PRIVILEGES');
            return true;
        } catch (PDOException $e) {
            throw new RuntimeException(
                    "DROP USER failed: " . $e->getMessage()
            );
        }
    }

    public function listDatabases(): array
    {
        $rows = $this->connect()->select("SHOW DATABASES");
        return array_map(fn($r) => $r['Database'] ?? '', $rows);
    }

    public function listUsers(): array
    {
        return $this->connect()->select(
                "SELECT User, Host FROM mysql.user ORDER BY User, Host"
        );
    }
}
