<?php

// pmwh3/Utils/Dns/PdnsAdapter.php

namespace pmwh3\Utils\Dns;

/**
 * PowerDNS backend adapter.
 *
 * Schema variations:
 *   The default PowerDNS gmysql schema names tables `domains` /
 *   `records` / `cryptokeys` / `domainmetadata`. Some installs
 *   prefix them (e.g. `pdns_domains`) when PDNS shares a database
 *   with another application.
 *
 *   Configurable via module.json under dns.table_prefix:
 *       "dns": {
 *           "type":         "pdns",
 *           "table_prefix": "pdns_",       <-- shared DB
 *           "database":     { ... }
 *       }
 *   or
 *           "table_prefix": "",            <-- standalone pdns
 *
 *   Default is "pdns_" (legacy compat). If the configured prefix
 *   doesn't match the actual DB, the adapter falls back to the
 *   alternate variant gracefully -- so changing the setting is safe.
 *
 * Other conventions:
 *   - Zones we create are type='NATIVE' (PDNS reads MySQL on every
 *     query, no AXFR needed).
 *   - All records have disabled=0, auth=1.
 *   - SOA serial format YYYYMMDDxx.
 */
class PdnsAdapter extends AbstractDnsAdapter
{

    /** Cache for resolved table names: ['domains' => 'pdns_domains', ...]. */
    private static array $tableCache = [];

    public static function getKey(): string  { return 'pdns'; }
    public static function getName(): string { return 'PowerDNS'; }

    /**
     * PowerDNS over the backend tables: zones + records writable.
     * 'dnssec' reflects schema presence (checks cryptokeys/domainmetadata
     * tables at runtime, see dnssecAvailable()).
     */
    public static function capabilities(): array
    {
        $caps = ['zone-write', 'record-write', 'record-manage'];
        if (self::dnssecAvailable()) {
            $caps[] = 'dnssec';
        }
        return $caps;
    }


    /**
     * Hard guard: pdns domains + records must exist on the resolved
     * dns.database connection. The PowerDNS schema belongs to the
     * server installation (pdns package); pmwh3 never creates it.
     */
    public static function requiredTables(): array
    {
        return [self::t('domains'), self::t('records')];
    }

    private static function requirePdnsTables(): void
    {
        self::requireTables([self::t('domains'), self::t('records')], 'PowerDNS');
    }

