# Etappe 4b — Manual SQL

Run these in phpMyAdmin against the pmwh3 module database.
Order matters (column added first, then populated, then old
column dropped). The `cevian` database name in the SELECT
subquery has to match your actual framework database name.

## chris's setup-specific values

Before running, the pre-condition is:
- Cevian Etappe 1 applied (`module` column on `permissions` + `roles`)
- pmwh3 Etappe 2 applied (`bootstrap_rbac.php` was run)
- pmwh3 Etappe 3 applied (`migrate_rbac.php --apply` was run)
- `roles` table has `pmwh3` parent + 5 children (Ultimate Admin,
  Reseller, Customer, Demo, Admin) all with `module='pmwh3'`

Expected starting state of pmwh3_customers:
```
cid=1  admin   groups=1   (was pointing at old pmwh3_groups.gid=1)
cid=<CID> <CUSTOMER> groups=3   (was pointing at old pmwh3_groups.gid=3)
```

After this migration:
```
cid=1  admin   role_id=13  (points at roles.id=13 = Ultimate Admin)
cid=<CID> <CUSTOMER> role_id=17  (points at roles.id=17 = Customer)
```

## SQL

```sql
-- 1. Add the new column.
ALTER TABLE `pmwh3_customers`
    ADD COLUMN `role_id` INT(11) NOT NULL DEFAULT 0 AFTER `groups`,
    ADD INDEX `pmwh3_customers_role` (`role_id`);

-- 2. Populate from old groups value, mapping by name through
--    the framework roles table.
--      old gid 1 (PMWH Admin)  -> roles 'Ultimate Admin'
--      old gid 2 (Reseller)    -> roles 'Reseller'
--      old gid 3 (Customer)    -> roles 'Customer'
--      anything else           -> roles 'Reseller' (safe default)
UPDATE `pmwh3_customers` c
   SET c.`role_id` = COALESCE(
       (SELECT r.id
          FROM `cevian`.`roles` r
         WHERE r.module = 'pmwh3'
           AND r.depth = 1
           AND r.roleName = CASE c.`groups`
               WHEN 1 THEN 'Ultimate Admin'
               WHEN 2 THEN 'Reseller'
               WHEN 3 THEN 'Customer'
               ELSE 'Reseller'
           END
         LIMIT 1),
       0
   )
 WHERE c.`groups` IS NOT NULL;

-- 3. Verify before dropping the old column.
SELECT cid, customer, `groups`, role_id FROM pmwh3_customers;
-- Should show role_id correctly populated (13/17 in chris's case)

-- 4. Drop the old column.
ALTER TABLE `pmwh3_customers`
    DROP COLUMN `groups`;
```

## Why no FOREIGN KEY

`pmwh3_customers` lives in the pmwh3 module DB, framework
`roles` lives in the cevian DB. Different databases, no FK
constraint possible. The relationship is application-level
only; `GroupManager::delete()` refuses to drop a group with
members for that reason.

## Order matters

The new Etappe 4b code expects `pmwh3_customers.role_id` and
will fail (column not found) until step 1 runs. Likewise the
old code is still happy with `pmwh3_customers.groups` and will
keep reading from it. Run the SQL as a single block in one
phpMyAdmin tab; don't leave the DB in a half-state.
