<?php

namespace pmwh3\Utils;

use ckvsoft\mvc\Config;

/**
 * Discovers backend adapter classes and exposes them as
 * (key => name) lists for option dropdowns.
 *
 * A discoverable adapter is a non-abstract class in a given directory
 * that implements a given interface AND exposes the static methods
 * getKey() / getName() / isAvailable() on the interface itself.
 *
 * Usage from OptionProviders:
 *
 *   public static function dnsAdapters(): array {
 *       return AdapterRegistry::discover(
 *           __DIR__ . '/../utils/dns',
 *           \pmwh3\Utils\Dns\DnsAdapterInterface::class
 *       );
 *   }
 *
 * Adapters whose isAvailable() returns false are filtered out (e.g.
 * the MyDNS adapter on an installation that doesn't have mydns_soa).
 */
class AdapterRegistry
{

    /** @var array<string, array<string,string>>  $cache[interface] = [key => name, ...] */
    private static array $cache = [];

    /** @var array<string, array<string,string>>  $classCache[interface@dir] = [key => class FQCN, ...] */
    private static array $classCache = [];

    /**
     * Returns ['key' => class FQCN, ...] sorted by name. Same discovery
     * rules as discover(), but resolves the concrete class instead of
     * only the display pair. Used by *Manager classes that must load
     * the configured adapter (DnsManager etc.).
     */
    public static function classes(string $dir, string $interface): array
    {
        $cacheKey = $interface . '@' . $dir;
        if (isset(self::$classCache[$cacheKey])) {
            return self::$classCache[$cacheKey];
        }

        $found = [];
        if (!is_dir($dir)) {
            return self::$classCache[$cacheKey] = $found;
        }

        foreach (glob($dir . '/*.php') ?: [] as $file) {
            require_once $file;
            foreach (get_declared_classes() as $class) {
                try {
                    $rc = new \ReflectionClass($class);
                } catch (\Throwable $e) {
                    continue;
                }
                if ($rc->getFileName() !== realpath($file)) {
                    continue;
                }
                if ($rc->isAbstract() || $rc->isInterface()) {
                    continue;
                }
                if (!$rc->implementsInterface($interface)) {
                    continue;
                }
                if (!$rc->hasMethod('getKey') || !$rc->hasMethod('getName')
                        || !$rc->hasMethod('isAvailable')) {
                    continue;
                }
                try {
                    if (!$class::isAvailable()) {
                        continue;
                    }
                    $key = (string) $class::getKey();
                    if ($key === '') {
                        continue;
                    }
                    $found[$key] = $class;
                } catch (\Throwable $e) {
                    \pmwh3\Utils\ErrorHandler::trace("AdapterRegistry: skipping {$class}: " . $e->getMessage());
                    continue;
                }
            }
        }

        return self::$classCache[$cacheKey] = $found;
    }

    /**
     * Returns ['key' => 'Display Name', ...] sorted by name.
     * Empty array if directory is missing or nothing matches.
     */
    public static function discover(string $dir, string $interface): array
    {
        $cacheKey = $interface . '@' . $dir;
        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        $found = [];

        if (!is_dir($dir)) {
            return self::$cache[$cacheKey] = $found;
        }

        $files = glob($dir . '/*.php') ?: [];

        foreach ($files as $file) {
            // Make sure the class is loaded before reflection.
            // The autoloader can't always resolve filename->class so
            // we just include the file directly. Idempotent.
            require_once $file;

            // Walk all currently declared classes; identify ones whose
            // file matches our path. (declared classes is a flat list,
            // so we need filename matching to know which class came
            // from this require.)
            foreach (get_declared_classes() as $class) {
                try {
                    $rc = new \ReflectionClass($class);
                } catch (\Throwable $e) {
                    continue;
                }
                if ($rc->getFileName() !== realpath($file)) {
                    continue;
                }
                if ($rc->isAbstract() || $rc->isInterface()) {
                    continue;
                }
                if (!$rc->implementsInterface($interface)) {
                    continue;
                }

                // Guard: required static methods must exist
                if (!$rc->hasMethod('getKey') || !$rc->hasMethod('getName')
                        || !$rc->hasMethod('isAvailable')) {
                    continue;
                }

                try {
                    if (!$class::isAvailable()) {
                        continue;
                    }
                    $key  = (string) $class::getKey();
                    $name = (string) $class::getName();
                    if ($key === '') {
                        continue;
                    }
                    $found[$key] = $name;
                } catch (\Throwable $e) {
                    \pmwh3\Utils\ErrorHandler::trace("AdapterRegistry: skipping {$class}: " . $e->getMessage());
                    continue;
                }
            }
        }

        asort($found, SORT_NATURAL | SORT_FLAG_CASE);
        return self::$cache[$cacheKey] = $found;
    }

    /**
     * Like discover(), but keeps UNAVAILABLE adapters in the list with
     * a reason -- the options UI shows WHY an adapter is unusable
     * instead of silently hiding it (a filter that hides everything
     * leaves the admin staring at an empty dropdown).
     *
     * Returns ['key' => ['name' => ..., 'available' => bool,
     *                    'reason' => string|null], ...] sorted by name.
     * Adapters may expose `public static function unavailabilityReason():
     * string` (compact "why"; '' == available). Without that method an
     * unavailable adapter just states "(not available)".
     */
    public static function discoverWithStatus(string $dir, string $interface): array
    {
        $cacheKey = 'status@' . $interface . '@' . $dir;
        if (isset(self::$classCache[$cacheKey])) {
            return self::$classCache[$cacheKey];
        }

        $byKey = [];
        if (is_dir($dir)) {
            foreach (glob($dir . '/*.php') ?: [] as $file) {
                require_once $file;
                foreach (get_declared_classes() as $class) {
                    try {
                        $rc = new \ReflectionClass($class);
                    } catch (\Throwable $e) {
                        continue;
                    }
                    if ($rc->getFileName() !== realpath($file)) {
                        continue;
                    }
                    if ($rc->isAbstract() || $rc->isInterface()) {
                        continue;
                    }
                    if (!$rc->implementsInterface($interface)) {
                        continue;
                    }
                    if (!$rc->hasMethod('getKey') || !$rc->hasMethod('getName')) {
                        continue;
                    }
                    try {
                        $key = (string) $class::getKey();
                        if ($key === '') {
                            continue;
                        }
                    } catch (\Throwable $e) {
                        continue;
                    }
                    if (isset($byKey[$key])) {
                        continue;
                    }
                    try {
                        $available = (bool) $class::isAvailable();
                    } catch (\Throwable $e) {
                        $available = false;
                    }
                    $reason = '';
                    if (!$available) {
                        if ($rc->hasMethod('unavailabilityReason')) {
                            try {
                                $reason = (string) $class::unavailabilityReason();
                            } catch (\Throwable $e) {
                                $reason = $e->getMessage();
                            }
                        }
                        if ($reason === '') {
                            $reason = 'not available on this installation';
                        }
                    }
                    $byKey[$key] = [
                        'name'      => (string) $class::getName(),
                        'available' => $available,
                        'reason'    => $reason,
                    ];
                }
            }
        }
        uasort($byKey, fn($a, $b) => strcasecmp($a['name'], $b['name']));
        return self::$classCache[$cacheKey] = $byKey;
    }

    /** Drop the cache; useful in tests. */
    public static function flushCache(): void
    {
        self::$cache = [];
        self::$classCache = [];
    }
}
