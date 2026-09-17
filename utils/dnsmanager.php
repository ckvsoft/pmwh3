<?php

namespace pmwh3\Utils;

use ckvsoft\CkvException;
use pmwh3\Utils\AdapterRegistry;
use pmwh3\Utils\Dns\DnsAdapterInterface;

/**
 * Single entry point for DNS operations from the rest of the module.
 * Resolves the configured adapter (DNS_TYPE setting) and delegates.
 *
 * If no adapter is configured or the configured one returns false from
 * isAvailable(), all write operations silently no-op and the caller
 * gets a 0/false return -- DomainManager etc. treat that as "DNS
 * automation skipped" and continue.
 */
class DnsManager
{

    /**
     * Get the adapter FQCN. Returns null if no DNS adapter is
     * configured/available (instead of throwing) so callers can
     * check once and skip cleanly.
     *
     * Resolution via AdapterRegistry (keys discovered from
     * utils/dns/*) -- NOT via a class-name convention. A configured
     * DNS_TYPE without a matching available adapter resolves to null.
     */
    public static function getAdapterClass(): ?string
    {
        static $classCache = [];
        $type = \pmwh3\Config\LazyConfig::get('DNS_TYPE', '');
        if ($type === '' || strtolower($type) === 'none') {
            return null;
        }
        $type = strtolower($type);
        if (isset($classCache[$type])) {
            return $classCache[$type];
        }
        $classCache[$type] = AdapterRegistry::classes(
                        __DIR__ . '/dns',
                        DnsAdapterInterface::class
                )[$type] ?? null;
        return $classCache[$type];
    }

    /**
     * Capability check against the configured adapter. Returns false
     * when no adapter is configured (callers treat as "automation
     * skipped"), true only for capabilities the adapter declares.
     *
     * @param string $cap 'zone-write' | 'record-write' | 'record-manage' | 'dnssec'
     */
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

    // ===== Reads =========================================================

    public static function getIpByDnsRecordLookup(string $domain, string $search): string
    {
        $cls = self::getAdapterClass();
        if ($cls === null) {
            return '';
        }
        $cls::initDb();

        $ip = '';
        $currentSearch = $search;

        while ($currentSearch) {
            $result = $cls::lookupRecord($domain, $currentSearch);
            if ($result === null) {
                break;
            }
            // Unified result shape: 'content' ('data' tolerated as a
            // read-time legacy alias).
            $ip   = $result['content'] ?? $result['data'] ?? '';
            $type = $result['type'];

            if ($type === 'A') {
                return $ip;
            }
            if ($type === 'CNAME') {
                if ($currentSearch === $ip) {
                    throw new CkvException("DNS CNAME loop detected for {$currentSearch}.{$domain}");
                }
                $currentSearch = $ip;
            } else {
                break;
            }
        }
        return $ip;
    }

    public static function zoneExists(string $domain): bool
    {
        $cls = self::getAdapterClass();
        if ($cls === null) {
            return false;
        }
        $cls::initDb();
        return $cls::zoneExists($domain);
    }

    public static function listRecords(string $domain, ?string $type = null): array
    {
        $cls = self::getAdapterClass();
        if ($cls === null) {
            return [];
        }
        $cls::initDb();
        return $cls::listRecords($domain, $type);
    }

    public static function getSoa(string $domain): ?array
    {
        $cls = self::getAdapterClass();
        if ($cls === null) {
            return null;
        }
        $cls::initDb();
        return $cls::getSoa($domain);
    }

    public static function listZones(): array
    {
        $cls = self::getAdapterClass();
        if ($cls === null) {
            return [];
        }
        $cls::initDb();
        return $cls::listZones();
    }

    public static function updateSoa(string $domain, array $timers): bool
    {
        $cls = self::getAdapterClass();
        if ($cls === null) {
            return false;
        }
        $cls::initDb();
        return $cls::updateSoa($domain, $timers);
    }

    public static function bumpSerial(string $domain): int
    {
        $cls = self::getAdapterClass();
        if ($cls === null) {
            return 0;
        }
        $cls::initDb();
        return $cls::bumpSerial($domain);
    }

    // ===== Writes ========================================================

