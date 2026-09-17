<?php

namespace pmwh3\Config;

use ckvsoft\mvc\Config;

/**
 * Hooks executed after a configuration value has been saved.
 *
 * In pmwh2 this was a function name string stored in the database
 * column `set_function` and resolved via eval(). In pmwh3 the hook
 * name lives in SettingsSchema as 'on_save' and resolves to a method
 * here -- a stable, lintable, testable target.
 *
 * Each hook receives ($key, $newValue, $oldValue) and returns void.
 * Failures should be logged, not rethrown; saving shouldn't fail
 * just because a downstream side-effect failed.
 */
class OnSaveHooks
{

    /**
     * Dispatch a named hook. Unknown names are silently ignored, so
     * adding 'on_save' to a key without a hook implementation here
     * is safe (just a no-op).
     */
    public static function run(string $name, string $key, $newValue, $oldValue): void
    {
        if (!is_callable([self::class, $name])) {
            return;
        }
        try {
            self::$name($key, $newValue, $oldValue);
        } catch (\Throwable $e) {
            error_log("OnSaveHooks::{$name}({$key}) failed: " . $e->getMessage());
        }
    }

    // -----------------------------------------------------------------
    // Hooks. Names match SettingsSchema['on_save'] entries.
    // -----------------------------------------------------------------

    /** Map from LazyConfig::get fallback defaults (matches SettingsSchema). */
    private static function soaTimers(): array
    {
        return [
            'refresh' => (int) \pmwh3\Config\LazyConfig::get('DNS_REFRESH', 10800),
            'retry'   => (int) \pmwh3\Config\LazyConfig::get('DNS_RETRY', 3600),
            'expire'  => (int) \pmwh3\Config\LazyConfig::get('DNS_EXPIRE', 604800),
            'minimum' => (int) \pmwh3\Config\LazyConfig::get('DNS_MINIMUM', 3600),
            'ttl'     => (int) \pmwh3\Config\LazyConfig::get('DNS_TTL', 3600),
        ];
    }

    /**
     * Called after DNS_SERVERS changed. Replaces the apex NS records
     * on every existing zone so the new server list is reflected.
     *
     * Subdomain delegations (NS records on a non-apex name) are left
     * untouched -- only the zone's own NS set is rewritten.
     */
    public static function updateNameservers(string $key, $newValue, $oldValue): void
    {
        try {
            if (\pmwh3\Utils\DnsManager::getAdapterClass() === null) {
                error_log("DNS_SERVERS changed; no DNS adapter available, nothing rewritten.");
                return;
            }
            $ns = self::nameserverList((string) $newValue);
            if (empty($ns)) {
                error_log("DNS_SERVERS changed to an empty list; no NS rewrite performed.");
                return;
            }
            $zones = \pmwh3\Utils\DnsManager::listZones();
            $rewritten = 0;
            foreach ($zones as $zone) {
                $records = \pmwh3\Utils\DnsManager::listRecords($zone, 'NS');
                $apexMatches = [];
                foreach ($records as $rec) {
                    // Only the apex NS set -- skip delegations.
                    if (strcasecmp((string) $rec['name'], $zone) === 0) {
                        $apexMatches[] = $rec;
                    }
                }
                if ($apexMatches) {
                    foreach ($apexMatches as $rec) {
                        \pmwh3\Utils\DnsManager::deleteRecord((int) $rec['id']);
                    }
                }
                $ttl = (int) (\pmwh3\Config\LazyConfig::get('DNS_TTL', 3600));
                foreach ($ns as $nsHost) {
                    \pmwh3\Utils\DnsManager::addRecord($zone, $zone, 'NS', $nsHost, $ttl, 0);
                }
                \pmwh3\Utils\DnsManager::bumpSerial($zone);
                $rewritten++;
            }
            error_log("DNS_SERVERS changed: rewrote NS on {$rewritten} zone(s).");
        } catch (\Throwable $e) {
            error_log("DNS_SERVERS rewrite failed: " . $e->getMessage());
        }
    }

