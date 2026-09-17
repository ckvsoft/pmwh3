<?php

namespace pmwh3\Utils;

use ckvsoft\mvc\Config;
use ckvsoft\CkvException;
use pmwh3\Config\LazyConfig;
use pmwh3\Utils\ServiceDb;

/**
 * Customer CRUD layer.
 *
 * CustomerUtil already covers reads, hierarchy, ACL. This class
 * focuses on writes (create / update / delete) so the same logic
 * can be reused by the controller, by scripts, and (later) by an
 * API.
 *
 * Each public mutator uses Config::moduleDb() and CountingUtil for
 * quotas, and runs cascading deletes via DomainModel /
 * MailManager / FtpManager (when those exist).
 */
class CustomerManager
{

    /** Whitelist of columns we'll accept on insert/update. Anything
     *  else is silently dropped to keep the SQL safe. */
    private const ALLOWED_FIELDS = [
        'customer', 'role_id', 'email', 'realname',
        'customer_number', 'street', 'postcode', 'city', 'country',
        'telephone', 'facsimile',
        'creator', 'php', 'cgi', 'language', 'package',
        'standard_subdomain',
    ];

    /**
     * Returns the row of one customer, or null if not found.
     */
    public static function getById(int $cid): ?array
    {
        $db = Config::moduleDb();
        $row = $db->selectOne(
                "SELECT * FROM pmwh3_customers WHERE cid = :cid",
                ['cid' => $cid]
        );
        return $row ?: null;
    }

    /** Returns a customer row by name, or null. */
    public static function getByName(string $customer): ?array
    {
        $db = Config::moduleDb();
        $row = $db->selectOne(
                "SELECT * FROM pmwh3_customers WHERE customer = :c",
                ['c' => $customer]
        );
        return $row ?: null;
    }

    /**
     * All customers, unscoped. Used by the framework MultiLogin
     * mapping UI which needs to see every customer regardless of
     * pmwh3 hierarchy (the framework user mapping it isn't a pmwh3
     * customer, so hierarchy filtering doesn't apply).
     */
    public static function listAll(string $orderBy = 'customer'): array
    {
        $orderBy = preg_match('/^[a-z_]+$/i', $orderBy) ? $orderBy : 'customer';
        return Config::moduleDb()->select(
                "SELECT * FROM pmwh3_customers ORDER BY {$orderBy}", []
        );
    }

    /**
     * Just the customer login names, filtered to non-empty rows.
     * Cheaper than listAll() when only the name list is needed
     * (owner dropdowns, ownership trees, etc).
     */
    public static function listCustomerNames(): array
    {
        $rows = Config::moduleDb()->select(
                "SELECT customer FROM pmwh3_customers
                  WHERE customer IS NOT NULL AND customer <> ''
                  ORDER BY customer"
        );
        return array_map(fn($r) => (string) $r['customer'], $rows);
    }

    /**
     * All customers + their creator's name resolved via self-join.
     * Used by the customer overview page (admin view) so a column
     * can show "admin" instead of "1" for the creator.
     */
    public static function listAllWithCreator(string $orderBy = 'customer'): array
    {
        $orderBy = preg_match('/^[a-z_]+$/i', $orderBy) ? $orderBy : 'customer';
        return Config::moduleDb()->select(
                "SELECT c.*, p.customer AS creator_name
                   FROM pmwh3_customers c
              LEFT JOIN pmwh3_customers p ON p.cid = c.creator
               ORDER BY c.{$orderBy}", []
        );
    }

    /** Returns the 'max' limits row of a customer (or empty defaults). */
    public static function getLimits(int $cid): array
    {
        $row = self::readMax($cid);
        if (empty($row)) {
            return ['webspace' => 0, 'traffic' => 0, 'domains' => 0,
                    'subdomains' => 0, 'emails' => 0, 'forwards' => 0,
                    'dbases' => 0];
        }
        return $row;
    }

