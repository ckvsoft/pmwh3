<?php

namespace pmwh3\Utils;

use ckvsoft\CkvException;
use ckvsoft\Session;
use ckvsoft\Hash;
use pmwh3\Utils\DomainUtil;
use ckvsoft\mvc\Config;

class CustomerUtil
{

    /**
     * Checks if the current logged-in pmwh3 customer has the given
     * permission.
     *
     * As of Etappe 4 (see MIGRATION_PLAN_RBAC.md) this is a thin
     * alias for \pmwh3\Utils\Acl::has(). The runtime permission
     * store is now the framework's `permissions` + `role_perms` +
     * `user_roles` tables, not pmwh3_acl_*.
     *
     * The $gid second argument is kept for backwards compatibility
     * with old callers but has no effect any more -- the framework
     * RBAC always looks at the current logged-in user's effective
     * role set, not at a hypothetical other group. If you need a
     * "would this group be allowed" check, instantiate
     * \ckvsoft\ACL directly with the userID you want to probe.
     */
    public static function hasAccess(string $permission, $gid = '-1'): bool
    {
        if ($gid !== '-1') {
            // Was used very rarely in legacy code paths. Log so we
            // notice if anything still relies on it post-migration.
            \pmwh3\Utils\ErrorHandler::trace("hasAccess: legacy gid={$gid} arg ignored for '{$permission}'");
        }
        return \pmwh3\Utils\Acl::has($permission);
    }

    public static function getCustomerNameById(int $id, bool $useSession = false): ?string
    {
        if ($useSession && ($cached = Session::getNs('pmwh3', 'customer_name'))) {
            return $cached;
        }

        $db = Config::moduleDb();

        $row = $db->selectOne(
                "SELECT customer FROM pmwh3_customers WHERE cid = :cid",
                ['cid' => $id]
        );

        $name = $row['customer'] ?? null;
        if ($name === null)
            throw new CkvException("Customer with ID {$id} not found.");

        if ($useSession)
            Session::setNs('pmwh3', 'customer_name', $name);
        return $name;
    }

    /**
     * Reverse lookup: customer name -> cid. Returns 0 if not found.
     * Used for resolving quota owners when the UI carries the name
     * (admin's "Owner" dropdown) but the quota functions expect cids.
     */
    public static function getCustomerIdByName(string $name): int
    {
        if ($name === '') {
            return 0;
        }
        $row = Config::moduleDb()->selectOne(
                "SELECT cid FROM pmwh3_customers WHERE customer = :n",
                ['n' => $name]
        );
        return (int) ($row['cid'] ?? 0);
    }

    /**
     * Look up the name of a customer-group by its role-id.
     *
     * Reads from the framework `roles` table (module='pmwh3').
     * As of Etappe 4b, customer-groups live there; the old
     * pmwh3_acl_groups table is no longer the source of truth.
     *
     * @param int $roleId   framework roles.id of the group
     * @param bool $useSession  if true, cache the looked-up name
     *                          in the session for the rest of
     *                          the request.
     */
    public static function getCustomerGroupNameById(int $roleId, bool $useSession = false): string
    {
        if ($useSession && ($cached = Session::getNs('pmwh3', 'customer_group')))
            return $cached;

        $row = Config::db()->selectOne(
                "SELECT roleName FROM roles
                  WHERE id = :i AND module = 'pmwh3'",
                ['i' => $roleId]
        );

        $name = $row['roleName'] ?? null;
        if ($name === null)
            throw new CkvException("Group with role-id {$roleId} not found.");
        if ($useSession)
            Session::setNs('pmwh3', 'customer_group', $name);

        return $name;
    }

    public static function getCustomersById($customerid, $what = "one", $customers = [], $level = 0)
    {
        $adminLevel = \pmwh3\Config\LazyConfig::get('ADMIN_LEVEL', 'ALL');
        $customers[$customerid] = $level;
        $db = Config::moduleDb();

        if ($adminLevel != "0") {
            $rows = $db->select(
                    "SELECT cid FROM pmwh3_customers WHERE creator = :customerid ORDER BY customer",
                    ['customerid' => $customerid]
            );

            foreach ($rows as $row) {
                $cid = $row["cid"];
                if ($adminLevel === "ALL" || $adminLevel === "2" || $what === "all") {
                    $customers = self::getCustomersById($cid, $what, $customers, $level + 1);
                } else {
                    $customers[$cid] = $level + 1;
                }
            }
        }

        return $customers;
    }

    /**
     * Retrieves a list of customers accessible by the current user,
     * formatted as an associative array (cid => customer name) for forms.
     * * @param int $customerId The base customer ID.
     * @return array Associative array of [cid => customer name].
     */
    public static function getCustomersList(int $customerId): array
    {
        $hierarchy = self::getCustomerHierarchy($customerId, 'all');
        $cids = array_keys($hierarchy);

        if (empty($cids)) {
            return [];
        }

        $bindParams = [];
        $placeholders = [];

        foreach ($cids as $index => $cid) {
            // Parametername FÜR DAS SQL: MIT Doppelpunkt
            $sqlParamName = ":cid_{$index}";
            $placeholders[] = $sqlParamName;

            // Schlüssel FÜR $bindParams: OHNE Doppelpunkt (weil _prepareAndBind ihn hinzufügt)
            $bindKey = "cid_{$index}"; // <--- KORREKTUR HIER
            $bindParams[$bindKey] = $cid;
        }

        $placeholderString = implode(',', $placeholders);

        // SQL-Statement ist weiterhin korrekt
        $sql = "SELECT cid, customer FROM pmwh3_customers WHERE cid IN ({$placeholderString}) ORDER BY customer";

        // $bindParams ist jetzt z.B.: ['cid_0' => 100, 'cid_1' => 200]
        // _prepareAndBind wandelt dies korrekt in [':cid_0' => 100, ':cid_1' => 200] um.
        $db = Config::moduleDb();

        $rows = $db->select($sql, $bindParams, \PDO::FETCH_KEY_PAIR);

        return $rows;
    }

