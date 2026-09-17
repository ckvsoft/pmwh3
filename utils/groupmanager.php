<?php

namespace pmwh3\Utils;

use ckvsoft\mvc\Config;

/**
 * Customer group CRUD.
 *
 * As of Etappe 4b (see MIGRATION_PLAN_RBAC.md) groups are stored
 * in the framework's `roles` table with module='pmwh3', as
 * children of a parent role named 'pmwh3'. They used to live in
 * pmwh3_groups; that table is unused now and will be dropped
 * in Etappe 4d.
 *
 * Identity convention:
 *   - getById($roleId) takes a framework roles.id
 *   - the public method names still say "group" because that's
 *     the user-facing word in pmwh3's UI ("customer group");
 *     internally everything is just a role.
 *   - the return shape stays compatible with the previous version
 *     for now: keys 'gid' and 'name'. This lets the surrounding
 *     UI code keep working unchanged.
 *
 * The parent role 'pmwh3' is a namespace marker -- you don't
 * see it in the listing, you can't delete it via this manager,
 * and new groups created via create() are always inserted as
 * its children.
 *
 * Cross-database note:
 *   pmwh3_customers.role_id points at framework roles.id, but
 *   the two tables live in different databases (cevian framework
 *   DB vs pmwh3 module DB) so we can't enforce this with a real
 *   FOREIGN KEY constraint. The relationship is application-level
 *   only -- if a role is deleted while customers still point at
 *   it, those customers end up dangling. GroupManager::delete()
 *   refuses to drop a group with members for exactly this reason.
 */
class GroupManager
{

    /** Parent role name that holds all pmwh3 customer-groups. */
    private const PARENT_NAME = 'pmwh3';

    /**
     * Resolve the framework role-id of the 'pmwh3' parent role.
     * Caches per request because every CRUD call needs it.
     */
    private static ?int $parentIdCache = null;

    private static function parentRoleId(): int
    {
        if (self::$parentIdCache !== null) {
            return self::$parentIdCache;
        }
        $row = Config::db()->selectOne(
                "SELECT id FROM roles WHERE roleName = :n AND module = 'pmwh3' AND depth = 0 LIMIT 1",
                ['n' => self::PARENT_NAME]
        );
        if (!$row) {
            // Shouldn't happen on a system that ran the Etappe 3
            // migration; surface a clear error rather than letting
            // a downstream query silently misbehave.
            throw new \RuntimeException(
                    "GroupManager: parent role '" . self::PARENT_NAME .
                    "' not found. Did the RBAC migration run?"
            );
        }
        self::$parentIdCache = (int) $row['id'];
        return self::$parentIdCache;
    }

    /**
     * The role-id used as the default group for newly-created
     * customers (used to be hardcoded 2 = old gid for Reseller).
     * Now resolved by name so it works after fresh installs too.
     *
     * Falls back to the first child of the pmwh3 parent role if
     * no role specifically named 'Reseller' exists.
     */
    public static function getDefaultRoleId(): int
    {
        $parentId = self::parentRoleId();
        $row = Config::db()->selectOne(
                "SELECT id FROM roles
                  WHERE roleName = 'Reseller'
                    AND module = 'pmwh3'
                  LIMIT 1"
        );
        if ($row) {
            return (int) $row['id'];
        }
        // No Reseller? Take the first child of pmwh3 parent.
        $parent = Config::db()->selectOne(
                "SELECT lft, rgt FROM roles WHERE id = :p", ['p' => $parentId]
        );
        if ($parent) {
            $row = Config::db()->selectOne(
                    "SELECT id FROM roles
                      WHERE module = 'pmwh3'
                        AND depth = 1
                        AND lft > :l AND rgt < :r
                      ORDER BY lft
                      LIMIT 1",
                    ['l' => (int) $parent['lft'], 'r' => (int) $parent['rgt']]
            );
            if ($row) {
                return (int) $row['id'];
            }
        }
        // Last resort: the parent itself. Bad value but at least
        // a valid roles.id -- avoids ending up with a dangling
        // role_id reference no role can ever satisfy.
        return $parentId;
    }

    /**
     * Look up one group by its role-id. Returns null if not found,
     * or if the role exists but isn't a pmwh3 customer-group
     * (i.e. wrong module or wrong depth in the role tree).
     *
     * Return shape: ['gid' => int, 'name' => string].
     * 'gid' name kept for backwards compatibility with callers
     * that still treat it as the customer-group identifier.
     */
    public static function getById(int $roleId): ?array
    {
        $row = Config::db()->selectOne(
                "SELECT id, roleName FROM roles
                  WHERE id = :i AND module = 'pmwh3' AND depth = 1
                  LIMIT 1",
                ['i' => $roleId]
        );
        return $row ? ['gid' => (int) $row['id'], 'name' => (string) $row['roleName']] : null;
    }

