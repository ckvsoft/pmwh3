<?php

namespace pmwh3\Config;

use ckvsoft\mvc\Config;

/**
 * Handles lazy loading and caching of module-specific configuration values from the database.
 */
class LazyConfig extends Config
{

    /**
     * Cache für bereits geladene Keys
     */
    private static array $cache = [];

    /**
     * Cache für alle Keys
     */
    private static ?array $allCache = null;

    /**
     * Optional: Legacy-Konstanten definieren
     */
    private static bool $defineLegacyConstants = true;

    /**
     * Lazily retrieves a configuration value from the database (static access).
     *
     * @param string $key The configuration key (e.g., 'IP_ADDRESS').
     * @param mixed $default The default value if the key is not found.
     * @param bool $translate If true, translates the value.
     * @return mixed The configuration value.
     */
    public static function get(string $key, $default = null, bool $translate = false)
    {
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $moduleDb = self::moduleDb();

        if ($moduleDb === null) {
            throw new \RuntimeException("LazyConfig: Module database connection failed to initialize.");
        }

        try {
            $sql = "SELECT configuration_value FROM pmwh3_configuration WHERE configuration_key = :key";
            $bindParams = ['key' => $key];

            // NEUE LOGIK: Verwenden Sie $moduleDb->select() mit PDO::FETCH_COLUMN.
            // Das Ergebnis ist ein Array, dessen erstes Element der gesuchte Wert ist.
            $results = $moduleDb->select($sql, $bindParams, \PDO::FETCH_COLUMN);
            $value = $results[0] ?? null;
        } catch (\ckvsoft\CkvException $e) {
            // Fängt die Exception ab, die vom $moduleDb->select() geworfen wird.
            error_log("LazyConfig DB Error for key '{$key}': " . $e->getMessage());
            $value = $default;
        }

        if ($value === null) {
            // No row in pmwh3_configuration for this key. Before
            // returning the caller-supplied $default, consult the
            // settings schema -- if it declares a default for this
            // key, use that. Otherwise the schema's defaults would
            // only apply after the user explicitly hit Save in the
            // Options UI, which is a stumbling block (see e.g.
            // DNS_TYPE silently coming back '' on a fresh install).
            $schemaDef = \pmwh3\Config\SettingsSchema::get($key);
            if (is_array($schemaDef) && array_key_exists('default', $schemaDef)) {
                $value = $schemaDef['default'];
            }
        }

        if ($value === null) {
            $value = $default;
        }

        if ($translate && $value !== null) {
            $value = __($value);
        }

        self::$cache[$key] = $value;

        // ... (Legacy constant definition logic remains the same) ...

        return $value;
    }

    /**
     * Retrieves all configuration values from the database and caches them.
     *
     * @return array Map of configuration keys to values.
     */
    public static function getAll(): array
    {
        if (self::$allCache !== null) {
            return self::$allCache;
        }

        $moduleDb = self::moduleDb();

        if ($moduleDb === null) {
            throw new \RuntimeException("LazyConfig: Module database connection failed to initialize.");
        }

        try {
            $sql = "SELECT configuration_key, configuration_value FROM pmwh3_configuration";

            // NEUE LOGIK: Verwenden Sie $moduleDb->select() mit PDO::FETCH_KEY_PAIR.
            $rows = $moduleDb->select($sql, [], \PDO::FETCH_KEY_PAIR);
        } catch (\ckvsoft\CkvException $e) {
            // Fängt die Exception ab, die vom $moduleDb->select() geworfen wird.
            error_log("LazyConfig DB Error in getAll: " . $e->getMessage());
            $rows = [];
        }

        self::$allCache = $rows;

        // Cache all values individually as well
        foreach ($rows as $k => $v) {
            self::$cache[$k] = $v;
        }

        return self::$allCache;
    }

    /**
     * Persists a configuration value. Inserts the row if missing,
     * updates it otherwise. Returns the old value (or null if the
     * key did not exist).
     *
     * Does NOT validate the value against SettingsSchema; that's the
     * caller's responsibility (so this method stays usable for
     * callers that don't go through the Options UI).
     */
    public static function set(string $key, $value): ?string
    {
        $moduleDb = self::moduleDb();
        if ($moduleDb === null) {
            throw new \RuntimeException("LazyConfig: Module database connection failed to initialize.");
        }

        // Read existing row.
        $existing = $moduleDb->selectOne(
                "SELECT id, configuration_value
                   FROM pmwh3_configuration
                  WHERE configuration_key = :key",
                ['key' => $key]
        );

        $oldValue = $existing['configuration_value'] ?? null;

        if ($existing) {
            $moduleDb->update(
                    'pmwh3_configuration',
                    ['configuration_value' => (string) $value],
                    'id = :id',
                    ['id' => (int) $existing['id']]
            );
        } else {
            $moduleDb->insert('pmwh3_configuration', [
                'configuration_key'   => $key,
                'configuration_value' => (string) $value,
            ]);
        }

        // Keep caches consistent.
        self::$cache[$key] = $value;
        if (self::$allCache !== null) {
            self::$allCache[$key] = $value;
        }

        return $oldValue !== null ? (string) $oldValue : null;
    }

    /**
     * Forget a single key (or everything) so the next get() rereads
     * from the database. Useful in tests and after bulk imports.
     */
    public static function clearCache(?string $key = null): void
    {
        if ($key === null) {
            self::$cache = [];
            self::$allCache = null;
            return;
        }
        unset(self::$cache[$key]);
        if (self::$allCache !== null) {
            unset(self::$allCache[$key]);
        }
    }
}