    /**
     * Convenience wrapper used by the Customer controller.
     *
     * @param int  $creatorCid    Whose hierarchy to pull.
     * @param bool $recursive     Full subtree (true) or just direct.
     * @return array              Customer rows, ordered by hierarchy + name.
     */
    public static function getList(int $creatorCid, bool $recursive): array
    {
        return self::listVisible($creatorCid,
                $recursive ? 'all' : 'one',
                false);
    }

    /**
     * Convenience wrapper for the Customer controller.
     * Sets creator from the current session and forwards to create().
     */
    public static function insert(array $fields, array $limits, string $password, int $creatorCid): int
    {
        $fields['creator']  = $creatorCid;
        $fields['password'] = $password;
        try {
            return self::create($fields, $limits);
        } catch (CkvException $e) {
            error_log("CustomerManager::insert failed: " . $e->getMessage());
            return 0;
        }
    }

    /** Change just the password of a customer. */
    public static function changePassword(int $cid, string $password): bool
    {
        if ($password === '') {
            return false;
        }
        if (!PasswordUtil::isValidLength($password)) {
            return false;
        }
        $hashed = \ckvsoft\Hash::create('sha256', $password, HASH_KEY);
        Config::moduleDb()->update('pmwh3_customers',
                ['password' => $hashed],
                'cid = :c', ['c' => $cid]);
        return true;
    }

    /**
     * Verifies a plaintext password against the stored hash for the
     * given customer. Uses the same sha256+HASH_KEY scheme as the
     * login flow (Input::format('hash', ...) in login/submit).
     */
    public static function verifyPassword(int $cid, string $password): bool
    {
        if ($password === '') {
            return false;
        }
        $row = Config::moduleDb()->selectOne(
                "SELECT password FROM pmwh3_customers WHERE cid = :c",
                ['c' => $cid]
        );
        if (!$row) {
            return false;
        }
        $expected = \ckvsoft\Hash::create('sha256', $password, HASH_KEY);
        return hash_equals((string) $row['password'], $expected);
    }

    /**
     * Returns rows of customers visible to $viewer.
     * Mirrors CustomerUtil::getCustomerHierarchy but returns full rows.
     *
     * @param int    $viewerCid       The customer doing the listing.
     * @param string $what            'one' = one level deep, 'all' = full subtree.
     * @param bool   $excludeSelf     Whether to omit $viewerCid in results.
     * @return array<int, array>      Indexed list of customer rows with extra
     *                                'level' key (0 = self).
     */
    public static function listVisible(int $viewerCid, string $what = 'one', bool $excludeSelf = false): array
    {
        $hierarchy = CustomerUtil::getCustomerHierarchy($viewerCid, $what);
        $cids = array_keys($hierarchy);
        if ($excludeSelf) {
            $cids = array_filter($cids, fn($c) => $c !== $viewerCid);
        }
        if (empty($cids)) {
            return [];
        }

        $placeholders = [];
        $bindings = [];
        foreach ($cids as $i => $c) {
            $placeholders[] = ":c{$i}";
            $bindings["c{$i}"] = $c;
        }
        $sql = "SELECT c.*, p.customer AS creator_name
                  FROM pmwh3_customers c
             LEFT JOIN pmwh3_customers p ON p.cid = c.creator
                 WHERE c.cid IN (" . implode(',', $placeholders) . ")
              ORDER BY c.customer";
        $rows = Config::moduleDb()->select($sql, $bindings);

        // Annotate each row with its hierarchy level.
        foreach ($rows as &$row) {
            $row['level'] = $hierarchy[(int) $row['cid']] ?? 0;
        }
        unset($row);

        return $rows;
    }

    /**
     * Whether the given customer name is already taken.
     */
    public static function nameExists(string $customer): bool
    {
        $db = Config::moduleDb();
        $row = $db->selectOne(
                "SELECT cid FROM pmwh3_customers WHERE customer = :c",
                ['c' => $customer]
        );
        return $row !== null;
    }

