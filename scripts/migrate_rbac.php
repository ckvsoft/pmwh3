<?php

/**
 * pmwh3 RBAC migration -- Etappe 3 of MIGRATION_PLAN_RBAC.md
 *
 * Migrates pmwh3's old ACL tables to the framework's RBAC store:
 *
 *   pmwh3_acl_groups (type='cgrp')  ->  roles (module='pmwh3')
 *   pmwh3_acl_permissions           ->  role_perms
 *   pmwh3_customers.groups          ->  user_roles
 *
 * Permissions themselves are already in the framework table from
 * Etappe 2 (bootstrap_rbac.php). This script only handles roles +
 * the relationships.
 *
 * STATUS: ONE-SHOT. After Etappe 4b the pmwh3_customers `groups`
 * column was renamed to `role_id`, so re-running this script on
 * a post-4b database would fail at the SELECT below. The pre-check
 * at the top will refuse to do anything if module='pmwh3' roles
 * already exist (which is the case once Etappe 3 ran). Keep this
 * file as a historical record of how the migration was done.
 *
 * Source data on the live DB (verified before the migration):
 *   - pmwh3_acl_groups type='cgrp': 5 rows (gid 1,2,3,27,31).
 *     gid=27 'Demo' has 0 perm rows -> dropped.
 *   - pmwh3_acl_permissions: 627 rows for cgrp=1 with massive
 *     duplication (245 distinct oids, 168 distinct names).
 *     We INSERT IGNORE on the way over to dedupe transparently.
 *   - 21 orphan rows (oid pointing at non-existent objects) ->
 *     dropped via the INNER JOIN.
 *   - 94 names in pmwh3_acl_permissions that aren't in the
 *     framework table -> dropped. These are pmwh2-era leftovers
 *     or perms that haven't been triggered yet; auto-register
 *     in CustomerUtil::hasAccess() will pick them up on first
 *     use after the migration.
 *   - 2 actual users: cid=1 admin (groups=1), cid=23 ckvsoft
 *     (groups=3) -> 2 user_roles rows.
 *
 * Usage:
 *   php scripts/migrate_rbac.php             # dry-run
 *   php scripts/migrate_rbac.php --apply     # actually write
 *
 * Safe to re-run: pre-check refuses to write if a Role with
 * module='pmwh3' already exists.
 */

declare(strict_types=1);

// --- Bootstrap (same shape as bootstrap_rbac.php) ---
$here = __DIR__;
$frameworkRoot = realpath($here . '/../../../');
if (!$frameworkRoot || !is_file($frameworkRoot . '/library/ckvsoft/autoload.php')) {
    fwrite(STDERR, "ERR: cannot find framework relative to {$here}\n");
    exit(2);
}
require_once $frameworkRoot . '/library/ckvsoft/autoload.php';
$autoload = new \ckvsoft\Autoload([
    $frameworkRoot . '/library',
    $frameworkRoot . '/modules',
]);
$moduleRoot = realpath($here . '/..');
if (is_file($moduleRoot . '/modulautoload.php')) {
    require_once $moduleRoot . '/modulautoload.php';
}

// --- Args ---
$apply = false;
foreach ($argv as $arg) {
    if ($arg === '--apply') {
        $apply = true;
    }
}
$mode = $apply ? 'APPLY' : 'DRY-RUN';
echo "=== pmwh3 RBAC migration ({$mode}) ===\n\n";

// --- DB handles ---
//
// Framework DB (cevian) -- holds roles / role_perms / user_roles /
// permissions. Config::db() is the framework shared connection.
//
// pmwh3 DB -- holds pmwh3_acl_groups / pmwh3_acl_permissions /
// pmwh3_acl_objects / pmwh3_customers. Config::moduleDb()
// normally figures out the module from the call backtrace, which
// fails outside a request -- so we pass 'pmwh3' explicitly.
$frameworkDb = \ckvsoft\mvc\Config::db();
$pmwh3Db     = \ckvsoft\mvc\Config::moduleDb('pmwh3');

// --- Pre-check: anything with module='pmwh3' in roles already? ---
$existing = $frameworkDb->select(
        "SELECT id, roleName FROM roles WHERE module = 'pmwh3'"
);
if (!empty($existing)) {
    echo "ABORT: roles already contains module='pmwh3' rows:\n";
    foreach ($existing as $r) {
        echo "  id={$r['id']} name={$r['roleName']}\n";
    }
    echo "\nIf you're re-running after a botched migration, drop\n";
    echo "the existing pmwh3 roles first (use scripts/migrate_rbac_rollback.php\n";
    echo "or do it manually).\n";
    exit(1);
}

// --- Read source data ---

// 1. Customer-groups to migrate. We use pmwh3_acl_groups (not
//    pmwh3_groups) as the source because that's where the
//    canonical names sit ("Ultimate Admin" not "PMWH Admin").
$cgroups = $pmwh3Db->select(
        "SELECT g.gid AS cgrp, g.name,
                (SELECT COUNT(*) FROM pmwh3_acl_permissions p WHERE p.cgrp = g.gid) AS perm_count
           FROM pmwh3_acl_groups g
          WHERE g.type = 'cgrp'
          ORDER BY g.gid"
);

echo "Customer groups to migrate:\n";
foreach ($cgroups as $g) {
    $note = ((int) $g['perm_count'] === 0) ? '  (no perms, but kept)' : '';
    echo sprintf("  cgrp=%-3d name=%-20s perms=%d%s\n",
            $g['cgrp'], $g['name'], $g['perm_count'], $note);
}
echo "\n";

