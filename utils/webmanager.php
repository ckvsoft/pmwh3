<?php

namespace pmwh3\Utils;

use pmwh3\Utils\AdapterRegistry;
use pmwh3\Utils\Web\WebAdapterInterface;

/**
 * Single entry point for web-server operations. Resolves the
 * configured adapter (WEB_TYPE setting, default 'apache') and
 * delegates.
 *
 * If no adapter is configured or the configured one reports
 * isAvailable()=false, callers get null/false -- treat as "web
 * automation skipped" (rows then can't be managed, but DNS-referencing
 * flows still work).
 */
class WebManager
{

    /**
     * Adapter FQCN for the configured WEB_TYPE, or null when web
     * automation is off/no adapter is available.
     */
    public static function getAdapterClass(): ?string
    {
        static $classCache = [];
        $type = \pmwh3\Config\LazyConfig::get('WEB_TYPE', 'apache');
        if ($type === '' || strtolower($type) === 'none') {
            return null;
        }
        $type = strtolower($type);
        if (isset($classCache[$type])) {
            return $classCache[$type];
        }
        $classCache[$type] = AdapterRegistry::classes(
                        __DIR__ . '/web',
                        WebAdapterInterface::class
                )[$type] ?? null;
        return $classCache[$type];
    }

    /** @param string $cap 'subdomain-write' | 'vhost-data' | 'subdomain-list' */
    public static function supports(string $capability): bool
    {
        $cls = self::getAdapterClass();
        if ($cls === null) {
            return false;
        }
        try {
            return in_array($capability, $cls::capabilities(), true);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function isEnabled(): bool
    {
        $cls = self::getAdapterClass();
        if ($cls === null) {
            return false;
        }
        try {
            return $cls::isAvailable();
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function getName(): string
    {
        $cls = self::getAdapterClass();
        if ($cls === null) {
            return 'None';
        }
        try {
            return (string) $cls::getName();
        } catch (\Throwable $e) {
            return 'Unknown';
        }
    }

    // ===== Delegations ==================================================

    private static function call(string $method, ...$args)
    {
        $cls = self::getAdapterClass();
        if ($cls === null) {
            return $method === 'listByDomain' ? [] : null;
        }
        $cls::isAvailable(); // triggers any adapter-side init
        return $cls::$method(...$args);
    }

    public static function rowExists(string $fqdn): bool
    {
        return (bool) self::call('rowExists', $fqdn);
    }

    public static function getRow(string $fqdn): ?array
    {
        return self::call('getRow', $fqdn);
    }

    public static function listByDomain(string $domain): array
    {
        return (array) self::call('listByDomain', $domain);
    }

    public static function createSubdomain(array $row): bool
    {
        return (bool) self::call('createSubdomain', $row);
    }

    public static function updateSubdomain(string $oldFqdn, array $fields): bool
    {
        return (bool) self::call('updateSubdomain', $oldFqdn, $fields);
    }

    public static function deleteSubdomain(string $fqdn): bool
    {
        return (bool) self::call('deleteSubdomain', $fqdn);
    }

    public static function buildVhostData(array $args): string
    {
        return (string) self::call('buildVhostData', $args);
    }

    public static function extractCustom(string $data): string
    {
        return (string) self::call('extractCustom', $data);
    }

    public static function regenerateVhostData(string $fqdn): bool
    {
        return (bool) self::call('regenerateVhostData', $fqdn);
    }

    // ===== certificates (ssl-capable adapters) ==========================

    /** Available certificate basenames for the UI picker. */
    public static function listCerts(): array
    {
        return (array) self::call('listCerts');
    }

    /** Resolve cert basename for $fqdn ('_'-wildcard aware) or null. */
    public static function resolveCert(string $fqdn): ?string
    {
        return self::call('resolveCert', $fqdn);
    }
}
