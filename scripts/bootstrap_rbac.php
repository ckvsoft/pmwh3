<?php

/**
 * pmwh3 RBAC bootstrap.
 *
 * One-shot CLI utility for Etappe 2 of the migration plan
 * (MIGRATION_PLAN_RBAC.md). Walks through the list of pmwh3
 * permission keys we want to live in the framework's
 * `permissions` table, and ensures each one is registered with
 * module='pmwh3' and the 'pmwh3.' key prefix.
 *
 * Safe to re-run -- ensurePermissions only inserts rows that
 * aren't there yet (relies on the framework's UNIQUE KEY on
 * permKey).
 *
 * Usage:
 *   php scripts/bootstrap_rbac.php           # from within the
 *                                              pmwh3 module dir
 *
 * The script needs Cevian's autoload to be wired with BOTH the
 * library/ and modules/ base dirs so \ckvsoft\ACL and
 * \pmwh3\Utils\Acl both resolve. It then mimics the parts of
 * the normal request bootstrap we care about (i18n init).
 */

declare(strict_types=1);

// Locate the framework root (.../service/cevian/) by walking up from
// scripts/. The module sits at modules/pmwh3/scripts/, so three
// levels up is the cevian root.
$here = __DIR__;
$frameworkRoot = realpath($here . '/../../../');
if (!$frameworkRoot || !is_file($frameworkRoot . '/library/ckvsoft/autoload.php')) {
    fwrite(STDERR, "ERR: cannot find framework relative to {$here}\n");
    fwrite(STDERR, "     expected: <here>/../../../library/ckvsoft/autoload.php\n");
    exit(2);
}

// 1) Bring in the Autoload class definition.
require_once $frameworkRoot . '/library/ckvsoft/autoload.php';

// 2) Register the spl_autoload with BOTH base dirs:
//    - library/  for \ckvsoft\* classes
//    - modules/  for \pmwh3\* classes (our own utils/acl.php etc.)
$autoload = new \ckvsoft\Autoload([
    $frameworkRoot . '/library',
    $frameworkRoot . '/modules',
]);

// 3) Mimic the bits of the normal request bootstrap that matter
//    for us. modulautoload.php inits i18n (so __() works) -- that
//    we want. Other parts (controller dispatch etc.) we don't need.
$moduleRoot = realpath($here . '/..');
if (is_file($moduleRoot . '/modulautoload.php')) {
    require_once $moduleRoot . '/modulautoload.php';
}

// =====================================================================
// The list of pmwh3-owned permission keys.
//
// Bare keys (no prefix). The Acl wrapper adds 'pmwh3.' on insert.
// Source: pmwh3 inc/sql/0.0.0_baseline.sql (acl_objects seed) plus
// any keys auto-registered at runtime by CustomerUtil::hasAccess().
//
// Re-runnable: missing keys get inserted, existing keys stay put.
// =====================================================================
$keys = [
    // ACL itself
    'view_permissions', 'change_permissions',

    // Customers
    'view_customers', 'view_customer_limits',
    'create_customer', 'edit_customer', 'delete_customer',

    // Customer groups (cgrp)
    'view_groups',
    'create_group', 'edit_group', 'delete_group',

    // Object groups (ogrp) -- legacy from pre-RBAC. Removed in
    // Etappe 4c when Object Groups went away as a UI concept.
    // Kept here as a comment so the diff explains the absence.
    // 'view_objgroups', 'view_all_object_groups',
    // 'create_objgroup', 'edit_objgroup', 'delete_objgroup',

    // Domains
    'view_domains', 'view_domain_advanced',
    'create_domain', 'edit_domain', 'edit_domain_advanced', 'delete_domain',
    'view_subdomain', 'create_subdomain', 'edit_subdomain', 'delete_subdomain',
    'view_subdomain_alias', 'create_subdomain_alias',
    'edit_subdomain_alias', 'delete_subdomain_alias',

    // Email
    'view_email',
    'create_email', 'edit_email', 'delete_email',
    'create_email_filtering', 'edit_email_filtering', 'delete_email_filtering',
    'create_email_wblist', 'edit_email_wblist', 'delete_email_wblist',

    // FTP
    'view_ftp',
    'create_ftp', 'edit_ftp', 'delete_ftp',

    // Databases
    'view_databases',
    'create_database', 'edit_database', 'delete_database',

    // Packages
    'view_packages',
    'create_package', 'edit_package', 'delete_package',

    // Tools / News / Applications / Backup / Errorlog
    'view_news',         'create_news',        'edit_news',     'delete_news',
    'view_applications', 'create_application', 'edit_application', 'delete_application',
    'view_backup',       'create_backup',
    'view_errorlog',
    'view_tools_menu',   'create_menu_row',    'edit_menu_row', 'delete_menu_row',

    // Messages
    'delete_message',

    // Menu visibility
    // Note: the singular legacy keys (view_menu_customer,
    // view_menu_database, view_menu_database_overview,
    // view_menu_domain, view_menu_domain_overview) were dropped
    // here -- they're nowhere in the codebase nor in pmwh3_menu.
    // See MIGRATION_SINGULAR_CLEANUP.md for the manual DELETE
    // statements to scrub them from existing installs.
    'view_menu_customers', 'view_menu_customers_overview',
    'view_menu_databases', 'view_menu_databases_overview', 'view_menu_databases_phpmyadmin',
    'view_menu_domains', 'view_menu_domains_overview',
    'view_menu_email', 'view_menu_email_overview',
    'view_menu_email_catchall', 'view_menu_email_email',
    'view_menu_email_filtering', 'view_menu_email_forward',
    'view_menu_email_wblist',
    'view_menu_ftp', 'view_menu_ftp_overview', 'view_menu_ftp_accounts',
    'view_menu_general_overview', 'view_menu_general_password',
    'view_menu_general_sessions', 'view_menu_general_traffic',
    'view_menu_general_messages',
    // Box-header permissions. The menu-helper since 3.0.72 no
    // longer gates on these (a box is shown whenever any child
    // is visible), but they still appear as toggleable rows in
    // the menu CRUD UI's "permission" column. Registering them
    // here means admins can see and assign them through the
    // Permissions admin UI like any other permission.
    'view_menu_general',
    'view_menu_contributions',
    'view_menu_options',
    'view_menu_tools', 'view_menu_tools_overview',
    'view_menu_tools_applications', 'view_menu_tools_backup',
    'view_menu_tools_errorlog', 'view_menu_tools_menu',
    'view_menu_tools_news',
];

echo "pmwh3 RBAC bootstrap: registering " . count($keys) . " permission keys with module='pmwh3'\n";

$created = \pmwh3\Utils\Acl::ensurePermissions($keys);

echo "Done. {$created} new permission(s) inserted, " . (count($keys) - $created) . " already present.\n";

// Helpful summary of what's now in the framework table for pmwh3.
$owned = \pmwh3\Utils\Acl::listOwnedPermissions();
echo "Framework now has " . count($owned) . " permission(s) with module='pmwh3'.\n";