// 2. Users with their group assignment.
$users = $pmwh3Db->select(
        "SELECT cid, customer, groups
           FROM pmwh3_customers
          WHERE groups > 0
          ORDER BY cid"
);
echo "Users to assign:\n";
foreach ($users as $u) {
    echo "  cid={$u['cid']} {$u['customer']} -> group {$u['groups']}\n";
}
echo "\n";

// 3. role_perms mapping preview -- how many rows will land per
//    customer-group, after filtering to perms that exist in the
//    framework table.
//
// The "exists in framework as pmwh3 perm" filter is applied here.
// We can't run a single cross-DB JOIN in a portable way, so we
// pre-fetch the set of framework pmwh3 permKeys and filter in
// PHP.
$frameworkPerms = $frameworkDb->select(
        "SELECT id, permKey FROM permissions WHERE module = 'pmwh3'"
);
$keyToPermId = [];   // pmwh3.create_customer -> 42
foreach ($frameworkPerms as $p) {
    $keyToPermId[(string) $p['permKey']] = (int) $p['id'];
}
echo "Framework has " . count($keyToPermId) . " pmwh3-owned permissions to map against.\n\n";

// For each cgrp, count how many of its rows actually have a
// framework counterpart.
echo "role_perms preview (after dedupe + framework filter):\n";
$rolePermsByCgrp = [];
foreach ($cgroups as $g) {
    $rows = $pmwh3Db->select(
            "SELECT DISTINCT o.object, p.value
               FROM pmwh3_acl_permissions p
               JOIN pmwh3_acl_objects     o ON o.oid = p.oid
              WHERE p.cgrp = :c",
            ['c' => (int) $g['cgrp']]
    );
    $kept = $dropped = 0;
    $entries = [];
    foreach ($rows as $r) {
        $key = 'pmwh3.' . $r['object'];
        if (isset($keyToPermId[$key])) {
            $entries[] = [
                'permId' => $keyToPermId[$key],
                'value'  => ((string) $r['value']) === 'Y' ? 1 : 0,
            ];
            $kept++;
        } else {
            $dropped++;
        }
    }
    $rolePermsByCgrp[(int) $g['cgrp']] = $entries;
    echo sprintf("  cgrp=%-3d %-20s  keep=%-3d  drop=%-3d (no framework counterpart)\n",
            $g['cgrp'], $g['name'], $kept, $dropped);
}
echo "\n";

// --- The actual writes ---

if (!$apply) {
    echo "(dry-run -- nothing written. Re-run with --apply to commit.)\n";
    exit(0);
}

echo "Applying...\n";

$acl = new \ckvsoft\ACL();

// 4a. Parent role "pmwh3" at root.
$parentId = $acl->addRole('pmwh3', null, 'pmwh3');
echo "  + parent role 'pmwh3' (id={$parentId})\n";

// 4b. Customer-groups as children.
$cgrpToRoleId = [];
foreach ($cgroups as $g) {
    $cgrp = (int) $g['cgrp'];
    $name = (string) $g['name'];
    $rid = $acl->addRole($name, $parentId, 'pmwh3');
    $cgrpToRoleId[$cgrp] = $rid;
    echo "  + role '{$name}' (id={$rid}, cgrp={$cgrp})\n";
}

// 5. role_perms.
//    INSERT IGNORE because role_perms has UNIQUE KEY (roleID, permID)
//    -- if our source had duplicates we silently dedupe.
$totalInserted = 0;
foreach ($rolePermsByCgrp as $cgrp => $entries) {
    $roleId = $cgrpToRoleId[$cgrp];
    foreach ($entries as $e) {
        try {
            $frameworkDb->insert('role_perms', [
                'roleID' => $roleId,
                'permID' => $e['permId'],
                'value'  => $e['value'],
            ]);
            $totalInserted++;
        } catch (\Throwable $ex) {
            // Duplicate key -- already there from a partial earlier run.
            // Treat as no-op, the data is correct.
        }
    }
    echo "  + role_perms for cgrp={$cgrp}: " . count($entries) . " rows\n";
}
echo "  (total {$totalInserted} role_perms rows inserted)\n";

// 6. user_roles.
foreach ($users as $u) {
    $cgrp = (int) $u['groups'];
    if (!isset($cgrpToRoleId[$cgrp])) {
        echo "  ! cid={$u['cid']} {$u['customer']}: groups={$cgrp} has no matching role -- SKIPPED\n";
        continue;
    }
    $roleId = $cgrpToRoleId[$cgrp];
    try {
        $frameworkDb->insert('user_roles', [
            'userID' => (int) $u['cid'],
            'roleID' => $roleId,
        ]);
        echo "  + user_roles: cid={$u['cid']} ({$u['customer']}) -> role id={$roleId}\n";
    } catch (\Throwable $ex) {
        echo "  ! cid={$u['cid']}: insert failed: " . $ex->getMessage() . "\n";
    }
}

echo "\n=== Migration applied. ===\n";

// --- Final verify ---
echo "\nVerify:\n";
$count = $frameworkDb->select("SELECT COUNT(*) AS c FROM roles WHERE module = 'pmwh3'");
echo "  roles (module=pmwh3): " . $count[0]['c'] . "\n";
$rolesIds = array_values($cgrpToRoleId);
$rolesIds[] = $parentId;
$idList = implode(',', array_map('intval', $rolesIds));
$count = $frameworkDb->select(
        "SELECT COUNT(*) AS c FROM role_perms WHERE roleID IN ({$idList})"
);
echo "  role_perms for our roles: " . $count[0]['c'] . "\n";
$count = $frameworkDb->select(
        "SELECT COUNT(*) AS c FROM user_roles WHERE roleID IN ({$idList})"
);
echo "  user_roles for our roles: " . $count[0]['c'] . "\n";
echo "\nDone.\n";
