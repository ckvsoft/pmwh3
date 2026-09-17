<?php

namespace pmwh3\Utils\Db;

/**
 * Adapter contract for one DB backend (mysql today, postgres later).
 *
 * All methods take/return data-only structures so DatabaseManager
 * doesn't need to know about specific server quirks. Adapters are
 * responsible for sanitizing identifiers (db names, user names).
 *
 * Errors are surfaced as \RuntimeException -- the caller (a
 * controller action) decides how to flash that to the UI.
 */
abstract class AbstractDbAdapter
{

    abstract public function ping(): bool;

    /** Raw connection -- for introspection queries managed in DatabaseManager. */
    abstract public function pdo(): \PDO;

    /** CREATE DATABASE $name (no overwrite). Returns true on success. */
    abstract public function createDatabase(string $name, string $charset = 'utf8mb4', string $collation = 'utf8mb4_unicode_ci'): bool;

    /** DROP DATABASE $name (no error if missing). */
    abstract public function dropDatabase(string $name): bool;

    /** CREATE USER + GRANT privileges on one database to user@host. */
    abstract public function createUserWithGrant(string $database, string $user, string $host, string $password, array $privileges): bool;

    /** Change user's password. */
    abstract public function changeUserPassword(string $user, string $host, string $newPassword): bool;

    /** REVOKE + DROP USER. Safe if user doesn't exist. */
    abstract public function dropUser(string $user, string $host): bool;

    /** Return databases the admin user can see (for reconciliation). */
    abstract public function listDatabases(): array;

    /** Return users on the server. */
    abstract public function listUsers(): array;
}
