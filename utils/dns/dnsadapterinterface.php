<?php

namespace pmwh3\Utils\Dns;

interface DnsAdapterInterface
{

    // ===== Discovery (for AdapterRegistry / option_providers) ===========

    /** Short identifier persisted in the DNS_TYPE setting. */
    public static function getKey(): string;

    /** Human-readable name shown in the options dropdown. */
    public static function getName(): string;

    /**
     * Runtime availability check. Returns false if the adapter can't
     * function on this installation (missing tables, missing PHP
     * extensions, etc.). Adapters returning false are filtered out
     * of the dropdown.
     */
    public static function isAvailable(): bool;

    /**
     * Capability flags of THIS adapter. DnsManager::supports() and
     * the UI consult this instead of trying writes and reading a
     * silent no-op result. Clients can hide unrelated UI (e.g. the
     * DNSSEC tab when 'dnssec' is absent) or warn explicitly when an
     * automation can't be executed.
     *
     * Known keys:
     *   'zone-write'     createZone/deleteZone supported (returns a real id)
     *   'record-write'   add/update/deleteRecord supported
     *   'record-manage'  full record CRUD incl. listRecords/getSoa editing
     *   'dnssec'         DNSSEC tab usable
     *   'api'            external API client available (cache flush, DS fetch, ...)
     * Adapters return ONLY keys they really support (an empty array
     * means read-only presence checking).
     */
    public static function capabilities(): array;

    // ===== Read operations =============================================

    /**
     * Look up a single A/CNAME record. Used by domain-IP lookups
     * (quick check: is this domain pointing to our server?).
     *
     * @param string $domain Top-level domain (e.g., 'example.com').
     * @param string $search The record name (e.g., 'www' or full FQDN).
     * @return array|null ['content' => 'IP/CNAME', 'type' => 'A/CNAME'] or null.
     *   ('content' is the unified result shape; 'data' is accepted by
     *   DnsManager as a read-time legacy alias.)
     */
    public static function lookupRecord(string $domain, string $search): ?array;

    /**
     * Returns true if this DNS adapter has a zone configured for the
     * given domain. Used by DomainManager to decide whether to call
     * createZone() (no zone) or skip (already there) on domain insert.
     */
    public static function zoneExists(string $domain): bool;

    /**
     * Returns all DNS records of a zone, optionally filtered by type.
     * Used by the admin DNS-tab in the domain details view.
     *
     * @param string      $domain Zone name.
     * @param string|null $type   'A','AAAA','MX',... or null for all.
     * @return array<int,array>   Each row at minimum:
     *     id, name, type, content, ttl, prio
     */
    public static function listRecords(string $domain, ?string $type = null): array;

    /**
     * Returns SOA record contents for a zone (or null if no zone).
     */
    public static function getSoa(string $domain): ?array;

    /**
     * Returns all zone names currently configured on this backend.
     * Used by the on-save hooks (SOA timers, nameservers) to make
     * settings changes effective across every existing zone.
     *
     * @return string[] Zone names without trailing dot.
     */
    public static function listZones(): array;

    // ===== Write operations ============================================

    /**
     * Create a new DNS zone with sensible default records:
     *   SOA, NS (x2), A (apex -> $masterIp), MX, www CNAME apex
     * Returns the zone id (adapter-internal) or 0 on failure.
     *
     * @param string $domain    Zone name (without trailing dot).
     * @param string $masterIp  IP for the apex A record.
     * @param string $admin     Admin email contact (e.g., 'hostmaster@example.com').
     * @param array  $extra     Optional overrides:
     *                            'ns'       => ['ns1.example.com', 'ns2.example.com']
     *                            'mx'       => 'mail.example.com'
     *                            'mx_prio'  => 10
     *                            'ttl'      => 3600
     */
    public static function createZone(
            string $domain,
            string $masterIp,
            string $admin,
            array $extra = []
    ): int;

    /**
     * Delete a zone and all its records. Idempotent.
     */
    public static function deleteZone(string $domain): bool;

