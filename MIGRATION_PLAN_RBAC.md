# pmwh3 ACL → Framework RBAC Migration Plan

**Status:** ✅ ABGESCHLOSSEN (Etappen 1-4 alle deployed, 4d Cleanup ist der finale Schritt).
**Author:** chris + claude
**Datum:** 2026-05-11 (Plan) → 2026-05-12 (Abschluss)
**Tracking:** dieser Plan ist authoritative. Code wird in Branches dazu erst geschrieben wenn jeweiliger Step abgesegnet ist.

---

## 0. Warum überhaupt

### Heutiges pmwh3-ACL-Modell

Drei Tabellen für ACL plus eine vierte für Customer-Group-Mitgliedschaft:

```
pmwh3_acl_objects     (oid, object, gid)        -- "alle Permissions"
pmwh3_acl_groups      (gid, name, type, parent, creator)
                                                 -- 'cgrp' = Customer-Groups, 'ogrp' = Object-Groups
pmwh3_acl_permissions (cgrp, oid, value Y/N)    -- "diese Customer-Group hat diese Permission"
pmwh3_groups          (gid, name)                -- "Customer-Groups als FK-Target"
                                                 -- in pmwh3_customers.groups referenziert
```

### Drei Probleme

1. **Doppelte Storage**: `pmwh3_groups` und `pmwh3_acl_groups` type='cgrp' sind beide "Customer-Groups". `pmwh3_customers.groups` zeigt auf `pmwh3_groups.gid`, ACL-Permissions zeigen auf `pmwh3_acl_groups.gid`. Beides funktioniert nur wenn die gids zufällig nicht kollidieren. Ungesicherte Konvention.

2. **Reseller-Workflow geht nicht sauber**: ein Reseller, der mehrere Kunden mit unterschiedlichen Berechtigungs-Templates hat, muss eigene Customer-Groups anlegen können. Heutige `pmwh3_groups` hat keine `creator`-Spalte, also keine per-Reseller-Sicht. Plus Namens-Kollision: zwei Reseller wollen beide eine Group "Premium".

3. **Framework hat schon ein RBAC**: `roles`, `permissions`, `role_perms`, `user_roles`, `user_perms` Tabellen plus die `ACL`-Klasse plus ein `rbac` Core-Module mit fertigen Views. pmwh3 baut das parallel nach, was unnötig ist.

### Ziel

pmwh3 nutzt das Framework-RBAC für seine ACL. Eigene `pmwh3_acl_*` und `pmwh3_groups` Tabellen fallen weg.

---

## 1. Konzept-Mapping (alt → neu)

| Heute (pmwh3) | Neu (Framework) | Anmerkung |
|---|---|---|
| `pmwh3_acl_objects.object` | `permissions.permKey` | Mit Prefix `pmwh3.` (z.B. `pmwh3.create_customer`) |
| `pmwh3_acl_objects.gid` (ogrp) | *entfällt* | Filterung läuft über neue `permissions.module` Spalte |
| `pmwh3_acl_groups` type='cgrp' | `roles` | Nested-Set, also Hierarchie für Reseller |
| `pmwh3_acl_groups` type='ogrp' | *entfällt* | s.o. |
| `pmwh3_acl_permissions` | `role_perms` | (roleID, permID, value) |
| `pmwh3_groups` | *entfällt* | `roles` ersetzt es |
| `pmwh3_customers.groups` | `user_roles` | (userID, roleID) -- m:n statt 1:1 |
| `CustomerUtil::hasAccess($k)` | `ACL::hasPermission('pmwh3.'.$k)` | API-Wrapper bleibt in pmwh3 für Übergang |

### Identitäts-Frage

Framework hat eine `user`-Tabelle mit `user_id`. pmwh3 hat `pmwh3_customers.cid`. Das sind heute getrennte Identitäten.

**Entscheidung dieses Plans:** beide Tabellen bleiben. `user_roles.userID` wird **konventionell** auf `pmwh3_customers.cid` gemappt — das geht weil das Framework `userID` nur in zwei Stellen mit `user` joint:
- `ACL::getUserName()` in `acl.php:630` — wird in pmwh3-Kontext nicht aufgerufen
- Framework `rbac` Core-Module — wird in pmwh3-UI nicht eingebunden