    /**
     * Creates a customer. Initialises pmwh3_countings rows with the
     * given limits, increments the creator's CUSTOMER_NO counter
     * (LazyConfig key) and returns the new cid.
     *
     * @param array $data     Whitelisted fields (customer, email, ...).
     *                        'password' is treated separately and hashed.
     * @param array $limits   Map of resource => max value (-1 unlimited, 0 disabled).
     * @return int            The new customer's cid.
     * @throws CkvException   On validation failure.
     */
    public static function create(array $data, array $limits = []): int
    {
        if (empty($data['customer'])) {
            throw new CkvException(__("Customer name is required"));
        }
        if (empty($data['password'])) {
            throw new CkvException(__("Password is required"));
        }
        if (!PasswordUtil::isValidLength((string) $data['password'])) {
            throw new CkvException(sprintf(
                    __("Password must be at least %d characters."),
                    PasswordUtil::minLength()));
        }
        if (CustomerUtil::isReservedName((string) $data['customer'])) {
            throw new CkvException(sprintf(__("Customer name '%s' is reserved"), $data['customer']));
        }
        if (self::nameExists($data['customer'])) {
            throw new CkvException(sprintf(__("Customer '%s' already exists"), $data['customer']));
        }

        $row = self::filterFields($data);

        // Default role -> GroupManager's default (used to be the
        // hardcoded gid=2 for Reseller; now resolved by name from
        // the framework `roles` table so it stays right on fresh
        // installs too).
        if (!isset($row['role_id']) || $row['role_id'] === '') {
            $row['role_id'] = GroupManager::getDefaultRoleId();
        }

        // Hash the password the same way login does (sha256 with HASH_KEY).
        $row['password'] = \ckvsoft\Hash::create('sha256', (string) $data['password'], HASH_KEY);

        // Auto-assign customer_number from CUSTOMER_NO if not provided.
        if (empty($row['customer_number'])) {
            $next = (int) LazyConfig::get('CUSTOMER_NO', '1');
            $row['customer_number'] = (string) $next;
            LazyConfig::set('CUSTOMER_NO', (string) ($next + 1));
        }

        $db = Config::moduleDb();
        $cid = $db->insert('pmwh3_customers', $row);
        if (!$cid) {
            throw new CkvException(__("Failed to insert customer"));
        }

        // Counter rows -> seed max/used/granted for every resource.
        self::seedCountings((int) $cid, $limits);

        // Adjust creator's 'granted' counters by the new customer's max.
        $creator = (int) ($row['creator'] ?? 0);
        if ($creator > 0) {
            self::adjustGrantedForCreator($creator, $limits, +1);
        }

        return (int) $cid;
    }

    /**
     * Update customer fields (and optionally limits).
     *
     * @return bool true on success.
     */
    public static function update(int $cid, array $data, ?array $limits = null): bool
    {
        $existing = self::getById($cid);
        if (!$existing) {
            throw new CkvException(sprintf(__("Customer with cid %d not found"), $cid));
        }

        // If renaming, ensure the new name is free.
        if (!empty($data['customer'])
                && $data['customer'] !== $existing['customer']) {
            if (CustomerUtil::isReservedName((string) $data['customer'])) {
                throw new CkvException(sprintf(__("Customer name '%s' is reserved"), $data['customer']));
            }
            if (self::nameExists($data['customer'])) {
                throw new CkvException(sprintf(__("Customer '%s' already exists"), $data['customer']));
            }
        }

        $update = self::filterFields($data);

        if (!empty($data['password'])) {
            $update['password'] = \ckvsoft\Hash::create('sha256', (string) $data['password'], HASH_KEY);
        }

        if (!empty($update)) {
            Config::moduleDb()->update('pmwh3_customers', $update,
                    'cid = :cid', ['cid' => $cid]);
        }

        if ($limits !== null) {
            self::updateLimits($cid, $limits, (int) $existing['creator']);
        }

        return true;
    }