    /**
     * Add a single record to an existing zone.
     *
     * @param string $domain  Zone name.
     * @param string $name    Record name (full FQDN or short -- adapter normalizes).
     * @param string $type    A, AAAA, MX, TXT, CNAME, NS, SRV, CAA, ...
     * @param string $content Record value.
     * @param int    $ttl     TTL in seconds. 0 = use zone default.
     * @param int    $prio    Priority for MX/SRV records, 0 otherwise.
     * @return int            New record id, 0 on failure.
     */
    public static function addRecord(
            string $domain,
            string $name,
            string $type,
            string $content,
            int $ttl = 3600,
            int $prio = 0
    ): int;

    /**
     * Update a record by its adapter-internal id.
     *
     * @param int   $recordId
     * @param array $fields   Subset of: name, type, content, ttl, prio
     */
    public static function updateRecord(int $recordId, array $fields): bool;

    /**
     * Delete a record by its adapter-internal id.
     */
    public static function deleteRecord(int $recordId): bool;

    /**
     * Bump the SOA serial for the zone (typically called after any
     * record change). Returns the new serial, or 0 if no zone.
     */
    public static function bumpSerial(string $domain): int;

    /**
     * Update the SOA timers of an existing zone without touching the
     * serial (the caller bumps separately if desired).
     *
     * @param string $domain Zone name.
     * @param array  $timers Subset of: refresh, retry, expire, minimum, ttl
     * @return bool          true when the zone existed and was updated.
     */
    public static function updateSoa(string $domain, array $timers): bool;

    // ===== DNSSEC ========================================================
    // Adapters that don't support DNSSEC return the no-op defaults from
    // AbstractDnsAdapter.

    /**
     * Returns true if this adapter has the DNSSEC schema available
     * on its DB connection (cryptokeys / domainmetadata tables).
     * The DNSSEC tab in the details view checks this once before
     * calling any of the methods below.
     */
    public static function dnssecAvailable(): bool;

    /**
     * Returns DNSSEC status for one zone. Shape:
     *   [
     *     'enabled'   => bool,    // any active key present
     *     'nsec3'     => bool,    // NSEC3PARAM metadata present
     *     'presigned' => bool,    // PRESIGNED=1 metadata present
     *     'keys'      => int,     // total key count
     *     'active'    => int,     // active key count
     *   ]
     * Returns null if the zone doesn't exist.
     */
    public static function getDnssecStatus(string $domain): ?array;

    /**
     * Returns all cryptokey rows for a zone. Each row at minimum:
     *   id, flags (256=ZSK, 257=KSK), active (0/1), published (0/1),
     *   content (truncated for display, full key in DB)
     *   tag (optional, computed key tag if adapter can)
     */
    public static function listKeys(string $domain): array;

    /**
     * Returns all domainmetadata rows for a zone. Each row:
     *   id, kind (NSEC3PARAM / PRESIGNED / ALSO-NOTIFY / ...), content
     */
    public static function listMetadata(string $domain): array;

    /**
     * Toggle a key's active flag. PowerDNS picks up this change at
     * its next cache reload (cache-ttl).
     */
    public static function setKeyActive(int $keyId, bool $active): bool;

    /**
     * Delete a key row entirely. Use with care -- if it was the
     * zone's only KSK and the DS is published at the registrar,
     * resolvers will go bogus.
     */
    public static function deleteKey(int $keyId): bool;

    /**
     * Sign / DNSSEC-secure a zone. Whether the adapter accomplishes
     * this via pdnsutil exec, the PowerDNS HTTP API, or some other
     * mechanism is the adapter's business.
     *
     * Returns one of:
     *   ['ok' => true,  'output' => '...']
     *   ['ok' => false, 'error'  => 'reason']
     *
     * Adapters that can't sign return ['ok'=>false,'error'=>'unsupported'].
     */
    public static function secureZone(string $domain): array;

    /**
     * Disable DNSSEC on a zone -- removes all keys, leaves the zone
     * unsigned. Same return shape as secureZone().
     */
    public static function disableDnssec(string $domain): array;
}