    /**
     * Look up one group by name. Module-scoped to pmwh3 so
     * a same-named framework role doesn't accidentally match.
     */
    public static function getByName(string $name): ?array
    {
        $row = Config::db()->selectOne(
                "SELECT id, roleName FROM roles
                  WHERE roleName = :n
                    AND module = 'pmwh3'
                    AND depth = 1
                  LIMIT 1",
                ['n' => $name]
        );
        return $row ? ['gid' => (int) $row['id'], 'name' => (string) $row['roleName']] : null;
    }

    /**
     * Returns all pmwh3 customer-groups with member counts.
     * Excludes the parent role 'pmwh3' itself (that's a namespace,
     * not a group customers can be in).
     */
    public static function listAll(): array
    {
        $parentId = self::parentRoleId();
        $rows = Config::db()->select(
                "SELECT id, roleName
                   FROM roles
                  WHERE module = 'pmwh3'
                    AND depth = 1
                    AND id != :p
                  ORDER BY lft",
                ['p' => $parentId]
        );
        // member_count comes from pmwh3 DB, so we have to query
        // separately -- framework DB and pmwh3 DB are different
        // connections.
        $out = [];
        $pmwh3Db = Config::moduleDb('pmwh3');
        foreach ($rows as $r) {
            $cnt = $pmwh3Db->selectOne(
                    "SELECT COUNT(*) AS n FROM pmwh3_customers WHERE role_id = :r",
                    ['r' => (int) $r['id']]
            );
            $out[] = [
                'gid'          => (int) $r['id'],
                'name'         => (string) $r['roleName'],
                'member_count' => (int) ($cnt['n'] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * How many customers are assigned to this group.
     */
    public static function memberCount(int $roleId): int
    {
        $row = Config::moduleDb('pmwh3')->selectOne(
                "SELECT COUNT(*) AS n FROM pmwh3_customers WHERE role_id = :r",
                ['r' => $roleId]
        );
        return (int) ($row['n'] ?? 0);
    }

    /**
     * Create a new customer-group under the pmwh3 parent role.
     *
     * @return int|false new role-id on success, false if name
     *                   collides with an existing pmwh3 role.
     */
    public static function create(string $name)
    {
        $name = trim($name);
        if ($name === '') {
            return false;
        }
        if (self::getByName($name) !== null) {
            return false;
        }
        $parentId = self::parentRoleId();
        $acl = new \ckvsoft\ACL();
        try {
            $newId = $acl->addRole($name, $parentId, 'pmwh3');
        } catch (\Throwable $e) {
            \pmwh3\Utils\ErrorHandler::trace('GroupManager::create: ' . $e->getMessage());
            return false;
        }
        return (int) $newId;
    }

    /**
     * Rename a customer-group. Refuses if another pmwh3 role
     * already has the target name.
     */
    public static function update(int $roleId, string $name): bool
    {
        $name = trim($name);
        if ($name === '') {
            return false;
        }
        if (self::getById($roleId) === null) {
            return false;
        }
        $existing = self::getByName($name);
        if ($existing !== null && (int) $existing['gid'] !== $roleId) {
            return false;
        }
        Config::db()->update(
                'roles',
                ['roleName' => $name],
                'id = :i',
                ['i' => $roleId]
        );
        return true;
    }

    /**
     * Delete a customer-group. Refuses if it still has members.
     * Caller is expected to either reassign customers first or
     * accept the "has members" error.
     *
     * Uses the framework ACL's deleteRole() which handles the
     * Nested Set shrink correctly. We explicitly clean up
     * role_perms and user_roles first because the framework
     * deleteRole doesn't cascade those (its source comment says
     * it expects FK CASCADE which isn't set up on the schema).
     */
    public static function delete(int $roleId): bool
    {
        if (self::memberCount($roleId) > 0) {
            return false;
        }
        $db = Config::db();
        // Cascade cleanup before nested-set delete.
        $db->delete('role_perms', 'roleID = :r', ['r' => $roleId]);
        $db->delete('user_roles', 'roleID = :r', ['r' => $roleId]);

        $acl = new \ckvsoft\ACL();
        try {
            $acl->deleteRole($roleId);
            return true;
        } catch (\Throwable $e) {
            \pmwh3\Utils\ErrorHandler::trace('GroupManager::delete: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * All customers in a group, ordered by name. Used by the
     * group-overview page to show *who* is in each group.
     */
    public static function listMembers(int $roleId): array
    {
        return Config::moduleDb('pmwh3')->select(
                "SELECT cid, customer FROM pmwh3_customers
                  WHERE role_id = :r
               ORDER BY customer",
                ['r' => $roleId]
        );
    }
}