    public static function isAvailable(): bool
    {
        try {
            self::initDb();
            // Either prefixed or unprefixed `domains` table must exist.
            return self::tableExists('domains');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Resolves the actual table name. The prefix comes from
     * module.json's `dns.table_prefix` (e.g. "pdns_" for installs
     * sharing the DB with another app, "" for stock PowerDNS where
     * tables are named `domains` / `records` / ... directly).
     *
     * If the configured prefix doesn't exist on the actual DB, we
     * fall back to the alternate variant -- this lets you change
     * `dns.table_prefix` in module.json without breaking and
     * gracefully tolerates installs whose schema doesn't match the
     * setting.
     *
     * Cached per request.
     */
    /**
     * Resolves the actual table name. The prefix comes from
     * module.json's `dns.table_prefix` (read once via the parent's
     * tablePrefix()).
     *
     * If the configured prefix doesn't match the actual schema, we
     * fall back to the alternate variant (prefixed <-> unprefixed) --
     * this makes switching the setting safe and tolerates installs
     * whose schema was set up before the setting was added.
     *
     * Cached per request.
     */
    private static function t(string $base): string
    {
        if (isset(self::$tableCache[$base])) {
            return self::$tableCache[$base];
        }
        $prefix = self::tablePrefix();
        $first  = $prefix . $base;
        $alt    = $prefix === '' ? "pdns_{$base}" : $base;

        foreach ([$first, $alt] as $candidate) {
            if (self::tableExistsRaw($candidate)) {
                return self::$tableCache[$base] = $candidate;
            }
        }
        return self::$tableCache[$base] = $first;
    }

    private static function tableExists(string $base): bool
    {
        $prefix = self::tablePrefix();
        return self::tableExistsRaw($prefix . $base)
            || self::tableExistsRaw($prefix === '' ? "pdns_{$base}" : $base);
    }

    private static function tableExistsRaw(string $tableName): bool
    {
        try {
            return self::$db->tableExists($tableName);
        } catch (\Throwable $e) {
            return false;
        }
    }

    // ===== Reads =========================================================

    public static function lookupRecord(string $domain, string $search): ?array
    {
        self::requirePdnsTables();
        self::initDb();
        $D = self::t('domains');
        $R = self::t('records');
        $normalizedDomain = self::normalizeName($domain);
        $searchFull = ($search !== $domain)
                ? $search . "." . $normalizedDomain
                : $normalizedDomain;

        $query = "
            SELECT r.content, r.type
              FROM `{$R}` r
              JOIN `{$D}` d ON r.domain_id = d.id
             WHERE d.name = :origin
               AND r.name = :name
               AND r.type IN ('A','CNAME')
             ORDER BY r.type DESC
             LIMIT 1
        ";
        $r = self::$db->selectOne($query, [
            'origin' => $normalizedDomain,
            'name'   => $searchFull,
        ]);
        if ($r === null) {
            $r = self::$db->selectOne($query, [
                'origin' => $normalizedDomain,
                'name'   => $search,
            ]);
        }
        return $r;
    }

    public static function zoneExists(string $domain): bool
    {
        self::requirePdnsTables();
        self::initDb();
        $D = self::t('domains');
        $r = self::$db->selectOne(
                "SELECT id FROM `{$D}` WHERE name = :n LIMIT 1",
                ['n' => self::normalizeName($domain)]
        );
        return !empty($r);
    }

    public static function listRecords(string $domain, ?string $type = null): array
    {
        self::requirePdnsTables();
        self::initDb();
        $zone = self::getZoneRow($domain);
        if (!$zone) {
            return [];
        }
        $R = self::t('records');
        $sql = "SELECT id, name, type, content, ttl, prio, disabled
                  FROM `{$R}`
                 WHERE domain_id = :d";
        $bind = ['d' => (int) $zone['id']];
        if ($type !== null && $type !== '') {
            $sql .= " AND type = :t";
            $bind['t'] = strtoupper($type);
        }
        $sql .= " ORDER BY
                    CASE type
                        WHEN 'SOA' THEN 1 WHEN 'NS'  THEN 2
                        WHEN 'MX'  THEN 3 WHEN 'A'   THEN 4
                        WHEN 'AAAA'THEN 5 WHEN 'CNAME' THEN 6
                        ELSE 99
                    END, name, prio";
        return self::$db->select($sql, $bind);
    }

    public static function getSoa(string $domain): ?array
    {
        self::requirePdnsTables();
        self::initDb();
        $zone = self::getZoneRow($domain);
        if (!$zone) {
            return null;
        }
        $R = self::t('records');
        $r = self::$db->selectOne(
                "SELECT id, name, content, ttl FROM `{$R}`
                  WHERE domain_id = :d AND type = 'SOA' LIMIT 1",
                ['d' => (int) $zone['id']]
        );
        if (!$r) {
            return null;
        }
        $parts = preg_split('/\s+/', trim((string) $r['content'])) ?: [];
        return [
            'id'      => (int) $r['id'],
            'name'    => (string) $r['name'],
            'primary' => (string) ($parts[0] ?? ''),
            'admin'   => (string) ($parts[1] ?? ''),
            'serial'  => (int)    ($parts[2] ?? 0),
            'refresh' => (int)    ($parts[3] ?? 10800),
            'retry'   => (int)    ($parts[4] ?? 3600),
            'expire'  => (int)    ($parts[5] ?? 604800),
            'negttl'  => (int)    ($parts[6] ?? 3600),
            'ttl'     => (int)    ($r['ttl']  ?? 3600),
        ];
    }

    // ===== Writes ========================================================

    public static function createZone(
            string $domain,
            string $masterIp,
            string $admin,
            array $extra = []
    ): int {
        self::requirePdnsTables();
        self::initDb();
        $domain = self::normalizeName($domain);
        if (self::zoneExists($domain)) {
            $row = self::getZoneRow($domain);
            return (int) ($row['id'] ?? 0);
        }
        $D = self::t('domains');

        $ttl     = (int) ($extra['ttl'] ?? 3600);
        $refresh = (int) ($extra['refresh'] ?? 10800);
        $retry   = (int) ($extra['retry'] ?? 3600);
        $expire  = (int) ($extra['expire'] ?? 604800);
        $negttl  = (int) ($extra['minimum'] ?? 3600);
        $ns  = (array) ($extra['ns'] ?? ["ns1.{$domain}", "ns2.{$domain}"]);
        $mx  = (string) ($extra['mx'] ?? "mail.{$domain}");
        $mxPrio = (int) ($extra['mx_prio'] ?? 10);

        self::$db->insert($D, [
            'name' => $domain,
            'type' => 'NATIVE',
        ]);
        $domainRow = self::$db->selectOne(
                "SELECT id FROM `{$D}` WHERE name = :n LIMIT 1",
                ['n' => $domain]
        );
        $domainId = (int) ($domainRow['id'] ?? 0);
        if ($domainId <= 0) {
            return 0;
        }

        $serial = (int) (date('Ymd') . '01');
        $adminPdns = str_replace('@', '.', $admin);
        $primaryNs = $ns[0] ?? "ns1.{$domain}";
        $soaContent = "{$primaryNs} {$adminPdns} {$serial} {$refresh} {$retry} {$expire} {$negttl}";

        self::insertRecord($domainId, $domain, 'SOA',   $soaContent, $ttl, 0);
        foreach ($ns as $nsHost) {
            self::insertRecord($domainId, $domain, 'NS', $nsHost,    $ttl, 0);
        }
        if ($masterIp !== '') {
            self::insertRecord($domainId, $domain,        'A',     $masterIp, $ttl, 0);
            self::insertRecord($domainId, "www.{$domain}", 'CNAME', $domain,   $ttl, 0);
        }
        if ($mx !== '') {
            self::insertRecord($domainId, $domain, 'MX', $mx, $ttl, $mxPrio);
        }
        self::apiCacheFlush($domain);
        return $domainId;
    }

    public static function deleteZone(string $domain): bool
    {
        self::requirePdnsTables();
        self::initDb();
        $zone = self::getZoneRow($domain);
        if (!$zone) {
            return true;
        }
        $D = self::t('domains');
        $R = self::t('records');
        $id = (int) $zone['id'];
        self::$db->delete($R, 'domain_id = :d', ['d' => $id]);
        self::$db->delete($D, 'id = :i',        ['i' => $id]);
        self::apiCacheFlush($domain);
        return true;
    }

    public static function addRecord(
            string $domain,
            string $name,
            string $type,
            string $content,
            int $ttl = 3600,
            int $prio = 0
    ): int {
        self::requirePdnsTables();
        self::initDb();
        $zone = self::getZoneRow($domain);
        if (!$zone) {
            return 0;
        }
        $id = self::insertRecord((int) $zone['id'], $name, $type, $content, $ttl, $prio);
        if ($id > 0) {
            self::bumpSerial($domain);
        }
        return $id;
    }

    public static function updateRecord(int $recordId, array $fields): bool
    {
        self::requirePdnsTables();
        self::initDb();
        $R = self::t('records');
        $D = self::t('domains');
        $update = [];
        foreach (['name','type','content','ttl','prio'] as $f) {
            if (array_key_exists($f, $fields)) {
                $update[$f] = ($f === 'ttl' || $f === 'prio')
                        ? (int) $fields[$f]
                        : (string) $fields[$f];
            }
        }
        if (empty($update)) {
            return false;
        }
        $row = self::$db->selectOne(
                "SELECT domain_id FROM `{$R}` WHERE id = :i",
                ['i' => $recordId]
        );
        self::$db->update($R, $update, 'id = :i', ['i' => $recordId]);
        if ($row) {
            $zone = self::$db->selectOne(
                    "SELECT name FROM `{$D}` WHERE id = :i",
                    ['i' => (int) $row['domain_id']]
            );
            if ($zone) {
                self::bumpSerial((string) $zone['name']);
            }
        }
        return true;
    }

    public static function deleteRecord(int $recordId): bool
    {
        self::requirePdnsTables();
        self::initDb();
        $R = self::t('records');
        $D = self::t('domains');
        $row = self::$db->selectOne(
                "SELECT domain_id FROM `{$R}` WHERE id = :i",
                ['i' => $recordId]
        );
        self::$db->delete($R, 'id = :i', ['i' => $recordId]);
        if ($row) {
            $zone = self::$db->selectOne(
                    "SELECT name FROM `{$D}` WHERE id = :i",
                    ['i' => (int) $row['domain_id']]
            );
            if ($zone) {
                self::bumpSerial((string) $zone['name']);
            }
        }
        return true;
    }

    public static function bumpSerial(string $domain): int
    {
        self::requirePdnsTables();
        self::initDb();
        $soa = self::getSoa($domain);
        if (!$soa) {
            return 0;
        }
        $R = self::t('records');
        $todayStr = date('Ymd');
        $oldSerial = (string) $soa['serial'];
        if (str_starts_with($oldSerial, $todayStr)) {
            $rev = (int) substr($oldSerial, -2) + 1;
        } else {
            $rev = 1;
        }
        $newSerial = (int) ($todayStr . sprintf('%02d', $rev));

        $newContent = sprintf(
                '%s %s %d %d %d %d %d',
                $soa['primary'], $soa['admin'], $newSerial,
                $soa['refresh'], $soa['retry'], $soa['expire'], $soa['negttl']
        );
        self::$db->update($R, ['content' => $newContent],
                'id = :i', ['i' => (int) $soa['id']]);
        // Drop the cached records for this zone so the change shows
        // up immediately instead of waiting for query-cache-ttl.
        // bumpSerial is called from every record-write path, so
        // this one call covers add/update/delete plus DNSSEC ops.
        self::apiCacheFlush($domain);
        return $newSerial;
    }

    /**
     * Update the SOA record of an existing zone: rebuild the content
     * from the current values plus the passed timer overrides, and
     * apply a ttl override if given. The serial is left alone (callers
     * bump it explicitly afterwards).
     */
    public static function updateSoa(string $domain, array $timers): bool
    {
        self::requirePdnsTables();
        self::initDb();
        $soa = self::getSoa($domain);
        if (!$soa) {
            return false;
        }
        $refresh = (int) ($timers['refresh'] ?? $soa['refresh']);
        $retry   = (int) ($timers['retry']   ?? $soa['retry']);
        $expire  = (int) ($timers['expire']  ?? $soa['expire']);
        $negttl  = (int) ($timers['minimum'] ?? $soa['negttl']);
        $ttl     = array_key_exists('ttl', $timers)
                ? (int) $timers['ttl']
                : (int) $soa['ttl'];

        $newContent = sprintf(
                '%s %s %d %d %d %d %d',
                $soa['primary'], $soa['admin'], $soa['serial'],
                $refresh, $retry, $expire, $negttl
        );
        $R = self::t('records');
        self::$db->update($R, ['content' => $newContent, 'ttl' => $ttl],
                'id = :i', ['i' => (int) $soa['id']]);
        self::apiCacheFlush($domain);
        return true;
    }

    /**
     * All zone names on this backend (from the domains table),
     * without trailing dot.
     */
    public static function listZones(): array
    {
        self::requirePdnsTables();
        self::initDb();
        $D = self::t('domains');
        $rows = self::$db->select(
                "SELECT name FROM `{$D}` ORDER BY name", []
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = self::normalizeName((string) ($r['name'] ?? ''));
        }
        return $out;
    }

    // ===== Internals (records) ===========================================

    private static function getZoneRow(string $domain): ?array
    {
        $D = self::t('domains');
        $row = self::$db->selectOne(
                "SELECT id, name FROM `{$D}` WHERE name = :n LIMIT 1",
                ['n' => self::normalizeName($domain)]
        );
        return $row ?: null;
    }

    private static function insertRecord(
            int $domainId,
            string $name,
            string $type,
            string $content,
            int $ttl,
            int $prio
    ): int {
        $R = self::t('records');
        self::$db->insert($R, [
            'domain_id' => $domainId,
            'name'      => self::normalizeName($name),
            'type'      => strtoupper($type),
            'content'   => $content,
            'ttl'       => $ttl,
            'prio'      => $prio,
            'disabled'  => 0,
            'auth'      => 1,
        ]);
        $r = self::$db->selectOne(
                "SELECT id FROM `{$R}`
                  WHERE domain_id = :d AND name = :n AND type = :t AND content = :c
                  ORDER BY id DESC LIMIT 1",
                ['d' => $domainId, 'n' => self::normalizeName($name),
                 't' => strtoupper($type), 'c' => $content]
        );
        return (int) ($r['id'] ?? 0);
    }

    // ===== DNSSEC ========================================================

    public static function dnssecAvailable(): bool
    {
        try {
            self::initDb();
            return self::tableExists('cryptokeys') && self::tableExists('domainmetadata');
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function getDnssecStatus(string $domain): ?array
    {
        self::requirePdnsTables();
        self::initDb();
        $zone = self::getZoneRow($domain);
        if (!$zone) {
            return null;
        }
        if (!self::dnssecAvailable()) {
            return ['enabled' => false, 'nsec3' => false, 'presigned' => false,
                    'keys' => 0, 'active' => 0];
        }
        $C = self::t('cryptokeys');
        $M = self::t('domainmetadata');

        $total = (int) (self::$db->selectOne(
                "SELECT COUNT(*) AS n FROM `{$C}` WHERE domain_id = :d",
                ['d' => (int) $zone['id']]
        )['n'] ?? 0);
        $active = (int) (self::$db->selectOne(
                "SELECT COUNT(*) AS n FROM `{$C}` WHERE domain_id = :d AND active = 1",
                ['d' => (int) $zone['id']]
        )['n'] ?? 0);
        $nsec3 = (int) (self::$db->selectOne(
                "SELECT COUNT(*) AS n FROM `{$M}`
                  WHERE domain_id = :d AND kind = 'NSEC3PARAM'",
                ['d' => (int) $zone['id']]
        )['n'] ?? 0);
        $presigned = (int) (self::$db->selectOne(
                "SELECT COUNT(*) AS n FROM `{$M}`
                  WHERE domain_id = :d AND kind = 'PRESIGNED' AND content = '1'",
                ['d' => (int) $zone['id']]
        )['n'] ?? 0);

        return [
            'enabled'   => $active > 0,
            'nsec3'     => $nsec3 > 0,
            'presigned' => $presigned > 0,
            'keys'      => $total,
            'active'    => $active,
        ];
    }

    public static function listKeys(string $domain): array
    {
        self::requirePdnsTables();
        self::initDb();
        if (!self::dnssecAvailable()) {
            return [];
        }
        $zone = self::getZoneRow($domain);
        if (!$zone) {
            return [];
        }
        $C = self::t('cryptokeys');
        $rows = self::$db->select(
                "SELECT * FROM `{$C}` WHERE domain_id = :d ORDER BY id",
                ['d' => (int) $zone['id']]
        );
        foreach ($rows as &$r) {
            if (isset($r['content']) && is_string($r['content'])) {
                $c = $r['content'];
                $r['content_short'] = strlen($c) > 80 ? substr($c, 0, 77) . '...' : $c;
            }
            $r['flags']     = (int) ($r['flags']     ?? 0);
            $r['active']    = (int) ($r['active']    ?? 0);
            $r['published'] = (int) ($r['published'] ?? 1);
            $r['role']      = self::keyRole((int) $r['flags']);
            $r['dnskey']    = '';
            $r['ds']        = [];
            $r['algorithm'] = '';
            $r['bits']      = null;
        }
        unset($r);

        // The DB rows only carry the encoded privatekey, not the
        // ready-to-paste DNSKEY/DS strings. Those are computed by
        // the PDNS server itself; we get them via the HTTP API.
        // Without an API, the read-only tab still shows everything
        // we have -- but no DS for the registrar.
        if (self::apiConfigured()) {
            $resp = self::pdnsApi('GET',
                    '/zones/' . self::apiZoneId($domain) . '/cryptokeys');
            if ($resp['status'] >= 200 && $resp['status'] < 300) {
                $apiKeys = json_decode($resp['body'], true);
                if (is_array($apiKeys)) {
                    $byId = [];
                    foreach ($apiKeys as $k) {
                        if (isset($k['id'])) {
                            $byId[(int) $k['id']] = $k;
                        }
                    }
                    foreach ($rows as &$r) {
                        $api = $byId[(int) ($r['id'] ?? 0)] ?? null;
                        if ($api === null) {
                            continue;
                        }
                        $r['dnskey']    = (string) ($api['dnskey']    ?? '');
                        $r['ds']        = (array)  ($api['ds']        ?? []);
                        $r['algorithm'] = (string) ($api['algorithm'] ?? '');
                        $r['bits']      = $api['bits'] ?? null;
                        // The API's keytype is more authoritative
                        // than flags-based detection (csk vs ksk
                        // for flags=257 depends on whether a ZSK
                        // exists too).
                        if (!empty($api['keytype'])) {
                            $r['role'] = strtoupper((string) $api['keytype']);
                        }
                    }
                    unset($r);
                }
            }
        }

        return $rows;
    }

    public static function listMetadata(string $domain): array
    {
        self::requirePdnsTables();
        self::initDb();
        if (!self::dnssecAvailable()) {
            return [];
        }
        $zone = self::getZoneRow($domain);
        if (!$zone) {
            return [];
        }
        $M = self::t('domainmetadata');
        return self::$db->select(
                "SELECT id, kind, content FROM `{$M}`
                  WHERE domain_id = :d ORDER BY kind, id",
                ['d' => (int) $zone['id']]
        );
    }

    public static function setKeyActive(int $keyId, bool $active): bool
    {
        // Direct DB write would leave RRSIGs / NSEC3 chain stale --
        // route through the API so PDNS rectifies the zone.
        $ok = self::apiSetKeyActive($keyId, $active);
        if ($ok) {
            self::initDb();
            $domain = self::keyDomain($keyId);
            if ($domain !== null) {
                self::bumpSerial($domain);
            }
        }
        return $ok;
    }

    public static function deleteKey(int $keyId): bool
    {
        self::initDb();
        // Capture domain BEFORE the delete since the JOIN target
        // disappears with the row.
        $domain = self::keyDomain($keyId);
        $ok = self::apiDeleteKey($keyId);
        if ($ok && $domain !== null) {
            self::bumpSerial($domain);
        }
        return $ok;
    }

    public static function secureZone(string $domain): array
    {
        if (!self::apiConfigured()) {
            return ['ok' => false,
                    'error' => 'PDNS_API_URL not configured'];
        }
        // PUT zones/<zone>  with {"dnssec": true} runs the equivalent
        // of "pdnsutil zone secure" + rectify on the server side.
        // 204 No Content on success.
        $resp = self::pdnsApi('PUT',
                '/zones/' . self::apiZoneId($domain),
                ['dnssec' => true, 'api_rectify' => true]);
        \pmwh3\Utils\ErrorHandler::trace("[PdnsAdapter.secureZone] domain='{$domain}' "
                . "status={$resp['status']} body=" . substr((string) $resp['body'], 0, 500));
        if ($resp['status'] >= 200 && $resp['status'] < 300) {
            // Bump the SOA serial so secondaries pick up the new
            // RRSIGs / NSEC chain. pdns won't do this on its own
            // for the dnssec=true toggle (it's not a record edit
            // from its point of view -- SOA-EDIT-API only kicks in
            // for record-level writes).
            self::initDb();
            $newSerial = self::bumpSerial($domain);
            return ['ok' => true,
                    'output' => 'Zone signed via PDNS API; SOA serial bumped to ' . $newSerial];
        }
        return ['ok' => false,
                'error' => "API returned {$resp['status']}: " . ($resp['body'] ?? '')];
    }

    public static function disableDnssec(string $domain): array
    {
        if (!self::apiConfigured()) {
            return ['ok' => false,
                    'error' => 'PDNS_API_URL not configured'];
        }
        $resp = self::pdnsApi('PUT',
                '/zones/' . self::apiZoneId($domain),
                ['dnssec' => false, 'api_rectify' => true]);
        if ($resp['status'] >= 200 && $resp['status'] < 300) {
            self::initDb();
            $newSerial = self::bumpSerial($domain);
            return ['ok' => true,
                    'output' => 'DNSSEC disabled via PDNS API; SOA serial bumped to ' . $newSerial];
        }
        return ['ok' => false,
                'error' => "API returned {$resp['status']}: " . ($resp['body'] ?? '')];
    }

    // ===== DNSSEC -- per-key writes via API ==============================

    private static function apiSetKeyActive(int $keyId, bool $active): bool
    {
        if (!self::apiConfigured()) {
            return false;
        }
        // Need the domain id -> domain name mapping, then key id.
        $domain = self::keyDomain($keyId);
        if ($domain === null) {
            return false;
        }
        $resp = self::pdnsApi('PUT',
                '/zones/' . self::apiZoneId($domain) . '/cryptokeys/' . $keyId,
                ['active' => $active]);
        return $resp['status'] >= 200 && $resp['status'] < 300;
    }

    private static function apiDeleteKey(int $keyId): bool
    {
        if (!self::apiConfigured()) {
            return false;
        }
        $domain = self::keyDomain($keyId);
        if ($domain === null) {
            return false;
        }
        $resp = self::pdnsApi('DELETE',
                '/zones/' . self::apiZoneId($domain) . '/cryptokeys/' . $keyId);
        return $resp['status'] >= 200 && $resp['status'] < 300;
    }

    /**
     * Look up the zone name a given cryptokeys.id belongs to. The API
     * needs the zone in the URL; we have only the key id here.
     */
    private static function keyDomain(int $keyId): ?string
    {
        $C = self::t('cryptokeys');
        $D = self::t('domains');
        $r = self::$db->selectOne(
                "SELECT d.name FROM `{$C}` c
                  JOIN `{$D}` d ON d.id = c.domain_id
                 WHERE c.id = :i LIMIT 1",
                ['i' => $keyId]
        );
        return $r['name'] ?? null;
    }

    private static function keyRole(int $flags): string
    {
        return match ($flags) {
            257 => 'KSK',
            256 => 'ZSK',
            default => 'CSK/?',
        };
    }

    /**
     * True if the PowerDNS HTTP API has been configured (URL set).
     */
    private static function apiConfigured(): bool
    {
        $url = trim((string) \pmwh3\Config\LazyConfig::get('PDNS_API_URL', ''));
        return $url !== '';
    }

    /**
     * Encode the zone name as the API expects in the URL: trailing
     * dot, all dots inside survive (so example.com. stays
     * example.com.). The trailing dot is the canonical zone id.
     */
    private static function apiZoneId(string $domain): string
    {
        $name = self::normalizeName($domain);
        if (substr($name, -1) !== '.') {
            $name .= '.';
        }
        return rawurlencode($name);
    }

    /**
     * Make an HTTP call to the PowerDNS Authoritative API.
     *
     * Returns ['status' => int, 'body' => string].
     * On a transport-level failure, status is 0 and body is the
     * error message.
     */
    private static function pdnsApi(string $method, string $path, ?array $body = null): array
    {
        $base = rtrim((string) \pmwh3\Config\LazyConfig::get('PDNS_API_URL', ''), '/');
        $key  = (string) \pmwh3\Config\LazyConfig::get('PDNS_API_KEY', '');
        $sid  = (string) \pmwh3\Config\LazyConfig::get('PDNS_API_SERVER_ID', 'localhost');
        if ($base === '') {
            return ['status' => 0, 'body' => 'PDNS_API_URL not configured'];
        }
        $url = $base . '/servers/' . rawurlencode($sid) . $path;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => [
                'X-API-Key: ' . $key,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            return ['status' => 0, 'body' => "curl error: {$err}"];
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => (string) $resp];
    }

    /**
     * Tell PDNS to drop the cached entries for one zone, so the
     * next DNS query for that zone hits the backend and picks up
     * the records we just wrote directly to the DB. Without this,
     * changes can take up to query-cache-ttl seconds (default 20)
     * to become visible -- annoying when a customer just added an
     * A-record and tries to look it up immediately.
     *
     * Silent no-op if the API isn't configured. Best-effort -- a
     * flush failure shouldn't break the write that triggered it.
     */
    private static function apiCacheFlush(string $domain): void
    {
        if (!self::apiConfigured()) {
            return;
        }
        $name = self::normalizeName($domain);
        if (substr($name, -1) !== '.') {
            $name .= '.';
        }
        self::pdnsApi('PUT', '/cache/flush?domain=' . rawurlencode($name));
    }
}