**Konsequenz:** Framework-RBAC-UI funktioniert für pmwh3-User nur dann sauber, wenn entweder die `user`-Tabelle parallel zur `pmwh3_customers` befüllt wird, oder die ACL-Bearbeitung nur über die pmwh3-eigene UI läuft. Dieser Plan wählt: **pmwh3 UI bleibt, ruft Framework-RBAC nur intern als API**. Die Framework-Tabellen sind Backend-Storage, nicht direkt UI-exposed.

Eine echte Identitäts-Vereinheitlichung (cid → user_id) wäre ein eigenes Projekt und kein Teil dieses Plans.

---

## 2. Framework-Änderung: Module-Spalte

### 2.1 Schema

```sql
ALTER TABLE permissions
    ADD COLUMN module VARCHAR(64) NOT NULL DEFAULT '__core__' AFTER permKey,
    ADD INDEX permissions_module (module);
```

**Konvention für `module`:**

- `'pmwh3'`, `'qrk'`, `'cevian'`, ... — der jeweilige Modulname für Module-eigene Permissions
- `'__core__'` — Framework-Core-Permissions, also Permissions die nicht aus einem konkreten Modul stammen oder die aus einem Modul stammen das die `module`-Spalte noch nicht setzt (Übergangs-Marker)

Default `'__core__'` heißt: bestehende Permission-Rows ohne explizites Set bleiben als Core-Permissions sichtbar. Module die später migriert werden, schreiben ihren Namen aktiv rein (siehe 2.3).

### 2.2 Framework-API-Änderungen

Minimaler Eingriff in `library/ckvsoft/acl.php`:

- `createPermission(array $data)`: akzeptiert jetzt `module` in `$data`. Default falls nicht gesetzt: `'__core__'`. Kein Verhaltens-Change für bestehende Aufrufe.
- `getAllPerms($format, ?string $module = null)`: optionaler Modul-Filter. Default null = wie bisher (alles). Mit `'__core__'` filtert man explizit auf Core-Permissions, mit `'pmwh3'` auf pmwh3-eigene, usw.

Plus in `core_modules/rbac/controller/rbac.php` + `model/rbac_model.php`:

- `permissionList()` und `editPermission()` bekommen einen Modul-Filter im UI (Dropdown). Default zeigt alles, Auswahl filtert. Diese Änderung kann zeitlich auch später kommen — sie betrifft nur die Framework-Verwaltungsoberfläche, nicht die Funktionalität für pmwh3.

### 2.3 Wo wird `module` geschrieben

pmwh3 stellt sicher dass eigene Permissions immer mit `module='pmwh3'` registriert sind. Wo das passiert:

- Neuer Wrapper `\pmwh3\Utils\Acl::ensurePermission(string $key, ?string $name = null)` ruft `ACL::createPermission(['permKey' => 'pmwh3.'.$key, 'permName' => $name ?? $key, 'module' => 'pmwh3'])`
- Auto-Register im pmwh3-Code geht durch diesen Wrapper.

---

## 3. Datenmigration

### 3.1 Voraussetzung: chris's Live-DB bereinigt

Wie bereits diskutiert (Hotfix99/100 commit message):

```sql
DELETE FROM pmwh3_acl_objects
 WHERE gid = 0
   AND object IN (SELECT object FROM
        (SELECT object FROM pmwh3_acl_objects WHERE gid > 0) AS keep);
ALTER TABLE pmwh3_acl_objects ADD UNIQUE KEY acl_objects_object (object);
```

Muss **vor** der Migration laufen, sonst importieren wir Duplikate ins Framework-Schema.

### 3.2 Migration-SQL (in einem Transaktion-Block oder als Migration-PHP)

**Schritt A: Permissions migrieren**
```sql
INSERT INTO permissions (permKey, permName, module)
  SELECT CONCAT('pmwh3.', object), object, 'pmwh3'
    FROM pmwh3_acl_objects
   WHERE object IS NOT NULL AND object <> ''
ON DUPLICATE KEY UPDATE permName = VALUES(permName), module = 'pmwh3';
```

**Schritt B: Roles aus den Customer-Groups bauen**

Quelle ist `pmwh3_groups` (das die FK von `pmwh3_customers.groups` ist), nicht `pmwh3_acl_groups`. Annahme: keine Hierarchie bei der Erstmigration, also flach. Reseller-Hierarchie wird nachträglich gepflegt.