    public static function createZone(
            string $domain,
            string $masterIp,
            string $admin,
            array $extra = []
    ): int {
        // Merge the configured SOA timers / default TTL into $extra so
        // the adapters render them instead of their hardcoded values.
        // The schema defaults (DNS_REFRESH=10800, DNS_RETRY=3600,
        // DNS_EXPIRE=604800, DNS_MINIMUM=3600, DNS_TTL=3600) match the
        // values the adapters used to hardcode, so this is transparent
        // on fresh installs unless an admin changed a setting.
        $timers = [
            'refresh' => (int) \pmwh3\Config\LazyConfig::get('DNS_REFRESH', 10800),
            'retry'   => (int) \pmwh3\Config\LazyConfig::get('DNS_RETRY', 3600),
            'expire'  => (int) \pmwh3\Config\LazyConfig::get('DNS_EXPIRE', 604800),
            'minimum' => (int) \pmwh3\Config\LazyConfig::get('DNS_MINIMUM', 3600),
            'ttl'     => (int) \pmwh3\Config\LazyConfig::get('DNS_TTL', 3600),
        ];
        $extra = array_merge($timers, $extra);

        $cls = self::getAdapterClass();
        if ($cls === null) {
            return 0;
        }
        $cls::initDb();
        return $cls::createZone($domain, $masterIp, $admin, $extra);
    }

    public static function deleteZone(string $domain): bool
    {
        $cls = self::getAdapterClass();
        if ($cls === null) {
            return true;
        }
        $cls::initDb();
        return $cls::deleteZone($domain);
    }

    public static function addRecord(
            string $domain,
            string $name,
            string $type,
            string $content,
            int $ttl = 3600,
            int $prio = 0
    ): int {
        $cls = self::getAdapterClass();
        if ($cls === null) {
            return 0;
        }
        $cls::initDb();
        return $cls::addRecord($domain, $name, $type, $content, $ttl, $prio);
    }

    public static function updateRecord(int $recordId, array $fields): bool
    {
        $cls = self::getAdapterClass();
        if ($cls === null) {
            return false;
        }
        $cls::initDb();
        return $cls::updateRecord($recordId, $fields);
    }

    public static function deleteRecord(int $recordId): bool
    {
        $cls = self::getAdapterClass();
        if ($cls === null) {
            return false;
        }
        $cls::initDb();
        return $cls::deleteRecord($recordId);
    }

    // ===== DNSSEC ========================================================

    public static function dnssecAvailable(): bool
    {
        $cls = self::getAdapterClass();
        if ($cls === null) {
            return false;
        }
        return $cls::dnssecAvailable();
    }

    public static function getDnssecStatus(string $domain): ?array
    {
        $cls = self::getAdapterClass();
        if ($cls === null) {
            return null;
        }
        $cls::initDb();
        return $cls::getDnssecStatus($domain);
    }

    public static function listKeys(string $domain): array
    {
        $cls = self::getAdapterClass();
        if ($cls === null) {
            return [];
        }
        $cls::initDb();
        return $cls::listKeys($domain);
    }

    public static function listMetadata(string $domain): array
    {
        $cls = self::getAdapterClass();
        if ($cls === null) {
            return [];
        }
        $cls::initDb();
        return $cls::listMetadata($domain);
    }

    public static function setKeyActive(int $keyId, bool $active): bool
    {
        $cls = self::getAdapterClass();
        if ($cls === null) {
            return false;
        }
        $cls::initDb();
        return $cls::setKeyActive($keyId, $active);
    }

    public static function deleteKey(int $keyId): bool
    {
        $cls = self::getAdapterClass();
        if ($cls === null) {
            return false;
        }
        $cls::initDb();
        return $cls::deleteKey($keyId);
    }

    public static function secureZone(string $domain): array
    {
        $cls = self::getAdapterClass();
        if ($cls === null) {
            return ['ok' => false, 'error' => 'no DNS adapter configured'];
        }
        return $cls::secureZone($domain);
    }

    public static function disableDnssec(string $domain): array
    {
        $cls = self::getAdapterClass();
        if ($cls === null) {
            return ['ok' => false, 'error' => 'no DNS adapter configured'];
        }
        return $cls::disableDnssec($domain);
    }
}
