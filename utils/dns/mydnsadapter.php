<?php

// pmwh3/Utils/Dns/MyDnsAdapter.php

namespace pmwh3\Utils\Dns;

use ckvsoft\mvc\Config;

/**
 * MyDNS backend adapter (mydns_soa / mydns_rr). Full write support:
 * some customer installations still run MyDNS as their authoritative
 * backend, so this adapter must expose the same surface as the
 * PowerDNS adapter -- zones, records and SOA serial management.
 *
 * Per capability API (DnsManager::supports()):
 *   'zone-write', 'record-write', 'record-manage'
 *   -- NO 'dnssec' (MyDNS has no DNSSEC concept; the AbstractDnsAdapter
 *   defaults make the whole DNSSEC tab a no-op for this backend).
 *
 * Schema notes (legacy, PMWH2-compatible):
 *   mydns_soa: id, origin (FQDN with trailing dot, UNIQUE), ns, mbox,
 *              serial, refresh, retry, expire, minimum, ttl, xfer
 *   mydns_rr:  id, zone, name, type ENUM, data, aux (="prio"), ttl
 *   Record names are RELATIVE to the origin ("www", "mail"); the apex
 *   may appear as the FQDN WITHOUT trailing dot ("example.com").
 *   Types available: A, AAAA, ALIAS, CNAME, HINFO, MX, NS, PTR, RP,
 *   SRV, TXT -- no SOA (that lives in mydns_soa).
 *
 * The table prefix resolution follows the AbstractDnsAdapter config
 * ('dns.table_prefix'), defaulting to the raw mydns_* names.
 *
 * MyDNS reads SOA/RR directly from MySQL on every query, so there is
 * no server-side cache to flush and the serial bump keeps secondaries
 * in sync (integer serial YYYYMMDDxx).
 */
class MyDnsAdapter extends AbstractDnsAdapter
{

    /** Record types the legacy mydns_rr ENUM accepts. */
    private static array $allowedTypes = [
        'A', 'AAAA', 'ALIAS', 'CNAME', 'HINFO', 'MX', 'NS', 'PTR', 'RP', 'SRV', 'TXT',
    ];

    /** Cached table names. */
    private static array $tableCache = [];

    public static function getKey(): string  { return 'mydns'; }
    public static function getName(): string { return 'MyDNS'; }

    /**
     * The MyDNS adapter needs the mydns_soa AND mydns_rr tables on the
     * resolved dns.database connection.
     */
    public static function isAvailable(): bool
    {
        try {
            self::initDb();
            return self::tableExists('soa') && self::tableExists('rr');
        } catch (\Throwable $e) {
            return false;
        }
    }


    /**
     * Hard guard: mydns_soa + mydns_rr must exist on the resolved
     * dns.database connection. The tables belong to the MyDNS server
     * installation; pmwh3 never creates them.
     */
    public static function requiredTables(): array
    {
        return [self::t('soa'), self::t('rr')];
    }

    private static function requireMydnsTables(): void
    {
        self::requireTables([self::t('soa'), self::t('rr')], 'MyDNS');
    }

    /**
     * Full record management (add/update/delete + zone lifecycle).
     */
    public static function capabilities(): array
    {
        return ['zone-write', 'record-write', 'record-manage'];
    }

    /**
     * Resolve the real table name. Honours dns.table_prefix, falls
     * back to the canonical mydns_<base> names if the configured
     * prefix does not exist (safe switching without breaking).
     */
    private static function t(string $base): string
    {
        if (isset(self::$tableCache[$base])) {
            return self::$tableCache[$base];
        }
        $prefix = self::tablePrefix();
        $first  = $prefix . ($base === 'soa' ? 'mydns_soa' : 'mydns_rr');
        $alt    = ($base === 'soa') ? 'mydns_soa' : 'mydns_rr';

        foreach ([$first, $alt] as $candidate) {
            if (self::tableExistsRaw($candidate)) {
                return self::$tableCache[$base] = $candidate;
            }
        }
        return self::$tableCache[$base] = $first;
    }

