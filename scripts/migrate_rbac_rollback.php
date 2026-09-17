<?php

/**
 * Rollback for scripts/migrate_rbac.php (Etappe 3 of
 * MIGRATION_PLAN_RBAC.md).
 *
 * Removes every roles row with module='pmwh3' plus the related
 * role_perms and user_roles. Leaves the framework `permissions`
 * table alone (those came in Etappe 2 and are still wanted).
 *
 * Use case: the migration ran partially and you want to start
 * fresh. Permissions in pmwh3_acl_* are untouched and remain
 * the system of record until Etappe 4.
 *
 * Usage:
 *   php scripts/migrate_rbac_rollback.php             # dry-run
 *   php scripts/migrate_rbac_rollback.php --apply     # really wipe
 */

declare(strict_types=1);

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

$apply = in_array('--apply', $argv, true);
$mode = $apply ? 'APPLY' : 'DRY-RUN';
echo "=== pmwh3 RBAC rollback ({$mode}) ===\n\n";

$frameworkDb = \ckvsoft\mvc\Config::db();

// What's there to remove
$roles = $frameworkDb->select(
        "SELECT id, roleName FROM roles WHERE module = 'pmwh3' ORDER BY lft"
);
if (empty($roles)) {
    echo "Nothing to do -- no roles with module='pmwh3'.\n";
    exit(0);
}
$ids = array_map(fn($r) => (int) $r['id'], $roles);
$idList = implode(',', $ids);
echo "Roles to remove: " . count($roles) . "\n";
foreach ($roles as $r) {
    echo "  id={$r['id']} {$r['roleName']}\n";
}

$rp = $frameworkDb->select("SELECT COUNT(*) AS c FROM role_perms WHERE roleID IN ({$idList})");
$ur = $frameworkDb->select("SELECT COUNT(*) AS c FROM user_roles WHERE roleID IN ({$idList})");
echo "  role_perms rows tied to these: " . $rp[0]['c'] . "\n";
echo "  user_roles rows tied to these: " . $ur[0]['c'] . "\n\n";

if (!$apply) {
    echo "(dry-run -- nothing written. Re-run with --apply.)\n";
    exit(0);
}

echo "Removing...\n";

// Order matters because of FK-style integrity even without FKs:
// 1) user_roles -- references roleID
// 2) role_perms -- references roleID
// 3) roles -- the thing itself
$frameworkDb->delete('user_roles', "roleID IN ({$idList})", []);
$frameworkDb->delete('role_perms', "roleID IN ({$idList})", []);
$frameworkDb->delete('roles',      "id IN ({$idList})",      []);

// Note: we do NOT use ACL::deleteRole here because that's a
// proper nested-set delete (shifts lft/rgt of siblings/ancestors),
// and our pmwh3 subtree is isolated under one parent root. The
// straight delete leaves a gap in lft/rgt numbering which is
// harmless for nested-set queries (they only care about ranges,
// not contiguity).

echo "Done. Now roles has 0 rows with module='pmwh3'.\n";
echo "Re-run scripts/migrate_rbac.php --apply to redo the migration.\n";