    public static function getDomainsByCustomerId(int $customerId): array
    {
        // Just walk the customer hierarchy starting at the given
        // cid. For cid=1 (the ultimate admin) this naturally
        // covers every customer in the system because everyone
        // descends from cid=1 via the creator chain. For any
        // other user, they see their own subtree and nothing
        // outside it.
        //
        // No special-case for the ultimate admin needed: the
        // tree-walk does the right thing for them by virtue of
        // who they are in the hierarchy, not by an extra check.
        $customers = self::getCustomersById($customerId);
        return empty($customers) ? [] : self::getDomainsByCustomerIds(array_keys($customers));
    }

    /**
     * Retrieves domains for a given array of Customer IDs and formats the output.
     * @param array $cids Array of customer IDs (int) to fetch domains for.
     * @return array Array of domains, each containing 'idn' (Punycode) and 'domain' (UTF-8).
     */
    public static function getDomainsByCustomerIds(array $cids): array
    {
        // 1. Safety check: Prevent execution with empty ID set.
        if (empty($cids)) {
            return [];
        }

        // 2. Prepare placeholders and bindings for the IN clause.
        $placeholders = [];
        $bindings = [];
        foreach ($cids as $index => $cid) {
            $key = "cid_{$index}";
            $placeholders[] = ":{$key}";
            $bindings[$key] = $cid;
        }
        $placeholderString = implode(', ', $placeholders);

        $query = "SELECT domain FROM pmwh3_domains WHERE cid IN ({$placeholderString})";

        // 3. Use the existing self::$sharedDb->select() method from ckvsoft\Database.
        // This method executes the query and returns the full result set array.
        // NOTE: The select method automatically handles prepare/bind/execute/fetch.
        $db = Config::moduleDb();

        $rows = $db->select($query, $bindings);

        $domains = [];
        foreach ($rows as $row) {
            $domains[] = [
                'idn' => DomainUtil::domainToAscii($row['domain']),
                'domain' => $row['domain']
            ];
        }

        // 4. Sort the results by the 'domain' (UTF-8) value.
        usort($domains, fn($a, $b) => strcmp($a['domain'], $b['domain']));

        return $domains;
    }

    /**
     * Instance method uses $this->moduleDb
     */
    public static function getCustomerHierarchy(int $customerId, string $what = "one", array $customers = [], int $level = 0): array
    {
        $customers[$customerId] = $level;
        $adminLevel = \pmwh3\Config\LazyConfig::get('ADMIN_LEVEL', 'ALL');

        if ($adminLevel === "0") {
            return $customers;
        }

        $db = Config::moduleDb();

        $rows = $db->select(
                "SELECT cid, customer
                    FROM pmwh3_customers
                    WHERE creator = :creator AND cid <> :cid
                    ORDER BY customer",
                ['creator' => $customerId, 'cid' => $customerId]
        );

        foreach ($rows as $row) {
            $cid = (int) $row['cid'];
            if ($adminLevel === "ALL" || $adminLevel === "2" || $what === "all") {
                $customers = self::getCustomerHierarchy($cid, $what, $customers, $level + 1);
            } else {
                $customers[$cid] = $level + 1;
            }
        }

        return $customers;
    }

    /**
     * The currently-selected domain from the pmwh3 session, or null
     * if none picked yet. Set via the "Change domain" widget in the
     * top-right info bar (controller/pmwh3.php::change_domain) or
     * Email overview's Pick action.
     *
     * Detail views in the menu (View email / forward / catchall /
     * wblist, DNS, DNSSEC, Apache, Activity, ...) all work on
     * exactly one domain at a time -- this is that domain.
     */
    public static function currentDomain(): ?string
    {
        $d = \ckvsoft\Session::getNs('pmwh3', 'customer_current_domain');
        return ($d !== null && $d !== '') ? (string) $d : null;
    }

    /**
     * Set / clear the current domain. Pass null/'' to clear.
     */
    public static function setCurrentDomain(?string $domain): void
    {
        if ($domain === null || $domain === '') {
            \ckvsoft\Session::removeNs('pmwh3', 'customer_current_domain');
            return;
        }
        \ckvsoft\Session::setNs('pmwh3', [
            'customer_current_domain' => $domain,
        ]);
    }

    /**
     * The reserved-name list from RESERVED_NAMES (comma separated),
     * lower-cased and trimmed. Empty entries dropped.
     *
     * @return string[]
     */
    public static function reservedNames(): array
    {
        $raw = (string) \pmwh3\Config\LazyConfig::get('RESERVED_NAMES', '');
        return array_values(array_filter(array_map(
                fn($n) => strtolower(trim($n)),
                explode(',', $raw)
        ), fn($n) => $n !== ''));
    }

    /**
     * True if the given name (customer / mailbox local part / subdomain
     * label) is on the reserve list and may not be chosen. Case-insensitive.
     */
    public static function isReservedName(string $name): bool
    {
        $name = strtolower(trim($name));
        if ($name === '') {
            return false;
        }
        return in_array($name, self::reservedNames(), true);
    }
}