    /**
     * Cascade-delete a customer: their domains, mailboxes, ftp users,
     * sub-customers (recursive), counters, sessions, packages, messages,
     * and finally the customer row.
     *
     * @return bool true on success.
     */
    public static function delete(int $cid): bool
    {
        $existing = self::getById($cid);
        if (!$existing) {
            return false;
        }
        $customerName = (string) $existing['customer'];
        $creator      = (int) $existing['creator'];

        // 1. Recursively delete sub-customers.
        $subs = Config::moduleDb()->select(
                "SELECT cid FROM pmwh3_customers WHERE creator = :c AND cid <> :c",
                ['c' => $cid]
        );
        foreach ($subs as $sub) {
            self::delete((int) $sub['cid']);
        }

        // 2. Delete domains belonging to this customer.
        //    Routed through Domain_Model so DNS / postfix / fs / counters
        //    cleanup happens correctly.
        $domains = Config::moduleDb()->select(
                "SELECT domain FROM pmwh3_domains WHERE cid = :c",
                ['c' => $cid]
        );
        if (!empty($domains)) {
            require_once __DIR__ . '/../model/domain_model.php';
            $dm = new \Domain_Model();
            foreach ($domains as $d) {
                $dm->deleteDomain((string) $d['domain']);
            }
        }

        // 3. Mailboxes / forwards / catchalls owned by customer.
        //    Postfix tables don't have a direct cid link, so we go via
        //    domain ownership which we already cleaned. Sweep orphan
        //    rows whose domain part is no longer in pmwh3_domains.
        $remaining = Config::moduleDb()->select(
                "SELECT domain FROM pmwh3_domains", []
        );
        $keepDomains = array_column($remaining, 'domain');
        if (!empty($keepDomains)) {
            $placeholders = [];
            $bind = [];
            foreach ($keepDomains as $i => $d) {
                $placeholders[] = ":d{$i}";
                $bind["d{$i}"] = $d;
            }
            ServiceDb::get('mail')->delete(
                    'pmwh3_mail_accounts',
                    "SUBSTRING_INDEX(email, '@', -1) NOT IN ("
                    . implode(',', $placeholders) . ")",
                    $bind
            );
        } else {
            ServiceDb::get('mail')->delete('pmwh3_mail_accounts', '1=1', []);
        }

        // 4. ProFTPD entries.
        ServiceDb::get('ftp')->delete('pmwh3_ftp_accounts', 'username = :u OR master = :u',
                ['u' => $customerName]);
        ServiceDb::get('ftp')->delete('pmwh3_ftp_groups', 'groupname = :g',
                ['g' => $customerName]);
        ServiceDb::get('ftp')->delete('pmwh3_ftp_quota_limits', 'name = :n',
                ['n' => $customerName]);
        ServiceDb::get('ftp')->delete('pmwh3_ftp_quota_tallies', 'name = :n',
                ['n' => $customerName]);

        // 5. Activity / messages / packages / countings.
        Config::moduleDb()->delete('pmwh3_activity', 'customer = :c',
                ['c' => $customerName]);
        Config::moduleDb()->delete('pmwh3_messages',
                'sender = :c OR recipient = :c', ['c' => $customerName]);
        Config::moduleDb()->delete('pmwh3_packages', 'creator = :cid',
                ['cid' => $cid]);

        // 6. Roll back the creator's 'granted' counters.
        if ($creator > 0) {
            $maxes = self::readMax($cid);
            self::adjustGrantedForCreator($creator, $maxes, -1);
        }

        Config::moduleDb()->delete('pmwh3_countings', 'cid = :c', ['c' => $cid]);

        // 7. Finally drop the customer row.
        Config::moduleDb()->delete('pmwh3_customers', 'cid = :c', ['c' => $cid]);

        return true;
    }

    /** Read the 'max' row of a customer as a resource => value map. */
    public static function readMax(int $cid): array
    {
        $db = Config::moduleDb();
        $row = $db->selectOne(
                "SELECT * FROM pmwh3_countings WHERE cid = :c AND type = 'max'",
                ['c' => $cid]
        );
        if (!$row) {
            return [];
        }
        unset($row['cid'], $row['type']);
        return array_map('intval', $row);
    }

