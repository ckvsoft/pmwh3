<?php

namespace pmwh3\Utils;

use ckvsoft\mvc\Config;

/**
 * ACL administration helpers -- post-Etappe-4c version.
 *
 * Reads / writes the framework RBAC tables (`role_perms` keyed by
 * roleID + permID). Replaces the previous pmwh3_acl_*-backed
 * implementation; the data is also gone after Etappe 4d cleans up
 * the old tables.
 *
 * The user-facing model in the pmwh3 group-permissions UI:
 *   - A customer group is a framework role with module='pmwh3'.
 *   - All pmwh3 permissions live in `permissions` with module='pmwh3'
 *     and permKey prefixed 'pmwh3.'.
 *   - Permissions are presented to the editor in fixed categories
 *     derived from the bare permKey (the part after 'pmwh3.'). The
 *     old object-group concept (manually maintained ogrp records)
 *     is gone -- categorisation is now purely a UI grouping rule,
 *     no DB rows behind it.
 *
 * Safety rails enforced here, unchanged from the previous version:
 *   - never let the viewer edit their own role (would let them lock
 *     themselves out)
 *   - never let the viewer grant a permission they don't have
 *     themselves (prevents privilege escalation through the UI)
 *
 * Both rails are checked at apply() time. The view layer still
 * gets the full set of perms so the user can see what's there;
 * checkboxes for perms the viewer doesn't have are rendered as
 * disabled.
 */
class AclManager
{

    /**
     * UI categories for the permissions grid. Each entry is a
     * [label, matcher] pair. The matcher is either:
     *   - a prefix string ('view_customer'): matches a bare key
     *     starting with that prefix
     *   - an array of exact bare keys
     *
     * Order here = display order. The first matching entry wins,
     * so put more specific prefixes before more general ones
     * (e.g. 'view_menu_email_' before 'view_menu_'). The catch-all
     * "Other" bucket at the end mops up anything not matched.
     */
    private const CATEGORIES = [
        // === pmwh3 functional areas ===
        ['Customers', ['prefix' => ['view_customer', 'view_customers', 'view_all_customers',
                                    'create_customer', 'edit_customer', 'delete_customer',
                                    'view_own_account', 'edit_customer_password']]],
        ['Customer groups',     ['prefix' => ['view_groups', 'create_group', 'edit_group',
                                              'delete_group', 'change_group']]],
        ['Domains',             ['prefix' => ['view_domain', 'view_domains', 'create_domain',
                                              'edit_domain', 'delete_domain', 'view_subdomain',
                                              'create_subdomain', 'edit_subdomain', 'delete_subdomain',
                                              'manage_dnssec']]],
        ['Email',               ['prefix' => ['view_email', 'create_email', 'edit_email',
                                              'delete_email', 'change_email', 'insert_email',
                                              'clone_email']]],
        ['FTP',                 ['prefix' => ['view_ftp', 'create_ftp', 'edit_ftp', 'delete_ftp',
                                              'change_ftp']]],
        ['Databases',           ['prefix' => ['view_database', 'view_databases', 'create_database',
                                              'edit_database', 'delete_database',
                                              'assign_database_user', 'change_database_user',
                                              'delete_database_user']]],
        ['Packages',            ['prefix' => ['view_package', 'view_packages',
                                              'create_package', 'edit_package', 'delete_package']]],
        ['Tools & Content',     ['prefix' => ['view_news', 'create_news', 'edit_news', 'delete_news',
                                              'view_applications', 'view_application',
                                              'create_application', 'edit_application', 'delete_application',
                                              'view_backup', 'create_backup',
                                              'view_errorlog', 'errorlog_view_all_domains',
                                              'view_tools_menu', 'create_menu_row',
                                              'edit_menu_row', 'delete_menu_row',
                                              'delete_message', 'send_message']]],
        ['Object groups (legacy)',
                                ['prefix' => ['view_objgroups', 'view_all_object_groups',
                                              'create_objgroup', 'edit_objgroup', 'delete_objgroup',
                                              'change_objgroup']]],
        ['Permissions admin',   ['prefix' => ['view_permissions', 'change_permissions',
                                              'ultimate_admin']]],
        ['Stats counts (legacy)', ['prefix' => ['view_customer_count', 'view_customer_creator',
                                              'view_customer_limits',
                                              'view_database_count', 'view_domain_count',
                                              'view_email_count', 'view_ftp_count',
                                              'view_subdomain_count',
                                              'view_free_space',
                                              'view_security_recommends',
                                              'view_server_variable']]],
        ['Options screens',     ['prefix' => ['view_menu_options', 'save_options']]],
        // === menu visibility (everything view_menu_* not covered above) ===
        ['Menu visibility',     ['prefix' => ['view_menu_']]],
        // catch-all sentinel handled below the loop
    ];