```sql
-- Roles werden mit Nested-Set lft/rgt versehen — vereinfacht: flach
-- direkt unter einer Root-Role "pmwh3". Konkretes SQL hängt davon ab,
-- wie das Framework Roots verwaltet (Plan: erst Migration-PHP, dann SQL).
INSERT INTO roles (roleName, lft, rgt, depth)
  SELECT g.name, /* lft */ ?, /* rgt */ ?, /* depth */ 1
    FROM pmwh3_groups g
   WHERE g.name IS NOT NULL AND g.name <> '';
```

Da Nested-Set in SQL alleine fummelig ist, wird das in einem **Migration-PHP-Skript** (z.B. `inc/sql/migration_rbac.php`) gemacht: Schleife über `pmwh3_groups`, ruft `ACL::addRole($name, $parentId)` pro Row.

**Schritt C: Role-Perms migrieren**

Vor dem INSERT brauchen wir die Mapping-Tables:
- alte `pmwh3_acl_groups.gid` (cgrp) → neue `roles.id`
- alte `pmwh3_acl_objects.oid` → neue `permissions.id`

Mit den Mappings:

```sql
INSERT INTO role_perms (roleID, permID, value)
  SELECT r.id, p.id, CASE perm.value WHEN 'Y' THEN 1 ELSE 0 END
    FROM pmwh3_acl_permissions perm
    JOIN pmwh3_acl_objects ao   ON ao.oid = perm.oid
    JOIN pmwh3_acl_groups  acg  ON acg.gid = perm.cgrp AND acg.type = 'cgrp'
    JOIN pmwh3_groups      pg   ON pg.name = acg.name           -- name-match cgrp↔pmwh3_groups
    JOIN roles             r    ON r.roleName = pg.name
    JOIN permissions       p    ON p.permKey  = CONCAT('pmwh3.', ao.object)
ON DUPLICATE KEY UPDATE value = VALUES(value);
```

**Wichtig:** das `name-match cgrp↔pmwh3_groups` ist die Brücke zwischen den heute zwei parallelen Group-Tabellen. Geht nur wenn die Namen tatsächlich übereinstimmen. Bei chris's Live-DB ist das vermutlich der Fall (admin, customer, reseller). Wenn nicht, manueller Cleanup vor Migration. Verify-Query siehe Schritt 3.3.

**Schritt D: User-Role Zuordnung**

```sql
INSERT INTO user_roles (userID, roleID, date_added)
  SELECT c.cid, r.id, NOW()
    FROM pmwh3_customers c
    JOIN pmwh3_groups    pg ON pg.gid = c.groups
    JOIN roles           r  ON r.roleName = pg.name
   WHERE c.cid IS NOT NULL;
```

**Schritt E: Alte Tabellen löschen**

Erst nach Verify (3.3). Lassen wir aber **vorerst stehen** als Backup, einfach `RENAME TO pmwh3_acl_objects_OLD_BEFORE_RBAC` etc.

### 3.3 Verify-Queries

Nach Migration, vor Drop der alten Tables:

```sql
-- 1. Count check
SELECT (SELECT COUNT(*) FROM pmwh3_acl_objects) AS old_perms,
       (SELECT COUNT(*) FROM permissions WHERE module='pmwh3') AS new_perms;

-- 2. Count Customer-Groups
SELECT (SELECT COUNT(*) FROM pmwh3_groups) AS old_groups,
       (SELECT COUNT(DISTINCT r.id) FROM roles r WHERE r.roleName IN
            (SELECT name FROM pmwh3_groups)) AS new_roles;

-- 3. Per-User Permissions vergleichen (Stichprobe)
-- Für customer cid=2 -- liste old vs new
SELECT ao.object AS old_perm
  FROM pmwh3_acl_permissions p
  JOIN pmwh3_acl_objects ao ON ao.oid = p.oid
  JOIN pmwh3_customers c    ON c.groups IN (...)  -- via cgrp/pmwh3_groups mapping
 WHERE c.cid = 2 AND p.value = 'Y';

-- vs.

SELECT REPLACE(p.permKey, 'pmwh3.', '') AS new_perm
  FROM user_roles ur
  JOIN role_perms rp ON rp.roleID = ur.roleID
  JOIN permissions p ON p.id = rp.permID
 WHERE ur.userID = 2 AND rp.value = 1;
```

