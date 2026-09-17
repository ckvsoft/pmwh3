<?php

namespace pmwh3\Utils\Dns;

use ckvsoft\mvc\Config;
use ckvsoft\Database;
use ckvsoft\CkvException;

abstract class AbstractDnsAdapter implements DnsAdapterInterface
{

    /** @var Database|null DB instance for this adapter */
    protected static ?Database $db = null;

    /** @var string|null table prefix from module.json -> dns.table_prefix */
    protected static ?string $tablePrefix = null;

    /**
     * Initialize DB and read prefix from the module.json `dns` block.
     *
     * Both pieces come from the SAME json node. The DB connection is
     * resolved via the framework API (Config::moduleDb with a dotted
     * config path) -- adapters never build their own Database. The
     * module name is detected from the call stack, so this same code
     * works in any module that drops a DNS adapter file.
     */
    public static function initDb(): void
    {
        if (self::$db !== null) {
            return;
        }

        $dns = Config::module('dns');  // backtrace detects the module
        if (!is_array($dns)) {
            $dns = [];
        }

        // Resolve DB: framework connection from the dns.database node.
        // When that node is missing entirely, fall back to the
        // module's own DB (backtrace-based). A node that EXISTS but
        // is incomplete stays a hard error -- silent fallback would
        // break DNS automation invisibly.
        if (isset($dns['database']) && is_array($dns['database'])) {
            foreach (['type', 'host', 'name', 'user', 'pass'] as $f) {
                if (empty($dns['database'][$f])) {
                    throw new CkvException("DNS database config incomplete in module.json (missing '{$f}')");
                }
            }
            self::$db = Config::moduleDb(null, 'dns.database');
        } else {
            self::$db = Config::moduleDb();
        }

        // Cache the configured prefix. Default to '' (= stock
        // PowerDNS schema). The adapter's t() helper still
        // auto-falls-back to `pdns_<base>` if the configured
        // prefix doesn't match the actual schema.
        self::$tablePrefix = isset($dns['table_prefix'])
                ? (string) $dns['table_prefix']
                : '';
    }

    /**
     * @return string The configured table prefix (may be '').
     */
    protected static function tablePrefix(): string
    {
        if (self::$tablePrefix === null) {
            self::initDb();
        }
        return self::$tablePrefix ?? '';
    }

    /**
     * Get the DB instance (for static lookup methods)
     */
    protected static function getDb(): Database
    {
        if (self::$db === null) {
            self::initDb();
        }
        return self::$db;
    }

    /**
     * Verify the adapter's required tables exist on the DNS database
     * connection -- hard and EARLY. The DNS backend schema (pdns_*,
     * mydns_*, ...) belongs to the server installation (pdns/mydns
     * packages), NOT to pmwh3: this code never creates or repairs
     * foreign tables. A missing table means a misconfigured
     * dns.database node or an uninstalled backend -- a clean, named
     * exception beats a raw SQL failure later in the operation.
     *
     * @param string[] $tables resolved table names
     * @param string   $label  adapter display name ('MyDNS', 'PowerDNS')
     * @throws \ckvsoft\CkvException when any table is missing
     */
    protected static function requireTables(array $tables, string $label): void
    {
        $db       = self::getDb();
        $missing  = [];
        foreach ($tables as $t) {
            try {
                if (!$db->tableExists($t)) {
                    $missing[] = $t;
                }
            } catch (\Throwable $e) {
                $missing[] = $t; // treat connection/schema failure as missing too
            }
        }
        if ($missing) {
            throw new CkvException(
                sprintf('%s adapter: table(s) not found on DNS database "%s": %s. '
                    . 'The DNS backend schema is part of the server installation '
                    . '(pdns/mydns), pmwh3 does not create it -- correct the '
                    . 'dns.database node or install the DNS package.',
                    $label, method_exists($db, 'databaseName') ? $db->databaseName() : '?',
                    implode(', ', $missing)));
        }
    }

    /**
     * Compact "why is this adapter unavailable" for option dropdowns
     * ('' == fully available). Discovery keeps unavailable adapters
     * in the list with this reason so the admin can SEE why a backend
     * cannot be selected instead of an empty dropdown.
     */
    public static function unavailabilityReason(): string
    {
        try {
            self::requireTables(static::requiredTables(), static::getName());
            return '';
        } catch (\Throwable $e) {
            // keep it SHORT for the dropdown -- details live in the
            // exception (errorlog) and inside requireTables' message
            $msg = $e->getMessage();
            if (str_contains($msg, 'table(s) not found')
                    && preg_match('/table\(s\) not found on DNS database "([^"]+)": *(.*?)(?:\.|$)/s', $msg, $m)) {
                $tables = trim($m[2]);
                return 'required tables missing on DNS database "' . $m[1] . '": ' . $tables
                    . ' (the DNS schema is part of the server installation)';
            }
            return 'DNS database not reachable: ' . $msg;
        }
    }

    /**
     * Table names this adapter needs on the dns.database connection
     * (resolved names, prefix-aware). Override per adapter.
     */
    public static function requiredTables(): array
    {
        return [];
    }

    /**
     * Normalize domain names (lowercase, trim dots/whitespace)
     */
    protected static function normalizeName(string $name): string
    {
        return strtolower(trim($name, ". \t\n\r\0\x0B"));
    }

    // ===== Default stubs (write methods) =================================
    // Adapters that don't implement these get NOT-IMPLEMENTED
    // behaviour; DnsManager treats those as "DNS automation skipped"
    // and proceeds. Consumers query capabilities() ahead of time.

    abstract public static function lookupRecord(string $domain, string $search): ?array;

    /**
     * Conservative default: an adapter that only overrides
     * lookupRecord/zoneExists etc. reports no write/DNSSEC/api
     * capabilities. Implementations override this.
     */
    public static function capabilities(): array
    {
        return [];
    }

    public static function zoneExists(string $domain): bool             { return false; }
    public static function listRecords(string $domain, ?string $type = null): array { return []; }
    public static function getSoa(string $domain): ?array               { return null; }
    public static function listZones(): array                           { return []; }

    public static function createZone(string $domain, string $masterIp, string $admin, array $extra = []): int
    { return 0; }
    public static function deleteZone(string $domain): bool             { return false; }

    public static function addRecord(string $domain, string $name, string $type, string $content, int $ttl = 3600, int $prio = 0): int
    { return 0; }
    public static function updateRecord(int $recordId, array $fields): bool { return false; }
    public static function deleteRecord(int $recordId): bool            { return false; }

    public static function bumpSerial(string $domain): int              { return 0; }
    public static function updateSoa(string $domain, array $timers): bool { return false; }

    // ===== DNSSEC defaults ===============================================

    public static function dnssecAvailable(): bool                      { return false; }
    public static function getDnssecStatus(string $domain): ?array      { return null; }
    public static function listKeys(string $domain): array              { return []; }
    public static function listMetadata(string $domain): array          { return []; }
    public static function setKeyActive(int $keyId, bool $active): bool { return false; }
    public static function deleteKey(int $keyId): bool                  { return false; }
    public static function secureZone(string $domain): array            { return ['ok' => false, 'error' => 'unsupported']; }
    public static function disableDnssec(string $domain): array         { return ['ok' => false, 'error' => 'unsupported']; }
}
