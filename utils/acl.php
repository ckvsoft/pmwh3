<?php

namespace pmwh3\Utils;

use ckvsoft\Session;

/**
 * Wrapper around the framework's \ckvsoft\ACL for pmwh3.
 *
 * As of Etappe 4 (see MIGRATION_PLAN_RBAC.md) this is the
 * authoritative runtime permission check for pmwh3.
 * CustomerUtil::hasAccess() still exists as a thin backwards-
 * compatible alias and forwards to Acl::has().
 *
 * Conventions:
 *   - All pmwh3 permission keys are prefixed `pmwh3.` in the
 *     framework table. Callers pass the bare key (e.g.
 *     'create_customer'), the prefix is added inside.
 *   - module='pmwh3' is always set on registration.
 *   - The ultimate admin (cid=1) bypasses the rbac lookup --
 *     cid=1 is always allowed everything. This mirrors
 *     CustomerUtil::hasAccess's old behaviour exactly so the
 *     switchover doesn't change anyone's effective permissions.
 *
 * Identity bridge:
 *   The framework's \ckvsoft\ACL expects $_SESSION['user_id'].
 *   pmwh3 stores its identity as a namespaced session value
 *   under 'pmwh3'.'customer_id'. We pass the cid explicitly to
 *   the ACL constructor on every check so the framework class
 *   doesn't need to know about our session shape.
 */
class Acl
{

    /** Permission-key prefix used in framework `permissions` table. */
    public const MODULE      = 'pmwh3';
    public const KEY_PREFIX  = 'pmwh3.';

    /**
     * Per-request cache so we don't reinstantiate ACL + rebuild
     * its perm map on every hasAccess() call. The framework class
     * does that work in its constructor.
     *
     * Keyed by cid. Reset implicitly when the script ends.
     */
    private static array $aclCache = [];

    /**
     * Is the currently logged-in pmwh3 customer the ultimate admin?
     *
     * "Ultimate admin" is the cid=1 customer -- the first one
     * created at install time and the unrevokable safety net.
     * Distinct from the "Ultimate Admin" role name (the role
     * customers can be members of); this check is about identity,
     * not role membership. cid=1 is the ultimate admin even if
     * they're somehow assigned a different role.
     *
     * Used for SCOPE decisions: "should this user see only their
     * own customers, or every customer in the system?". That's
     * not a permission (it's not granular) -- it's an identity
     * concept. The previous code had hasAccess("ultimate_admin")
     * checks for this which was wrong: the cid=1 bypass in has()
     * returns true for *every* permission so the check answered
     * yes even for users who shouldn't have all-scope access.
     */
    public static function isUltimateAdmin(): bool
    {
        $cid = (int) (Session::getNs('pmwh3', 'customer_id') ?? 0);
        return $cid === 1;
    }

    /**
     * Runtime permission check. Returns true iff the current
     * pmwh3-customer holds the named permission via one of their
     * roles in `role_perms`. cid=1 is the unconditional bypass.
     *
     * @param string $bareKey  e.g. 'create_customer'
     */
    public static function has(string $bareKey): bool
    {
        $cid = (int) (Session::getNs('pmwh3', 'customer_id') ?? 0);

        // Ultimate admin bypass -- cid=1 is the safety net of last
        // resort and is always allowed everything, even before they
        // have any explicit role assignments. Also: registers the
        // permission in the framework table on first sight so the
        // RBAC admin UI can see it and grant it to other roles.
        // Mirrors the legacy CustomerUtil::hasAccess behaviour where
        // first-time permissions were auto-inserted with cid=1 = Y.
        if ($cid === 1) {
            try {
                self::ensurePermission($bareKey);
            } catch (\Throwable $e) {
                // Non-fatal -- cid=1 is allowed anyway, the
                // registration is just bookkeeping.
            }
            return true;
        }

        if ($cid <= 0) {
            return false;  // not logged in
        }

        // Cache the ACL instance per cid so the framework class
        // doesn't rebuild its perm map for every check in one
        // request.
        if (!isset(self::$aclCache[$cid])) {
            self::$aclCache[$cid] = new \ckvsoft\ACL($cid);
        }
        return self::$aclCache[$cid]->hasPermission(self::permKey($bareKey));
    }

    /**
     * Build the framework permKey from a bare pmwh3 key.
     *   'create_customer'  ->  'pmwh3.create_customer'
     */
    public static function permKey(string $bareKey): string
    {
        return self::KEY_PREFIX . $bareKey;
    }

    /**
     * Register a permission in the framework `permissions` table if
     * it isn't there yet. Idempotent -- relies on the UNIQUE KEY on
     * permKey.
     *
     * @param string      $bareKey  e.g. 'create_customer'
     * @param string|null $name     human-readable name (default: the bare key)
     * @param string|null $desc     description (default: empty)
     * @return int                  the permission id (existing or new)
     */
    public static function ensurePermission(string $bareKey, ?string $name = null, ?string $desc = null): int
    {
        $key = self::permKey($bareKey);
        $acl = new \ckvsoft\ACL();
        return $acl->createPermission([
            'permKey'         => $key,
            'module'          => self::MODULE,
            'permName'        => $name ?? $bareKey,
            'permDescription' => $desc ?? '',
        ]);
    }

    /**
     * Bulk-register a list of pmwh3 permission keys. Returns the
     * number of permissions actually created (existing ones are
     * not re-inserted thanks to the UNIQUE KEY).
     *
     * @param string[] $bareKeys  list of bare keys (no prefix)
     * @return int                count of new rows
     */
    public static function ensurePermissions(array $bareKeys): int
    {
        $acl = new \ckvsoft\ACL();
        $created = 0;
        foreach ($bareKeys as $bare) {
            $bare = (string) $bare;
            if ($bare === '') continue;
            $key = self::permKey($bare);
            // permissionKeyExists is the cheapest existence-probe the
            // framework offers; createPermission also checks but we
            // want the "did I create something" answer.
            if (!$acl->permissionKeyExists($key)) {
                $acl->createPermission([
                    'permKey'         => $key,
                    'module'          => self::MODULE,
                    'permName'        => $bare,
                    'permDescription' => '',
                ]);
                $created++;
            }
        }
        return $created;
    }

    /**
     * Return all pmwh3-owned permissions from the framework table,
     * in the framework 'full' format (associative by permKey).
     *
     * Returns rows shaped like:
     *   ['pmwh3.create_customer' => [
     *       'id' => 42, 'permKey' => 'pmwh3.create_customer',
     *       'permName' => '...', 'permDescription' => '...',
     *       'module' => 'pmwh3'
     *   ], ...]
     */
    public static function listOwnedPermissions(): array
    {
        $acl = new \ckvsoft\ACL();
        return $acl->getAllPerms('full', self::MODULE);
    }
}