    /**
     * Map a bare pmwh3 permKey (no 'pmwh3.' prefix) to a UI category
     * label. Returns 'Other' for anything not matched.
     */
    public static function categoryFor(string $bareKey): string
    {
        foreach (self::CATEGORIES as [$label, $matcher]) {
            $prefixes = (array) ($matcher['prefix'] ?? []);
            foreach ($prefixes as $pfx) {
                if ($pfx !== '' && str_starts_with($bareKey, $pfx)) {
                    return $label;
                }
            }
        }
        return 'Other';
    }

    /**
     * Return the ordered list of category labels for stable UI
     * rendering. Includes 'Other' at the end if anything would
     * actually land there for the given key list.
     *
     * @param string[] $bareKeys  the permission keys that will be
     *                            rendered; used to decide whether
     *                            'Other' should be in the list.
     */
    public static function categoryOrder(array $bareKeys): array
    {
        $labels = array_map(fn($e) => $e[0], self::CATEGORIES);
        // Add 'Other' only if any input key needs it -- avoids an
        // empty section in the UI.
        foreach ($bareKeys as $k) {
            if (self::categoryFor($k) === 'Other') {
                $labels[] = 'Other';
                break;
            }
        }
        return $labels;
    }

    /**
     * Build the grid data for one customer-group role.
     *
     * Returns an array of category-grouped permissions:
     *   [
     *     'Customers' => [
     *       ['permId' => 42, 'bareKey' => 'view_customers', 'hasit' => true],
     *       ...
     *     ],
     *     'Domains' => [...],
     *     ...
     *   ]
     *
     * Categories with zero matching perms are dropped so the view
     * doesn't render empty sections.
     */
    public static function listPermissionsGrouped(int $roleId): array
    {
        $db = Config::db();

        // All pmwh3 permissions.
        $perms = $db->select(
                "SELECT id, permKey FROM permissions
                  WHERE module = 'pmwh3'
                  ORDER BY permKey"
        );

        // role_perms granted to this role -- one query, then join in PHP.
        $granted = $db->select(
                "SELECT permID, value FROM role_perms WHERE roleID = :r",
                ['r' => $roleId]
        );
        $grantedMap = [];
        foreach ($granted as $g) {
            $grantedMap[(int) $g['permID']] = ((int) $g['value']) === 1;
        }

        // Bucket by category.
        $out = [];
        foreach ($perms as $p) {
            $permId  = (int) $p['id'];
            $bareKey = (string) str_starts_with($p['permKey'], 'pmwh3.')
                    ? substr($p['permKey'], 6)
                    : (string) $p['permKey'];
            $label   = self::categoryFor($bareKey);
            $out[$label][] = [
                'permId'  => $permId,
                'bareKey' => $bareKey,
                'hasit'   => $grantedMap[$permId] ?? false,
            ];
        }

        // Order the output by CATEGORIES + Other, dropping empty ones.
        $bareKeys = array_map(fn($p) => (string) (str_starts_with($p['permKey'], 'pmwh3.')
                ? substr($p['permKey'], 6) : $p['permKey']), $perms);
        $ordered = [];
        foreach (self::categoryOrder($bareKeys) as $label) {
            if (!empty($out[$label])) {
                $ordered[$label] = $out[$label];
            }
        }
        return $ordered;
    }