    /**
     * Called after any SOA timer setting (DNS_REFRESH/RETRY/EXPIRE/
     * MINIMUM/TTL) changed. Applies the new timers to every existing
     * zone and bumps each serial so secondaries pick the change up.
     */
    public static function updateSoaTimers(string $key, $newValue, $oldValue): void
    {
        try {
            if (\pmwh3\Utils\DnsManager::getAdapterClass() === null) {
                error_log("{$key} changed; no DNS adapter available, nothing updated.");
                return;
            }
            $timers = self::soaTimers();
            $zones = \pmwh3\Utils\DnsManager::listZones();
            $updated = 0;
            foreach ($zones as $zone) {
                if (\pmwh3\Utils\DnsManager::updateSoa($zone, $timers)) {
                    \pmwh3\Utils\DnsManager::bumpSerial($zone);
                    $updated++;
                }
            }
            error_log("{$key} changed to '{$newValue}': updated SOA timers on {$updated} zone(s).");
        } catch (\Throwable $e) {
            error_log("{$key} SOA update failed: " . $e->getMessage());
        }
    }

    private static function nameserverList(string $raw): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $out = [];
        foreach ($lines as $l) {
            $l = trim($l);
            if ($l !== '') {
                $out[] = $l;
            }
        }
        return $out;
    }

    public static function updateApacheconfig(string $key, $newValue, $oldValue): void
    {
        // Regenerate vhost skeletons that still carry the OLD default
        // snippet as their custom section (i.e. rows the user never
        // hand-edited). Rows with a hand-maintained custom section are
        // left untouched. `data` blocks without markers (foreign /
        // hand-crafted) are never touched either.
        try {
            if (!\pmwh3\Utils\WebManager::supports('vhost-data')) {
                error_log("APACHECONFIG changed: no vhost-data-capable web adapter, nothing regenerated.");
                return;
            }
            $db = Config::moduleDb();
            $rows = $db->select(
                    "SELECT subdomain FROM pmwh3_web_subdomains
                      WHERE data IS NOT NULL
                        AND data LIKE '%### START CUSTOM ###%'
                        AND data LIKE '%### END CUSTOM ###%'"
            );
            $oldSnippet = trim((string) $oldValue);
            $regenerated = 0;
            foreach ($rows as $row) {
                $fqdn = (string) $row['subdomain'];
                $r = Config::moduleDb()->selectOne(
                        "SELECT data FROM pmwh3_web_subdomains WHERE subdomain = :s",
                        ['s' => $fqdn]
                );
                $custom = \pmwh3\Utils\WebManager::extractCustom((string) ($r['data'] ?? ''));
                if (trim($custom) === $oldSnippet) {
                    if (\pmwh3\Utils\WebManager::regenerateVhostData($fqdn)) {
                        $regenerated++;
                    }
                }
            }
            error_log("APACHECONFIG changed: regenerated {$regenerated} "
                    . "vhost skeleton(s) that used the old default snippet. "
                    . "Apache picks the new vhosts up on its next reload.");
        } catch (\Throwable $e) {
            error_log("APACHECONFIG regeneration failed: " . $e->getMessage());
        }
        // Reload of Apache itself stays out of pmwh3's reach (onsavehooks
        // daemon topic, see roadmap item 9).
    }

    public static function updateHomedir(string $key, $newValue, $oldValue): void
    {
        error_log("HOMEDIR changed from '{$oldValue}' to '{$newValue}'. "
                . "Existing mailboxes are NOT moved automatically.");
    }

    public static function updateMailUid(string $key, $newValue, $oldValue): void
    {
        error_log("MAIL_USER_UID changed; you may need to chown existing maildirs.");
    }

    public static function updateMailGid(string $key, $newValue, $oldValue): void
    {
        error_log("MAIL_USER_GID changed; you may need to chgrp existing maildirs.");
    }

    public static function updateMaildropUsage(string $key, $newValue, $oldValue): void
    {
        error_log("USING_MAILDROP changed; postfix_transport entries for new domains "
                . "will use " . ($newValue === 'Y' ? 'maildrop:' : 'virtual:') . " from now on. "
                . "Existing entries are NOT rewritten automatically.");
    }
}