    /** Update limits and adjust the creator's granted counters by the diff. */
    private static function updateLimits(int $cid, array $newLimits, int $creator): void
    {
        $oldLimits = self::readMax($cid);

        $allowed = CountingUtil::getResources();
        $update = [];
        foreach ($allowed as $r) {
            if (array_key_exists($r, $newLimits)) {
                $update[$r] = (int) $newLimits[$r];
            }
        }
        if (empty($update)) {
            return;
        }

        $db = Config::moduleDb();
        $exists = $db->selectOne(
                "SELECT cid FROM pmwh3_countings WHERE cid = :c AND type = 'max'",
                ['c' => $cid]
        );
        if ($exists) {
            $db->update('pmwh3_countings', $update,
                    "cid = :c AND type = 'max'", ['c' => $cid]);
        } else {
            $db->insert('pmwh3_countings', array_merge(
                            ['cid' => $cid, 'type' => 'max'], $update));
        }

        // Adjust creator's 'granted' by the diff so the bookkeeping stays
        // correct across edits.
        if ($creator > 0) {
            foreach ($update as $r => $newMax) {
                $oldMax = (int) ($oldLimits[$r] ?? 0);
                $diff   = $newMax - $oldMax;
                if ($diff !== 0) {
                    CountingUtil::incrementGranted($creator, $r, $diff);
                }
            }
        }
    }

    /**
     * Initialise the three counting rows (max, used, granted) for a
     * brand-new customer.
     */
    private static function seedCountings(int $cid, array $limits): void
    {
        $resources = CountingUtil::getResources();
        $maxRow     = ['cid' => $cid, 'type' => 'max'];
        $usedRow    = ['cid' => $cid, 'type' => 'used'];
        $grantedRow = ['cid' => $cid, 'type' => 'granted'];

        foreach ($resources as $r) {
            $maxRow[$r]     = (int) ($limits[$r] ?? 0);
            $usedRow[$r]    = 0;
            $grantedRow[$r] = 0;
        }

        $db = Config::moduleDb();
        $db->insert('pmwh3_countings', $maxRow);
        $db->insert('pmwh3_countings', $usedRow);
        $db->insert('pmwh3_countings', $grantedRow);
    }

    /**
     * Adjust the 'granted' counter on the creator so the books balance
     * when a child customer is created or removed.
     *
     * $sign is +1 when handing limits down, -1 when reclaiming them.
     * Limits of -1 (unlimited) are not propagated to granted (a parent
     * can hand down unlimited without affecting their own quota).
     */
    private static function adjustGrantedForCreator(int $creator, array $limits, int $sign): void
    {
        foreach ($limits as $resource => $value) {
            $value = (int) $value;
            if ($value <= 0) {
                continue;
            }
            try {
                CountingUtil::incrementGranted($creator, $resource, $sign * $value);
            } catch (\InvalidArgumentException $e) {
                // unknown resource -> skip silently
            }
        }
    }

    /**
     * Filter incoming data to known columns. Additionally drops
     * columns that don't exist on the actual `pmwh3_customers`
     * table -- some installs (especially upgraded from pmwh2)
     * predate the `package` column. We don't want a 1054 here just
     * because someone never ran the matching migration.
     */
    private static function filterFields(array $data): array
    {
        $out = [];
        foreach (self::ALLOWED_FIELDS as $f) {
            if (array_key_exists($f, $data) && self::columnExists($f)) {
                $out[$f] = $data[$f];
            }
        }
        return $out;
    }

    /** Per-request cache of pmwh3_customers columns. */
    private static ?array $customerColumns = null;

    private static function columnExists(string $col): bool
    {
        if (self::$customerColumns === null) {
            try {
                $rows = Config::moduleDb()->select(
                        "SELECT column_name AS c FROM information_schema.columns
                          WHERE table_schema = DATABASE()
                            AND table_name = 'pmwh3_customers'", []
                );
                self::$customerColumns = array_map(
                        fn($r) => strtolower((string) ($r['c'] ?? '')),
                        $rows
                );
            } catch (\Throwable $e) {
                // Failed to detect -- assume everything exists,
                // let the caller see the real SQL error.
                self::$customerColumns = self::ALLOWED_FIELDS;
            }
        }
        return in_array(strtolower($col), self::$customerColumns, true);
    }
}