    private static function tableExistsRaw(string $tableName): bool
    {
        try {
            return self::getDb()->tableExists($tableName);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function tableExists(string $base): bool
    {
        $prefix = self::tablePrefix();
        $first  = $prefix . ($base === 'soa' ? 'mydns_soa' : 'mydns_rr');
        $alt    = ($base === 'soa') ? 'mydns_soa' : 'mydns_rr';
        return self::tableExistsRaw($first) || self::tableExistsRaw($alt);
    }

    // ===== Reads =========================================================

    /**
     * Looks up a DNS record in MyDNS. Unified result shape
     * ['content' => ..., 'type' => ...] (DnsManager tolerates the
     * legacy 'data' key, adapters produce 'content').
     */
    public static function lookupRecord(string $domain, string $search): ?array
    {
        self::requireMydnsTables();
        self::initDb();
        $S = self::t('soa');
        $R = self::t('rr');

        $origin  = self::normalizeName($domain) . '.';   // MyDNS origin expects trailing dot
        $nameRel = $search;
        if (str_ends_with($nameRel, '.' . self::normalizeName($domain))) {
            $nameRel = substr($nameRel, 0, -strlen('.' . self::normalizeName($domain)));
        }

        $query = "
            SELECT r.data AS content, r.type
              FROM `{$R}` r
              JOIN `{$S}` a ON r.zone = a.id
             WHERE a.origin = :origin
               AND (r.name = :rel OR r.name = :fqdn)
               AND r.type IN ('A','CNAME')
             ORDER BY r.type DESC
             LIMIT 1
        ";
        return self::getDb()->selectOne($query, [
                    'origin' => $origin,
                    'rel'    => $nameRel,
                    'fqdn'   => self::normalizeName($search),
                ]);
    }

    public static function zoneExists(string $domain): bool
    {
        self::requireMydnsTables();
        self::initDb();
        $r = self::getDb()->selectOne(
                "SELECT id FROM `" . self::t('soa') . "` WHERE origin = :o LIMIT 1",
                ['o' => self::normalizeName($domain) . '.']
        );
        return !empty($r);
    }

    public static function listRecords(string $domain, ?string $type = null): array
    {
        self::requireMydnsTables();
        self::initDb();
        $zone = self::getZoneRow($domain);
        if (!$zone) {
            return [];
        }
        $R = self::t('rr');
        $sql = "SELECT id, name, type, data AS content, aux AS prio, ttl
                  FROM `{$R}`
                 WHERE zone = :z";
        $bind = ['z' => (int) $zone['id']];
        if ($type !== null && $type !== '') {
            $sql .= " AND type = :t";
            $bind['t'] = strtoupper($type);
        }
        $sql .= " ORDER BY
                    CASE type
                        WHEN 'NS'  THEN 2 WHEN 'MX'  THEN 3 WHEN 'A'   THEN 4
                        WHEN 'AAAA'THEN 5 WHEN 'CNAME' THEN 6 ELSE 99
                    END, name, aux";
        // NO SOA rows (SOA lives in mydns_soa); selected via getSoa().
        return self::getDb()->select($sql, $bind);
    }

    /**
     * Builds the SOA view from mydns_soa (SOA is NOT an RR row in
     * MyDNS). Same result shape as the PowerDNS adapter:
     * id, name, primary, admin, serial, refresh, retry, expire,
     * negttl, ttl.
     */
    public static function getSoa(string $domain): ?array
    {
        self::requireMydnsTables();
        self::initDb();
        $S  = self::t('soa');
        $r = self::getDb()->selectOne(
                "SELECT * FROM `{$S}` WHERE origin = :o LIMIT 1",
                ['o' => self::normalizeName($domain) . '.']
        );
        if (!$r) {
            return null;
        }
        return [
            'id'      => (int) $r['id'],
            'name'    => self::normalizeName($domain),
            'primary' => (string) ($r['ns'] ?? ''),
            'admin'   => (string) ($r['mbox'] ?? ''),
            'serial'  => (int)   ($r['serial'] ?? 0),
            'refresh' => (int)   ($r['refresh'] ?? 28800),
            'retry'   => (int)   ($r['retry']  ?? 7200),
            'expire'  => (int)   ($r['expire'] ?? 604800),
            'negttl'  => (int)   ($r['minimum'] ?? 86400),
            'ttl'     => (int)   ($r['ttl']    ?? 86400),
        ];
    }

    // ===== Writes ========================================================

    public static function createZone(
            string $domain,
            string $masterIp,
            string $admin,
            array $extra = []
    ): int {
        self::requireMydnsTables();
        self::initDb();
        $domain = self::normalizeName($domain);
        $S = self::t('soa');

        $existing = self::$db->selectOne(
                "SELECT id FROM `{$S}` WHERE origin = :o LIMIT 1",
                ['o' => $domain . '.']
        );
        if ($existing) {
            return (int) $existing['id'];
        }

        $ttl      = (int) ($extra['ttl'] ?? 3600);
        $refresh  = (int) ($extra['refresh'] ?? 10800);
        $retry    = (int) ($extra['retry'] ?? 3600);
        $expire   = (int) ($extra['expire'] ?? 604800);
        $minimum  = (int) ($extra['minimum'] ?? 3600);
        $ns  = (array) ($extra['ns'] ?? ["ns1.{$domain}", "ns2.{$domain}"]);
        $mx  = (string) ($extra['mx'] ?? "mail.{$domain}");
        $mxPrio = (int) ($extra['mx_prio'] ?? 10);

        $nsMain   = (string) ($ns[0] ?? "ns1.{$domain}");
        $nsSecond = (string) ($ns[1] ?? "ns2.{$domain}");
        $serial   = (int) (date('Ymd') . '01');
        // MyDNS mbox convention: dots instead of @ (RFC2142/hostmaster.ckvsoft.at.)
        $mbox = str_replace('@', '.', $admin);
        if (!str_ends_with($mbox, '.')) {
            $mbox .= '.';
        }

        self::$db->insert($S, [
            'origin'  => $domain . '.',
            'ns'      => $nsMain . '.',
            'mbox'    => $mbox,
            'serial'  => $serial,
            'refresh' => $refresh,
            'retry'   => $retry,
            'expire'  => $expire,
            'minimum' => $minimum,
            'ttl'     => $ttl,
            'xfer'    => '',
        ]);
        $zoneId = (int) self::$db->id();
        if ($zoneId <= 0) {
            return 0;
        }

        // Default records, pmwh3-same set as the PowerDNS adapter
        // (SOA/NS x2, apex A, www CNAME, MX).
        self::insertRr($zoneId, $domain,      'NS',    $nsMain . '.',    $ttl, 0);
        self::insertRr($zoneId, $domain,      'NS',    $nsSecond . '.',  $ttl, 0);
        if ($masterIp !== '') {
            self::insertRr($zoneId, $domain,  'A',     $masterIp, $ttl, 0);
            self::insertRr($zoneId, "www.{$domain}", 'CNAME', $domain, $ttl, 0);
        }
        if ($mx !== '') {
            self::insertRr($zoneId, $domain,  'MX',    $mx, $ttl, $mxPrio);
        }
        return $zoneId;
    }

    public static function deleteZone(string $domain): bool
    {
        self::requireMydnsTables();
        self::initDb();
        $zone = self::getZoneRow($domain);
        if (!$zone) {
            return true; // idempotent
        }
        $S = self::t('soa');
        $R = self::t('rr');
        $id = (int) $zone['id'];
        self::$db->delete($R, 'zone = :z', ['z' => $id]);
        self::$db->delete($S, 'id = :i',   ['i' => $id]);
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
        self::requireMydnsTables();
        self::initDb();
        $zone = self::getZoneRow($domain);
        if (!$zone) {
            return 0;
        }
        $id = self::insertRr((int) $zone['id'], $name, $type, $content, $ttl, $prio);
        if ($id > 0) {
            self::bumpSerial($domain);
        }
        return $id;
    }

    public static function updateRecord(int $recordId, array $fields): bool
    {
        self::requireMydnsTables();
        self::initDb();
        $R = self::t('rr');
        $update = [];
        foreach (['name', 'type', 'content', 'ttl', 'prio'] as $f) {
            if (array_key_exists($f, $fields)) {
                $update[$f] = ($f === 'ttl' || $f === 'prio')
                        ? (int) $fields[$f]
                        : (string) $fields[$f];
            }
        }
        if (empty($update)) {
            return false;
        }
        // Map the unified field names onto the mydns_rr columns.
        $data = [];
        foreach ($update as $f => $v) {
            $data[$f === 'prio' ? 'aux' : ($f === 'content' ? 'data' : $f)] = $v;
        }
        // 'type' is an ENUM column; reject unsupported types BEFORE the
        // write so MySQL's silent-invalid-enum fallback can't kick in.
        if (isset($data['type'])
                && !in_array(strtoupper($data['type']), self::$allowedTypes, true)) {
            return false;
        }
        $zoneId = self::rrZone($recordId);
        self::$db->update($R, $data, 'id = :i', ['i' => $recordId]);
        if ($zoneId) {
            self::bumpSerialByZone($zoneId);
        }
        return true;
    }

    public static function deleteRecord(int $recordId): bool
    {
        self::requireMydnsTables();
        self::initDb();
        $R = self::t('rr');
        $zoneId = self::rrZone($recordId);
        self::$db->delete($R, 'id = :i', ['i' => $recordId]);
        if ($zoneId) {
            self::bumpSerialByZone($zoneId);
        }
        return true;
    }

    public static function bumpSerial(string $domain): int
    {
        self::requireMydnsTables();
        self::initDb();
        $S = self::t('soa');
        $soa = self::$db->selectOne(
                "SELECT id, serial FROM `{$S}` WHERE origin = :o LIMIT 1",
                ['o' => self::normalizeName($domain) . '.']
        );
        if (!$soa) {
            return 0;
        }
        $newSerial = self::nextSerial((int) $soa['serial']);
        self::$db->update($S, ['serial' => $newSerial], 'id = :i', ['i' => (int) $soa['id']]);
        return $newSerial;
    }

    /**
     * Update SOA timer columns of an existing zone. The serial is left
     * alone (callers bump it explicitly afterwards).
     */
    public static function updateSoa(string $domain, array $timers): bool
    {
        self::requireMydnsTables();
        self::initDb();
        $soa = self::getSoa($domain);
        if (!$soa) {
            return false;
        }
        $S = self::t('soa');
        $data = [];
        foreach (['refresh', 'retry', 'expire', 'minimum', 'ttl'] as $f) {
            if (array_key_exists($f, $timers)) {
                $data[$f] = (int) $timers[$f];
            }
        }
        if (empty($data)) {
            return false;
        }
        self::$db->update($S, $data, 'id = :i', ['i' => (int) $soa['id']]);
        return true;
    }

    /**
     * All zone names on this backend (from mydns_soa), without trailing dot.
     */
    public static function listZones(): array
    {
        self::requireMydnsTables();
        self::initDb();
        $S = self::t('soa');
        $rows = self::$db->select("SELECT origin FROM `{$S}` ORDER BY origin", []);
        $out = [];
        foreach ($rows as $r) {
            $out[] = self::normalizeName((string) ($r['origin'] ?? ''));
        }
        return $out;
    }

    // ===== Internals =====================================================

    private static function getZoneRow(string $domain): ?array
    {
        self::initDb();
        $S = self::t('soa');
        $r = self::$db->selectOne(
                "SELECT id, origin FROM `{$S}` WHERE origin = :o LIMIT 1",
                ['o' => self::normalizeName($domain) . '.']
        );
        return $r ?: null;
    }

    private static function rrZone(int $rrId): ?int
    {
        self::initDb();
        $R = self::t('rr');
        $r = self::$db->selectOne(
                "SELECT zone FROM `{$R}` WHERE id = :i",
                ['i' => $rrId]
        );
        return $r ? (int) $r['zone'] : null;
    }

    private static function insertRr(
            int $zoneId,
            string $name,
            string $type,
            string $content,
            int $ttl,
            int $prio
    ): int {
        self::initDb();
        if (!in_array(strtoupper($type), self::$allowedTypes, true)) {
            return 0;
        }
        $R = self::t('rr');
        $origin = self::$db->selectOne(
                "SELECT origin FROM `" . self::t('soa') . "` WHERE id = :i LIMIT 1",
                ['i' => $zoneId]
        );
        $zoneOrigin = isset($origin['origin'])
                ? self::normalizeName($origin['origin'])
                : '';

        $name = self::normalizeName($name);
        if ($name === $zoneOrigin) {
            // apex: keep the FQDN-without-dot convention of mydns
        } elseif ($zoneOrigin !== '' && str_ends_with($name, '.' . $zoneOrigin)) {
            $name = substr($name, 0, -strlen('.' . $zoneOrigin));
        }

        self::$db->insert($R, [
            'zone' => $zoneId,
            'name' => $name,
            'type' => strtoupper($type),
            'data' => (string) $content,
            'aux'  => $prio,
            'ttl'  => $ttl,
        ]);
        $row = self::$db->selectOne(
                "SELECT id FROM `{$R}`
                 WHERE zone = :z AND name = :n AND type = :t AND data = :c
                 ORDER BY id DESC LIMIT 1",
                ['z' => $zoneId, 'n' => $name, 't' => strtoupper($type), 'c' => (string) $content]
        );
        return (int) ($row['id'] ?? 0);
    }

    private static function bumpSerialByZone(int $zoneId): int
    {
        self::initDb();
        $S = self::t('soa');
        $soa = self::$db->selectOne(
                "SELECT id, serial FROM `{$S}` WHERE id = :i LIMIT 1",
                ['i' => $zoneId]
        );
        if (!$soa) {
            return 0;
        }
        $newSerial = self::nextSerial((int) $soa['serial']);
        self::$db->update($S, ['serial' => $newSerial], 'id = :i', ['i' => $zoneId]);
        return $newSerial;
    }

    /** Integer serial YYYYMMDDxx (same policy as PowerDNS adapter). */
    private static function nextSerial(int $old): int
    {
        $today = date('Ymd');
        if (strlen((string) $old) === 10 && str_starts_with((string) $old, $today)) {
            return $old + 1;
        }
        return (int) ($today . '01');
    }
}