    /**
     * Apply a checkbox-grid submission to role_perms.
     *
     * @param int   $roleId      target role whose perms are being edited
     * @param int   $viewerRoleId  the editor's own role-id (used for
     *                             the self-edit rail; comes from the
     *                             controller, not from POST)
     * @param int[] $checked     permIDs the user wants set (granted).
     *                           Unchecked checkboxes don't appear in POST
     *                           and so aren't here.
     * @param int[] $known       all permIDs that were visible in the form
     *                           (hidden field per row); needed to know
     *                           what to UN-set.
     *
     * Returns ['changed' => int, 'skipped' => int].
     */
    public static function applyGrid(int $roleId, int $viewerRoleId, array $checked, array $known): array
    {
        // Self-edit rail: a normal user can't change their own
        // role's permissions. The ultimate admin (cid=1) needs to
        // be able to -- safety net of last resort. The
        // per-permission privilege-escalation rail below also
        // applies, so cid=1 passes both because hasAccess()
        // returns true unconditionally for them.
        $viewerCid = (int) (\ckvsoft\Session::getNs('pmwh3', 'customer_id') ?? 0);
        if ($roleId === $viewerRoleId && $viewerCid !== 1) {
            return ['changed' => 0, 'skipped' => count($known)];
        }

        $db = Config::db();
        $checkedSet = array_flip(array_map('intval', $checked));
        $changed = 0;
        $skipped = 0;

        // Pre-fetch all framework permission rows we'll touch so we
        // can do the privilege-escalation check by name (CustomerUtil
        // ::hasAccess takes a bare key, not a permID).
        $known = array_values(array_unique(array_map('intval', $known)));
        $known = array_filter($known, fn($p) => $p > 0);
        if (empty($known)) {
            return ['changed' => 0, 'skipped' => 0];
        }
        $ph = []; $bind = [];
        foreach ($known as $i => $p) {
            $ph[]          = ":p{$i}";
            $bind["p{$i}"] = $p;
        }
        $permRows = $db->select(
                "SELECT id, permKey FROM permissions
                  WHERE module = 'pmwh3' AND id IN (" . implode(',', $ph) . ")",
                $bind
        );
        $permKeyByPermId = [];
        foreach ($permRows as $r) {
            $permKeyByPermId[(int) $r['id']] = (string) $r['permKey'];
        }

        foreach ($known as $permId) {
            if (!isset($permKeyByPermId[$permId])) {
                // Not a pmwh3 perm (or doesn't exist) -- silently
                // drop. Possible if the form was rigged client-side.
                $skipped++;
                continue;
            }
            $permKey = $permKeyByPermId[$permId];
            $bareKey = str_starts_with($permKey, 'pmwh3.')
                    ? substr($permKey, 6) : $permKey;

            // Privilege-escalation rail: don't grant/revoke a
            // permission the editor doesn't have themselves.
            if (!\pmwh3\Utils\Acl::has($bareKey)) {
                $skipped++;
                continue;
            }

            $wantY = isset($checkedSet[$permId]);

            $existing = $db->selectOne(
                    "SELECT value FROM role_perms
                      WHERE roleID = :r AND permID = :p",
                    ['r' => $roleId, 'p' => $permId]
            );
            $hasY = $existing && ((int) $existing['value']) === 1;

            if ($wantY === $hasY) {
                continue;  // no change
            }
            if ($wantY) {
                if ($existing) {
                    $db->update('role_perms',
                            ['value' => 1],
                            'roleID = :r AND permID = :p',
                            ['r' => $roleId, 'p' => $permId]);
                } else {
                    $db->insert('role_perms', [
                        'roleID' => $roleId,
                        'permID' => $permId,
                        'value'  => 1,
                    ]);
                }
            } else {
                // Revoke -- delete the row entirely. ACL::hasPermission
                // returns false when no row exists, which is the
                // intended semantics ("not granted").
                $db->delete('role_perms',
                        'roleID = :r AND permID = :p',
                        ['r' => $roleId, 'p' => $permId]);
            }
            $changed++;
        }
        return ['changed' => $changed, 'skipped' => $skipped];
    }
}
