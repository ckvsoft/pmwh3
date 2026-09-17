# Etappe 4d — Final DB cleanup

Final step of the pmwh3 ACL → framework RBAC migration. Drops
the now-unused legacy tables and cleans up orphaned framework
permission rows for things that no longer exist as UI concepts.

Pre-condition: Etappes 1, 2, 3, 4a, 4b, 4c all deployed and
verified working. The code at this version does not read or
write any of the tables being dropped here.

## 1. Backup first

Before dropping anything, take a snapshot. From the shell:

```bash
mysqldump -u <user> -p pmwh3 \
    pmwh3_acl_groups \
    pmwh3_acl_objects \
    pmwh3_acl_permissions \
    pmwh3_groups \
  > /tmp/pmwh3_rbac_legacy_$(date +%F).sql
```

Keep that file somewhere safe. If something goes wrong, you can
restore the 4 tables and revert the code. Once 4d has been
running fine for a while, you can delete the backup.

## 2. Drop the legacy tables

In the pmwh3 module DB:

```sql
DROP TABLE IF EXISTS `pmwh3_acl_permissions`;
DROP TABLE IF EXISTS `pmwh3_acl_objects`;
DROP TABLE IF EXISTS `pmwh3_acl_groups`;
DROP TABLE IF EXISTS `pmwh3_groups`;
```

## 3. Clean up orphan framework permissions (optional)

The framework `permissions` table still has rows for the
object-group concept that went away in 4c. Nothing reads or
writes them any more, so they're harmless -- but they show up
in the rbac UI's permission listing and clutter the picture.

In the FRAMEWORK DB (cevian):

```sql
-- Object Groups feature is gone -> drop its permissions.
-- Also drops any role_perms rows that referenced them.
DELETE rp
  FROM role_perms rp
  JOIN permissions p ON p.id = rp.permID
 WHERE p.module = 'pmwh3'
   AND p.permKey IN (
       'pmwh3.view_objgroups',
       'pmwh3.view_all_object_groups',
       'pmwh3.create_objgroup',
       'pmwh3.edit_objgroup',
       'pmwh3.delete_objgroup',
       'pmwh3.change_objgroup',
       'pmwh3.view_menu_tools_objgroups'
   );

DELETE FROM permissions
 WHERE module = 'pmwh3'
   AND permKey IN (
       'pmwh3.view_objgroups',
       'pmwh3.view_all_object_groups',
       'pmwh3.create_objgroup',
       'pmwh3.edit_objgroup',
       'pmwh3.delete_objgroup',
       'pmwh3.change_objgroup',
       'pmwh3.view_menu_tools_objgroups'
   );
```

## 4. Verify

In pmwh3 DB:
```sql
SHOW TABLES LIKE 'pmwh3_acl_%';   -- empty
SHOW TABLES LIKE 'pmwh3_groups';  -- empty
```

In cevian DB:
```sql
SELECT permKey FROM permissions
 WHERE module = 'pmwh3' AND permKey LIKE '%objgroup%';
-- empty
```

## 5. Done

That's the migration. From this point on:

* Permissions are stored in `cevian.permissions` (module='pmwh3').
* Customer-groups are stored in `cevian.roles` (module='pmwh3'),
  as children of the 'pmwh3' parent role.
* The role grants live in `cevian.role_perms`.
* `pmwh3_customers.role_id` points at `cevian.roles.id`.
* All permission checks go through `\pmwh3\Utils\Acl::has()`.
* `CustomerUtil::hasAccess()` is a thin alias for backwards
  compatibility.