Die zwei Listen müssen identisch sein.

---

## 4. Code-Migration in pmwh3

### 4.1 Neuer Wrapper

`utils/acl.php` (NEU, ersetzt langfristig customerutil.php's hasAccess):

```php
class Acl {
    public static function has(string $key): bool {
        // cid=1 ultimate admin bypass behalten
        $cid = (int) (\ckvsoft\Session::getNs('pmwh3', 'customer_id') ?? 0);
        if ($cid === 1) return true;
        // sonst Framework fragen
        return (new \ckvsoft\ACL($cid))->hasPermission('pmwh3.'.$key);
    }
    public static function ensurePermission(string $key, ?string $name = null): void {
        // Auto-register Pattern wie CustomerUtil::hasAccess heute
        $acl = new \ckvsoft\ACL();
        $acl->createPermission([
            'permKey' => 'pmwh3.'.$key,
            'permName' => $name ?? $key,
            'module' => 'pmwh3',
        ]);
    }
}
```

`ensurePermission()` nicht aus `has()` heraus aufrufen (kein Auto-Register auf jedem `hasPermission`-Call) — sondern entweder durch Migration einmalig, oder durch einen "ACL bootstrap" der beim Modul-Init durch alle bekannten Keys läuft (besser, kein DB-Insert per Request).

### 4.2 Suchen-und-Ersetzen

179 Aufrufe von `CustomerUtil::hasAccess(...)` → `Acl::has(...)` in folgenden Dateien:

```
controller/customer.php      controller/databases.php
controller/domain.php        controller/email.php
controller/ftp.php           controller/group.php
controller/options.php       controller/package.php
controller/tools.php         helper/pmwh3menu_helper.php
model/email_model.php        model/general_model.php
utils/aclmanager.php         utils/customerutil.php
utils/databasemanager.php    utils/ftpmanager.php
utils/objectgroupmanager.php
```

Mechanisch, dann sanity Tests.

`CustomerUtil::hasAccess()` bleibt als Alias / dünner Wrapper für Rückwärtskompatibilität bestehen (delegiert an `Acl::has`). Kann später entfernt werden.

### 4.3 Customer-Group-Verwaltung

`controller/group.php` + `view/group/*` + `utils/groupmanager.php` werden zu UI/Logic für **Framework-Roles**, gefiltert auf "Roles die für pmwh3-Customers sinnvoll sind". Konkret:

- `group/overview` listet `roles` (vermutlich alle, oder mit creator-Filter falls Framework das unterstützt — siehe TODO Reseller-Hierarchie)
- `group/new_group` ruft `ACL::addRole($name, $parentId)`. `$parentId` = Root-Role von pmwh3, oder eine Reseller-Parent-Role
- `group/permissions/$gid` zeigt das pmwh3-Slice der `permissions` (filter `module='pmwh3'`) und liest/schreibt `role_perms` für die gewählte Role
- `group/delete_group` ruft `ACL::deleteRole($id)` (Framework hat schon Schutz für referenzierte Roles)

`pmwh3_customers.groups` wird zu `pmwh3_customers.role_id` (oder bleibt heißend "groups" und zeigt jetzt auf `roles.id` — Konvention-Frage). Falls m:n gewollt: `pmwh3_customers.groups`-Spalte droppen, ausschließlich `user_roles` verwenden.

### 4.4 Object-Groups CRUD

`tools/objgroups`, `tools/edit_objgroup`, `utils/objectgroupmanager.php` **entfernen**. Funktion fällt weg — Filterung passiert über `module` Spalte in `permissions`. Falls Sub-Gruppierung innerhalb pmwh3 gewünscht (z.B. "Mail-Perms", "Domain-Perms"), wäre das eine optionale `permissions.subgroup` Spalte. Vorerst nicht.

### 4.5 ACL-Manager + Permissions-View

`utils/aclmanager.php` + `view/group/permissions.php` werden vom `pmwh3_acl_*`-Schema auf `permissions`+`role_perms`-Schema umgestellt. Self-edit-Rail + Privilege-Escalation-Rail bleiben funktional gleich.

### 4.6 Menu Helper

`helper/pmwh3menu_helper.php` Z53/194: liest `pmwh3_menu.permission` Spalte und ruft `hasAccess($r['permission'])`. Werte sind heute Strings wie `view_menu_customers`. Nach Migration entweder:
- Werte in `pmwh3_menu` umschreiben auf `pmwh3.view_menu_customers`
- Oder Helper macht das Prefix selber: `Acl::has($r['permission'])` was intern `pmwh3.` davorhängt

Zweite Option weniger DB-Touch, bevorzugt.

### 4.7 Login-Flow

`controller/login.php` Z71 setzt `customer_groups` aus `pmwh3_customers.groups` in Session. Bleibt funktional dasselbe, nur Wert zeigt jetzt auf `roles.id` statt `pmwh3_groups.gid`. `customer_groups_key` (HMAC zur Session-Integrität) bleibt unverändert.

---

## 5. Reihenfolge der Umsetzung

Damit Zwischenstände lauffähig sind:

### Etappe 1 — Framework-Vorbereitung
1. `permissions.module` Spalte hinzufügen (`ALTER TABLE`)
2. `ACL::getAllPerms($format, ?$module)` Filter dazu
3. `rbac` Controller UI: Modul-Filter (optional, nicht blockierend)

**Verifikation:** Framework-RBAC-UI funktioniert weiter, neuer Filter aktivierbar.

### Etappe 2 — pmwh3 Acl-Wrapper
1. `utils/acl.php` `Acl::has()` + `Acl::ensurePermission()` schreiben
2. Bootstrap-Skript das alle bekannten pmwh3-Permissions in `permissions` registriert (`module='pmwh3'`), ohne dabei die alte `pmwh3_acl_objects`-Logik anzufassen
3. `CustomerUtil::hasAccess()` so umbauen: schaut zuerst ins neue Framework (falls dort Permission existiert), fallback aufs alte pmwh3_acl_*

**Verifikation:** pmwh3 läuft wie heute, aber Permissions sind doppelt geführt. Beide Datenwege liefern dieselben Ergebnisse.

### Etappe 3 — Datenmigration
1. chris's DB-Cleanup (3.1)
2. Migration-Skript: Permissions, Roles, Role-Perms, User-Roles (3.2 A–D)
3. Verify (3.3)

**Verifikation:** Beide Datenwege identisch. Wenn ja → Etappe 4.

### Etappe 4 — pmwh3 dünn schalten
1. `CustomerUtil::hasAccess()` fallback auf alt **wegnehmen**
2. `Acl::has()` ist die einzige Quelle
3. `controller/group.php` + views auf neues Schema umstellen
4. `controller/tools.php` Object-Groups CRUD entfernen
5. `pmwh3_acl_*` und `pmwh3_groups` Tables: `RENAME TO *_OLD_BEFORE_RBAC`

**Verifikation:** Alle pmwh3-Funktionen, neue Permissions-UI, Reseller-Workflow (manuell mit Test-Reseller).

### Etappe 5 — Cleanup
1. Alte `*_OLD_BEFORE_RBAC` Tables löschen (nach z.B. 1 Woche Beobachtung)
2. `utils/customerutil.php::hasAccess` als deprecated markieren, anschließend droppen
3. `utils/objectgroupmanager.php`, `utils/aclmanager.php` entfernen (Funktionalität ist im Framework)

---

## 6. Offene Fragen / TODO

- **Reseller-Hierarchie**: Framework `roles` ist nested-set, theoretisch perfekt für „Reseller-A's Roles sind Children unter Reseller-A-Root". Aber wer legt diese Sub-Roots an? Wann? In welcher UI? Eigener Punkt nach Etappe 4.
- **user_roles m:n**: heute hat ein pmwh3-Customer **genau eine** Group. Framework `user_roles` ist m:n. Bei Migration zunächst 1:1 (1 Row pro Customer). Soll später m:n erlaubt sein, oder constraint behalten? Default Plan: 1:1.
- **`user_perms` (User-Overrides)**: Framework unterstützt "diesem User zusätzlich/abzüglich diese Permission". pmwh3 nutzt das heute nicht. Nicht migrieren, aber API ist da wenn gewünscht.
- **`permission.is_used` Flag**: Framework hat es, sagt vermutlich "wird irgendwo geprüft / kann manuell zurückgesetzt werden". Wert beim Auto-Register: 1.
- **Framework-RBAC-UI für pmwh3-Customer**: heute zeigt das Framework-UI Roles+Permissions ohne Wissen über pmwh3. Will man die Framework-UI von pmwh3 aus zugänglich machen? Plan: **nicht jetzt**. pmwh3 hat eigene UI (group/permissions). Falls später, dann unter einem eigenen Menüpunkt mit eigener Berechtigung.

---

## 7. Was zuerst gemacht wird

**Wenn dieser Plan abgesegnet ist:** Etappe 1 als erstes (Framework-Änderung, minimal-invasiv, kann separat geprüft werden). Anschließend Etappe 2.

Jede Etappe = eigener Hotfix-ZIP mit eigener Commit-Message und eigenen Verify-Schritten.

---

## 8. Abschluss-Log (was tatsächlich passiert ist)

### Etappe 1 — Framework module-Spalten (3.0.59 cevian)
Ein einzelner `0.19.0.sql` mit ALTER auf `permissions` UND `roles`
(beide bekommen `module VARCHAR(64) NOT NULL DEFAULT '__core__'`).
ACL-Klasse erhält 3. Parameter `$module` in `addRole()` und
Filter-Argumente in `getAllPerms()` / `getAllRoles()`.

### Etappe 2 — `\pmwh3\Utils\Acl` Wrapper + Bootstrap (pmwh3 3.0.59)
Wrapper-Klasse für Framework-ACL mit pmwh3-Conventions (`pmwh3.` Prefix, `module='pmwh3'`).
CLI-Script `scripts/bootstrap_rbac.php` registriert 87 bekannte Permission-Keys.
`CustomerUtil::hasAccess()` Mirror-on-auto-register: neue auto-detected Permissions
landen automatisch auch im Framework.

### Etappe 3 — Daten-Migration (pmwh3 3.0.60)
`scripts/migrate_rbac.php` (dry-run by default, `--apply` zum Commit) migriert:
- 5 Customer-Groups aus `pmwh3_acl_groups type='cgrp'` → `roles` mit `module='pmwh3'`
  unter einem Parent-Role 'pmwh3'.
- 168 role_perms aus `pmwh3_acl_permissions` (dedupe + INSERT IGNORE).
- 2 user_roles aus `pmwh3_customers` (cid=1→Ultimate Admin, cid=23→Customer).
- Rollback-Script `scripts/migrate_rbac_rollback.php` für Notfall.

### Etappe 4a — Runtime-Flip (pmwh3 3.0.61)
`Acl::has()` als der neue Permission-Check, liest aus Framework `role_perms` mit
cid-Identity-Bridge. `CustomerUtil::hasAccess()` wird Thin-Alias. cid=1-Bypass
mit Auto-Register-Mirror bleibt erhalten.

### Etappe 4b — Group-Identität (pmwh3 3.0.62)
`pmwh3_customers.groups` → `role_id` umbenannt. Werte gemappt von alten gids auf
roles.id per Cross-DB-Lookup. `GroupManager` komplett umgeschrieben auf Framework
`roles`. Session-Keys `customer_groups`/`customer_groups_key`/`customer_group_key`
→ `role_id`/`role_id_key`. Login + AuthMiddleware durchgereicht.

### Etappe 4c — Permissions-UI auf Framework (pmwh3 3.0.64)
`AclManager` komplett neu auf framework `role_perms`. UI-Kategorien aus Permission-
Prefix abgeleitet (hardcoded `CATEGORIES` Mapping) statt Object-Group-Tabelle.
View `view/group/permissions.php` als Akkordeon mit `<details>` Blocks neu.
Object-Groups-CRUD entfernt: `view/tools/objgroups.php`,
`view/tools/edit_objgroup.php`, `utils/objectgroupmanager.php` gelöscht; alle
`controller/tools.php :: *_objgroup` Methoden raus.

### Etappe 4d — Final DB-Cleanup (pmwh3 3.0.67)
`baseline.sql` aufgeräumt: keine `pmwh3_acl_*` und `pmwh3_groups` CREATE TABLEs
mehr, keine Seed-INSERTs. `MIGRATION_4D_SQL.md` mit den DROP-Befehlen + Backup-
Anleitung + Cleanup für orphan framework-Permissions (objgroup-Reste).

Plus: dieser Plan ist jetzt **abgeschlossen**. Künftige RBAC-Änderungen sind
normale Framework-Erweiterungen und brauchen keinen Plan dieser Größe mehr.
