# Bereinigung der Singular-Permission-Altlasten

In der framework `permissions` Tabelle haben sich Singular-Versionen
einiger Menü-Permission-Keys angesammelt die nirgends mehr verwendet
werden:

* `pmwh3.view_menu_customer`            (aktuell: `view_menu_customers`)
* `pmwh3.view_menu_database`            (aktuell: `view_menu_databases`)
* `pmwh3.view_menu_database_overview`   (aktuell: `view_menu_databases_overview`)
* `pmwh3.view_menu_domain`              (aktuell: `view_menu_domains`)
* `pmwh3.view_menu_domain_overview`     (aktuell: `view_menu_domains_overview`)

Sie sind in der `bootstrap_rbac.php` Liste ab 3.0.77 entfernt;
für bestehende Installationen die manuelle Bereinigung.

## 1. Erst prüfen ob irgendwo zugewiesen

Falls die toten Keys jemanden zugewiesen sind, sind die Zuweisungen
genau so tot — aber zur Sicherheit anschauen:

```sql
-- In cevian-DB
SELECT rp.roleID, r.roleName, p.permKey
  FROM role_perms rp
  JOIN permissions p ON p.id = rp.permID
  JOIN roles r       ON r.id = rp.roleID
 WHERE p.module = 'pmwh3'
   AND p.permKey IN (
       'pmwh3.view_menu_customer',
       'pmwh3.view_menu_database',
       'pmwh3.view_menu_database_overview',
       'pmwh3.view_menu_domain',
       'pmwh3.view_menu_domain_overview'
   );

SELECT up.userID, p.permKey
  FROM user_perms up
  JOIN permissions p ON p.id = up.permID
 WHERE p.module = 'pmwh3'
   AND p.permKey IN (
       'pmwh3.view_menu_customer',
       'pmwh3.view_menu_database',
       'pmwh3.view_menu_database_overview',
       'pmwh3.view_menu_domain',
       'pmwh3.view_menu_domain_overview'
   );
```

Erwartung: beides leer. Falls nicht, schreibst du dir die roleID/userID
auf und checkst manuell ob diese Rolle/User die Plural-Variante auch
hat (vermutlich schon).

## 2. Löschen

```sql
-- In cevian-DB. Cascade-Reihenfolge: erst Zuweisungen, dann die Perm
-- selbst (keine FK-Constraints in dieser DB).

DELETE rp
  FROM role_perms rp
  JOIN permissions p ON p.id = rp.permID
 WHERE p.module = 'pmwh3'
   AND p.permKey IN (
       'pmwh3.view_menu_customer',
       'pmwh3.view_menu_database',
       'pmwh3.view_menu_database_overview',
       'pmwh3.view_menu_domain',
       'pmwh3.view_menu_domain_overview'
   );

DELETE up
  FROM user_perms up
  JOIN permissions p ON p.id = up.permID
 WHERE p.module = 'pmwh3'
   AND p.permKey IN (
       'pmwh3.view_menu_customer',
       'pmwh3.view_menu_database',
       'pmwh3.view_menu_database_overview',
       'pmwh3.view_menu_domain',
       'pmwh3.view_menu_domain_overview'
   );

DELETE FROM permissions
 WHERE module = 'pmwh3'
   AND permKey IN (
       'pmwh3.view_menu_customer',
       'pmwh3.view_menu_database',
       'pmwh3.view_menu_database_overview',
       'pmwh3.view_menu_domain',
       'pmwh3.view_menu_domain_overview'
   );
```

## 3. Verify

```sql
SELECT permKey FROM permissions
 WHERE module = 'pmwh3'
   AND permKey LIKE '%view_menu_customer%'
   OR  permKey LIKE '%view_menu_domain%'
   OR  permKey LIKE '%view_menu_database%';
```

Sollten nur die Plural-Versionen erscheinen.
