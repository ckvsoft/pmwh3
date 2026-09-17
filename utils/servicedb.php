<?php

namespace pmwh3\Utils;

use ckvsoft\mvc\Config;
use ckvsoft\Database;
use ckvsoft\CkvException;

/**
 * Service-DB resolution (2026-09-17, "mail == mail, web == web, ftp == ftp").
 *
 * Optional module.json nodes:
 *
 *   "mail": { "database": { ... } }
 *   "web":  { "database": { ... } }
 *   "ftp":  { "database": { ... } }
 *
 * A SERVICE node may point at the SAME database as the module DB
 * (default, everything in one store) or at a separate DB (e.g. the
 * legacy mail/proftpd stack database on existing installations).
 * A missing node falls back to the module DB; an INCOMPLETE node
 * (any of type/host/name/user/pass empty) is a hard error -- boolean
 * config mistakes must fail loudly, not silently fallback.
 *
 * Callers get a per-(node) cached Database via Config::moduleDb().
 */
class ServiceDb
{

    /** @var array<string, Database> */
    private static array $cache = [];

    /**
     * Resolve the Database for a service ("mail", "web", "ftp").
     * Falls back to the module DB when the node is absent.
     */
    public static function get(string $node): Database
    {
        if (isset(self::$cache[$node])) {
            return self::$cache[$node];
        }
        $config = Config::module("{$node}.database");
        if (is_array($config) && $config !== []) {
            self::requireComplete($node, $config);
            $db = Config::moduleDb(null, "{$node}.database");
        } else {
            // No node -> module DB owns the service tables.
            $db = Config::moduleDb();
        }
        self::$cache[$node] = $db;
        return $db;
    }

    private static function requireComplete(string $node, $config): void
    {
        foreach (['type', 'host', 'name', 'user', 'pass'] as $f) {
            if (empty($config[$f])) {
                throw new CkvException(ucfirst($node) . " database config incomplete in module.json");
            }
        }
    }

    public static function flush(string $node = ''): void
    {
        if ($node === '') {
            self::$cache = [];
            return;
        }
        unset(self::$cache[$node]);
    }
}
