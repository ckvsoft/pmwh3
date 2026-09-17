# pmwh3 — PHP MyWebHosting Control Panel

PHP-basiertes Hosting-Control-Panel als Cevian-Modul. Verwaltet Customers, Domains, Email-Konten, Datenbanken, FTP-Accounts und liefert Reseller-Hierarchie mit Quotas und Paketen.

**Aktuelle Version: **3.0.82**

> Hinweis: Die alte Setup-Check-Doku wurde nach `SYSCHECK.md` umbenannt.

---

---

## Installation (Frisch-Install, ein Befehl → Wizard)

1. **Cevian installiert haben** (Voraussetzung!) — das pmwh3-Modul lebt
   im Cevian-Release-Tree:
   ```bash
   cp -r pmwh3 /path/to/cevian/modules/
   ```
   (`/path/to/cevian` ist dabei der Cevian-Tree, z.B.
   `/vhome/<host>/<vhost>/service/cevian`).

2. **URL im Browser öffnen** — der erste pmwh3-Seitenaufruf leitet
   auf `pmwh3/install` um (AuthMiddleware-Gate), wenn
   - die `module.json` noch Platzhalter-Creds trägt, oder
   - die pmwh3-DB-Tabellen (Baseline) noch fehlen.

3. **Install-Wizard** (`pmwh3/install`) ausfüllen:
   - Modul-DB-Credentials (Host / DB-Name / User / Pass — wird in
     `modules/pmwh3/module.json` geschrieben, Felder dort aus
     `DB_HOST` / `DB_NAME` / `DB_USER` / `DB_PASS` Platzhaltern
     ersetzt)
   - DNS-DB: `same as module DB`-Checkbox (fresh install) ODER
     eigene `dns.database`-Node-Values (bestehende pdns-DB mit
     Zonen, z.B. Trennung wie in der Live-Umgebung)
   - **admin-Kennwort** — das Wizard legt den Ultimate-Admin-Kunden
     an (limits alle -1)
   - POST → Baseline (0.0.0_baseline.sql) als FRESH-INSTALL
     (nur die Baseline; Migrations-Kette wird als bereinigt
     gestempelt — kein Legacy-ACL-Abort), RBAC-Rollen pmwh3 /
     Ultimate Admin / Reseller / Customer, 95 Berechtigungs-Slots,
     `pmwh3_mail_*/pmwh3_web_*/pmwh3_ftp_*`-Konsolidierungstabelle —
     alles automatisch.

4. **Login** unter `pmwh3/login` als `admin` bzw. eigener Kunde.

> Der Installer ist auch **idempotent** — Re-Run fest, kein zweites
> Admin-Insert, keine doppelten Berechtigungen (0 new keys beim Re-
> run). Bei fehlgeschlagenem ersten Versuch einfach aufrufen.

Alles weitere (Formularwerte, Datei-Layout) in
`/modules/pmwh3/config/` und `SYSCHECK.md`.

---

## Voraussetzungen

- **Cevian** ≥ `0.18.3` (Voraussetzung) — pmwh3 ist ein Cevian-Modul
  und braucht:
  - `Config::moduleDb($module, $configPath)` + `Config::cachedDatabase`
    (0.18.3, DB-Architektur)
  - `Database::execDdl/tableExists/...` Helfer (0.18.3)
  - den **Updater Fresh-Path** (nur Baseline für fresh installs)
    + `SORT_NATURAL`-Migrations-Ordnung (0.18.4)
- PHP ≥ 8.0 mit `pdo`, `pdo_mysql`, `mbstring` (siehe `SYSCHECK.md`)
- MariaDB / MySQL ≥ 10.x (MariaDB 10.4+ tested)
- Eigene MySQL/MariaDB-Datenbank (`pmwh3`) — und optional mehr
  siehe Service-DB unten

---

## Service-DBs (mail / web / ftp / dns)

`module.json` kennt bis zu vier Datenbank-Zugänge. Pro SERVICE gilt:
**eine konsolidierte pmwh3_*-Tabellenfamilie** — keine ttrennung
per vendor (postfix_users etc. gehören DEM Postfix, die müssen
nicht in's pmwh3-DB). Die Daemon-Configs (postfix/dovecot/proftpd)
lesen die pmwh3_*-Tabellen direkt aus ihrer DB — mit den
verhaltens-kompatiblen Templates unter `contrib/`.

**Frisch-Install (Standard): alles in EINER DB** — nur der
Modul-DB-Block und die DNS-DB (fresh-Install-Wizard erzeugt genau
diese Form):

```json
{
    "name": "pmwh3",
    "version": "<VERSION>",
    "core": false,
    "database": {
        "type": "mysql", "host": "localhost", "name": "pmwh3",
        "user": "DB_USER", "pass": "DB_PASS"
    },
    "dns": {
        "table_prefix": "",
        "database": {
            "type": "mysql", "host": "localhost", "name": "pdns",
            "user": "DNS_USER", "pass": "DNS_PASS"
        }
    }
}
```

**Getrennte Service-DBs** —	nur wenn ein Service wirklich eine
EIGENE Datenbank braucht (z.B. bestehende Legacy-Mail-Stack-DB,
der proftpd/Postfix portions ihr контейner-DB gelesen haben).
Fehlt ein Node, fällt der Adapter auf die Modul-DB zurück —
identische Cred-Werte wie der Modul-DB-Block sind dann
REDUNDANT und stehen weg:

```json
{
    "mail": { "database": { "type": "mysql", "host": "localhost",
              "name": "mailstack", "user": "MAIL_USER", "pass": "MAIL_PASS" } },
    "web":  { "database": { "type": "mysql", "host": "localhost",
              "name": "webstack", "user": "...", "pass": "... } },
    "ftp":  { "database": { "type": "mysql", "host": "localhost",
              "name": "ftpstack", "user": "FTP_USER", "pass": "FTP_PASS" } }
}
```
(nur die Betreffenden Nodes, die wirklich abweichen — `dns.database`
ist der bislang etablierte Sonderfall; mail/web/ftp folgen demselben
Muster.)

**Mail-Kontingent:** Zuweisen liegt als `quota_bytes` in
`pmwh3_mail_accounts`; der VERBRAUCH (`used_bytes`/`used_messages`)
wird vom Quota-Treiber des Mail-Systems direkt in dieselbe Zeile
geschrieben — **keine separate Vendor-Tabelle** (früher
`dovecot_quota`). Wo der Adapter einen Live-Endpoint unterstützt
(z.B. {dovecot} doveadm HTTP API), zeigt pmwh3 die Live-Werte mit
Fallback auf die Row-Spalten; Templates: `contrib/`.

- **Fehlt ein Node** (z.B. `mail`), fällt der betreffende Adapter auf
  die pmwh3-Modul-DB zurück (fresh-install default: alles in einer DB).
  die pmwh3-Modul-DB zurück (fresh-install default: alles in einer DB).
- Ist ein Node vorhanden aber **unvollständig** (type/host/name/user/
  pass leer), ist das ein harter Fehler (kein Silent-Fallback).
- Im Install-Wizard können die Nodes,
  (`mail.database`/`web.database`/`ftp.database`), auch später
  manuell in `module.json` gesetzt werden — die Daemon-Configs
  (postfix/dovecot/proftpd) müssen in demselben Umzug die pmwh3_*-
  Tabellen lesen (sonst weiß die Mailbox consumer nichts von pmwh3-
  Neu-Schreibungen — die Migration 3.0.82 erledigt das pmwh3-interne
  Umbenennen; die Daemon-Configs umstellen ist ein manuelles
  Deploy-Schritt, Templates unter `contrib/`).

---

## Konfiguration

`module.json` definiert die Modul-DB und optional Adapter-Konfigurationen
(für den Modul-DB-block -- die Service-Nodes siehe oben):

```json
{
    "database":   { "type": "mysql", "host": "localhost",
                    "name": "pmwh3",
                    "user": "DB_USER", "pass": "DB_PASS" }
}
```

---

## Architektur

### Schichten

```
controller/   HTTP-Routing, Permission-Gates, View-Rendering
   └─ delegiert Business-Operationen an utils/*Manager
model/        Daten für Views; dünne Wrapper um Manager + UI-spezifische Queries
utils/        Stateless Business Logic (Manager, Util, Adapter)
   └─ aufrufbar aus Controller, Scripts, anderen Modulen
view/         Reine Präsentation. Kein DB-Zugriff, keine Geschäftslogik.
config/       Modul-Konfiguration (Version, Settings-Schema, Lazy-Config)
helper/       View-/Menu-Helfer
i18n/         Lokalisierung (Pmwh3I18n)
scripts/      CLI-Tools (z.B. Migration aus pmwh2)
```

### Hauptklassen

| Klasse | Aufgabe |
|---|---|
| `CustomerManager` | Customer-CRUD: getById/getByName/listAll/listVisible/create/update/delete/changePassword/verifyPassword |
| `CustomerUtil` | ACL/Hierarchie: hasAccess, getCustomerHierarchy, getCustomerNameById |
| `PackageManager` | Hosting-Pakete CRUD |
| `GroupManager` | Customer-Gruppen CRUD |
| `MessageManager` | Customer-Nachrichten (inboxFor, sentBy, send, markRead, delete) |
| `MailManager` | Email-Konten / Forwards / Catchalls (delegiert an Mail-Adapter) |
| `DnsManager` | DNS-Records (delegiert an DNS-Adapter, z.B. PowerDNS) |
| `FsManager` | Verzeichnisse anlegen/löschen (delegiert an FS-Adapter) |
| `CountingUtil` | Quota-Berechnung pro Customer |
| `SizeConverter` | Bytes/MB/GB-Konvertierung |
| `AuthMiddleware` | Login-Check + ActivityTracker-Hook |
| `ActivityTracker` | Schreibt `pmwh3_activity` bei jedem authenticated Request |
| `AdapterRegistry` | Discovery für Mail/DNS/FS-Adapter |

### Adapter-Pattern

Mail-, DNS- und FS-Operationen sind über Adapter abstrahiert:

```
utils/mail/   MailAdapterInterface  +  z.B. PostfixAdapter, ExchangeAdapter
utils/dns/    DnsAdapterInterface   +  z.B. PdnsAdapter, MydnsAdapter
utils/fs/     FsAdapterInterface    +  LocalFsAdapter, RemoteFsAdapter
```

Welcher Adapter aktiv ist, steuert die Options-UI bzw. die
`pmwh3_configuration`-Tabelle (`MAIL_TYPE`, `DNS_TYPE`, `FS_TYPE`).
In `module.json` stehen nur die Verbindungs-Daten zu DNS / Mail
(`dns.database`, `dns.table_prefix`, ...).

### Einen neuen DNS-Adapter schreiben

Kurzreferenz für weitere Backends (BIND/file, Cloud-APIs, weitere
SQL-Schemata wie MyDNS, ...). Datei nach `modules/pmwh3/utils/dns/`
legen — Discovery (`AdapterRegistry::classes` via `DnsManager`) findet
alle nicht-abstract Klassen, die `DnsAdapterInterface` implementieren.

```php
<?php
// modules/pmwh3/utils/dns/MyBackendAdapter.php
namespace pmwh3\Utils\Dns;

class MyBackendAdapter extends AbstractDnsAdapter
{
    public static function getKey(): string  { return 'mybackend'; }   // = DNS_TYPE Wert
    public static function getName(): string { return 'MyBackend'; }    // Dropdown-Label

    public static function isAvailable(): bool {
        // z.B. Service/Schema-Erkennung; false entfernt den Adapter
        // aus dem Dropdown (AdapterRegistry filtert).
        return true;
    }

    public static function capabilities(): array {
        // Nur wirklich gelieferte Flags deklarieren:
        // 'zone-write', 'record-write', 'record-manage', 'dnssec', 'api'
        return ['zone-write', 'record-write', 'record-manage'];
    }

    // Pflicht (abstract): lookupRecord(...)
    public static function lookupRecord(string $domain, string $search): ?array {
        // unified result shape: ['content' => ..., 'type' => 'A'|'CNAME']
        return null;
    }

    // Optional überlagern: zoneExists, listRecords, getSoa,
    // createZone, deleteZone, addRecord, updateRecord, deleteRecord,
    // bumpSerial, dnssecAvailable, getDnssecStatus, listKeys,
    // listMetadata, setKeyActive, deleteKey, secureZone, disableDnssec.
}
```

Konventionen & Regeln:

- **Statisch wie der Rest** (`getKey/getName/isAvailable`), keine
  SQLite-DI-Details: DB via `AbstractDnsAdapter::initDb()` (löst
  `dns.database` aus module.json über `Config::moduleDb()`; Prefix
  automatisch aus `dns.table_prefix`).
- **Capability-API vor UI:** Tab/Buttons nur render, wenn
  `DnsManager::supports(<cap>)` true gibt (z.B. DNSSEC-Tab nur mit
  'dnssec'). Never annehmen dass ein Stub silent überschrieben wird.
- **Result-Shapes:** `listRecords` → `id,name,type,content,ttl,prio`;
  `lookupRecord` → `content,type` (`data` wird an der Read-Site nur
  kompatibilitätshalber gelesen, nicht mehr erzeugt).
- **Kein `new Database`/`new PDO`** im Adapter — Framework-DB-API
  nutzen: siehe `DB_ACCESS.md`.
- Verify am Server-Teststack: Testzone Lifecycle gegen
  `pdns-test`/Ziel-DB (nur klar benannte Testzonen), siehe AGENTS.md.

---

## Funktionsumfang

### Sektion General

- **Overview** — System-Resourcen, Server-Info, Customer-Gruppen, pmwh3-Daten
- **Password** — Eigenes Passwort ändern (verifizierter Wechsel mit altem Passwort)
- **Traffic** — Traffic-Statistiken
- **Messages** — interne Nachrichten (Inbox / Sent / Compose mit Customer-Autocomplete / Reply / Delete)
- **Session list** — aktive Sessions der letzten 15 min, Admin kann Sessions kicken
- **Packages** — Hosting-Pakete CRUD
- **Groups** — Customer-Gruppen CRUD

### Sektion Customer

- Übersicht aller Customers (Hierarchie-gefiltert)
- Edit/New mit Paket-Auswahl + JS-Auto-Fill der Limit-Felder
- Passwort-Reset

### Sektion Domain

- Domain-Verwaltung mit Subdomains und Aliases
- Whois-Info, DNS-Konfiguration

### Sektion Email

- Email-Konten, Forwards, Catchalls
- **Filtering (Rspamd-Anbindung, Installationsübersicht)** — Details unten
  unter „Rspamd-Filterung installieren (Schritt für Schritt)“
  - per-Scope-Schwellen (`pmwh3_filtering`, Vererbung
    @Mailbox → @Domain; leer = rspamd-Globals). Server-Global (`@.`)
    ist bewusst NICHT pmwh3-editierbar (rspamd-globals.)
  - **Whitelist / Blacklist** (`pmwh3_wblist`, W/B pro Scope)
  - Scope-Dropdown mit existierenden Mailboxen (pmwh2-nah)

### Rspamd-Filterung installieren (Schritt für Schritt)

Nach dem pmwh3-Deploy (Migrations laufen automatisch) sind 3 Stellen
einzustellen — pmwh3 bleibt die DB-Quelle, rspamd zieht alles per HTTP:

**1. pmwh3-Options → Email (einmal speichern):**

| Setting | Wert / Aufgabe |
|---|---|
| `FILTER_POLICY_TYPE` | `rspamd` (pmwh3-DB wird Quelle; `none` = nur UI) |
| `RSPAMD_MAP_TOKEN` | Generieren z.B. `openssl rand -hex 32` — muss **identisch** am rspamd-Block und den Endpoints stehen |
| `RSPAMD_API_URL` | Owner-API für pmwh3 (Learn/Stat), default `http://rspamd:11334` |
| `RSPAMD_API_PASSWORD` | leer = keine Auth |
| `RSPAMD_WORKER_URL` | für Scan-Hilfe aus pmwh3, default `http://rspamd:11333` |

**2. rspamd `local.d` (am SELBEN Server wie pmwh3, einmalig):**

   ⚠ `<TOKEN>` = Wert aus `RSPAMD_MAP_TOKEN` (beide Server gleich).

`/srv/docker/rspamd/config/local.d/multimap.conf` (+APPEND):
```
PMWH3_WHITELIST {
    type = "from";
    prefilter = true;
    action = "accept";
    map = "https://webhost.example/cevian/pmwh3/filtering/map_wblist/W?key=<TOKEN>";
}
PMWH3_BLACKLIST {
    type = "from";
    prefilter = true;
    action = "reject";
    map = "https://webhost.example/cevian/pmwh3/filtering/map_wblist/B?key=<TOKEN>";
}
```

`/srv/docker/rspamd/config/local.d/settings.conf` (+APPEND):
```
pmwh3_thresholds {
    priority = medium;
    external_map {
        map {
            external = true;
            backend = "https://webhost.example/cevian/pmwh3/filtering/settings_query?key=<TOKEN>";
            method = "body";          # !NOT "query"; query ersetzt ?key=
            encode = "json";
            timeout = 3.0;
        }
        selector = "id('rcpt');rcpts:addr.lower";   # exakt so, 'rcpt' währe invalide
    }
}
```

Danach einmal: `docker restart rspamd` (Settings.neuedatei laden)
und Verify im Log zu einer echten eingehenden Mail an eine Domain mit
Policy:
```
docker logs rspamd | grep "apply settings from external"
```

**3. Verifikation mit der pmwh3-UI:**

- Email → Tab „Filtering"/„Whitelist/Blacklist“: Zeilen CRUD
  anlegen (Scope selektierbar aus existierenden Mailboxen)
- Andere Domains ohne Policy → rspamd-global (kein PMWH3-Einfluss) ✓
- `@.` (Server-Grausamkeit) bleibt rspamd-`actions.conf` — in pmwh3
  bewusst NICHT bearbeitbar.

**ns2 (mailbackup / zweiter Standort):** wenn es einen zweiten rspamd
gibt, in dessen local.d **denselben 2 Blöcke** einspielen; die URLs
zeigen on HTTPS auf den pmwh3-Host (ns1 rspamd-DB erreicht ns2 nur-
lesend über HTTPS) — Token, Settings — identisch ns1.

**ns2 (mailbackup / Backup-MX):** der zweite rspamd bekommt die
selben beiden Blöcke — URLs zeigen HTTPS auf den pmwh3-Host (ns1).
Wenn ns1 down ist, fällt ns2 per Timeout auf die rspamd-Defaults
zurück (tolerant, kein Mail-Verlust). Bei Backups gilt:
`/srv/docker/rspamd/config/local.d/multimap.conf + settings.conf`
am ns2 analog; Token identisch; danach `docker restart rspamd`.
**Verify am ns2** (erfolgt 2026-09-14): `PMWH3_WHITELIST` fired,
action=no action. Bereits études aux live.

### Mail-System / Sieve-Learning (postfix+Dovecot-Adapter, Live-Setup bei beiden MX)

Die mailbox-„Junk-E-Mail"-Sieve-Regeln (`report-spam.sieve`,
`report-ham.sieve`) + Wrapper `/vhome/etc/sbin/rspamc_learn.sh`
lernt wie pmwh2 gewohnt über die rspamd HTTP API
(POST `/learnspam` `/learnham`, Header `Deliver-To`). Wichtig:
der Wrapper-Log muss auf einen vmail-schreibbaren Pfad zeigen
(conf: `LOG_FILE=/vhome/vmail/sieve/rspamc_learn.log`,
`chmod 666`) — sonst schreibt er stillschweigend ins Leere und man
glaubt falsch, das Lernen würde nicht funktionieren.

### Sektion Email (weitere Details)

**PMWH2-Migration (Legacy-Instanzen am Server die noch amavis-Daten
haben):** `scripts/migrate_amavis.php` (falls vorhanden, sonst DRY
RUN-Planung) — pmwh2_amavis_policy/-users/-wblist → pmwh3_tables
einlesen (ALT-Daten bleiben unangetastet; nur-wenn Flag
`CUSTOMER_EMAIL_POLICY=Y` historische Werte migriert).
- **Rspamd-API-Client** (`utils/rspamdmanager.php`, analog Dovecot-
  rspamd-Integration): Controller-API (`/ping`, `/stat`, `/maps`,
  `/learnspam`/`/learnham`/`/learnforget`) und Worker-Normal
  (`POST /checkv2`) via curl; Settings `RSPAMD_API_URL`,
  `RSPAMD_API_PASSWORD`, `RSPAMD_WORKER_URL`. Grundlage für
  Learn-Buttons und die Verify-Checks (ohne rspamc, das nur im
  rspamd-Container lebt).
- **Whitelist / Blacklist** (`pmwh3_wblist`, W/B pro Scope):
  multimap-Text via `pmwh3/filtering/map_wblist/{W|B}`
  (multimap-Moduleintrag `prefilter = true`, `action = accept/reject`)
- Server-Glue (einmalig, in rspamd `local.d`) siehe AGENTS.md
- Global rspamd-Verhalten (greylist, subject, learning) bleibt
  unverändert in rspamd-Configs.

### Sektion Options

Alle Module-Settings via Settings-Schema (`config/settingsschema.php`). Sektionen:

- System / Web / Layout / Email / FTP / DNS
- Databases / Customers / Errorlog / Domains
- Messages / Confirmations / Sessions

Settings-Werte sind gruppiert (`GROUP_*`-Konstanten) und werden über `LazyConfig::get($key, $default)` gelesen.

### Sektion Tools

- Errorlog-Viewer
- Menu-Editor
- Backup
- News
- Applications
- Object Groups
- Modules

---

## Permissions

ACL läuft über `pmwh3_acl_objects` (Permission-Namen) und `pmwh3_acl_permissions` (Grants pro `cgrp`). Standard-Gruppen:

| gid | Name |
|-----|------|
| 1 | admin (Vollzugriff) |
| 2 | customer |
| 3 | reseller |

`CustomerUtil::hasAccess($permission)` prüft den eingeloggten User.

Wichtige Permissions:

- `view_customers`, `create_customer`, `edit_customer`, `delete_customer`
- `view_customer_limits`
- `view_domains`, `create_domain`, `edit_domain`, `delete_domain`
- `view_email`, `create_email`, `edit_email`, `delete_email`
- `view_packages`, `create_package`, `edit_package`, `delete_package`
- `view_groups`, `create_group`, `edit_group`, `delete_group`
- `send_message`, `delete_message`
- `view_menu_*` (Menü-Sichtbarkeit)

---

## Datenbank-Schema

Setup über `inc/sql/0.0.0_baseline.sql`. Wichtige Tabellen:

| Tabelle | Inhalt |
|---|---|
| `pmwh3_customers` | Customer-Stammdaten + Quota-IST-Werte |
| `pmwh3_packages` | Hosting-Pakete (Quota-Templates) |
| `pmwh3_groups` | Customer-Gruppen |
| `pmwh3_acl_objects` / `pmwh3_acl_permissions` / `pmwh3_acl_groups` | RBAC |
| `pmwh3_menu` | Sidebar-Menüstruktur |
| `pmwh3_configuration` | Settings (key/value, Schema in PHP) |
| `pmwh3_messages` | Customer-Nachrichten |
| `pmwh3_activity` | Active-Sessions-Liste (BBS-Style) |
| `pmwh3_domains` / `_subdomains` / `_subdomainaliases` | Domain-Hierarchie |
| `pmwh3_traffic` | Traffic-Aggregation |

Nicht verwaltet (gehören anderer Software):

- (`pmwh3_mail_*`, `pmwh3_web_*`, `pmwh3_ftp_*` sind ab **3.0.82**
        die pmwh3-Konsolidiertheit und im baseline GESETZ — die
        vendor-mirror-Tabellen werden nicht mehr auch angelegt;
        installationen mit `postfix_users/…` migrieren via
        `3.0.82.sql`)
- `mydns_*`, `pdns_*`, `amavis_*`

---

## MultiLogin-Integration

pmwh3 stellt einen User-Provider unter `utils/multilogin/userprovider.php` bereit. Der Framework-MultiLogin-Tool zeigt damit eine Spalte „pmwh3" und lässt Admin-User mit pmwh3-Customers verknüpfen. Provider delegiert an `CustomerManager::listAll()` etc.

---

## Migrations

Versionsnummer in `config/version.php` (`Version::VERSION`). Bei jeder neuen Migrations-Datei `inc/sql/<version>.sql` muss diese hochgezogen werden, sonst läuft die Migration nicht.

Dateien (Stand 3.0.81):

```
inc/sql/0.0.0_baseline.sql   Komplettes Schema + Permissions + Default-Menüeinträge
inc/sql/3.0.7.sql            Legacy-Menülink-Cleanup (`&view=` -> Cevian-Style)
inc/sql/3.0.8.sql            Activity-Tabelle für Session list
inc/sql/3.0.9.sql            Permissions/Menü-Einträge für Packages/Groups/Messages
inc/sql/3.0.10.sql           Mirror der baseline-Seeds für bestehende Installs
inc/sql/3.0.11.sql           Legacy-Menülinks (UPDATE auf neue CRUD-URLs, Linkstring)
inc/sql/3.0.12.sql           Bessere Match-Logik via (name, box) PK
```

Wichtig: pmwh3 hat eine eigene DB. Migrations werden seit Cevian `0.18.3` korrekt in die pmwh3-DB ausgeführt (Updater-Bugfix). Tabellennamen daher **ohne** `pmwh3.`-Prefix.

---

## Internationalisierung

UI-Strings via `__('...')`. Locales unter `i18n/locale/<lang>/LC_MESSAGES/pmwh3.po|.mo`.

Aktive Sprache aus `Pmwh3I18n::getCurrentLang()`, Default aus Settings (`DEFAULT_LANGUAGE`).

---

## Lizenz

GPL-3.0 (wie pmwh2/Vorgänger). Siehe `LICENSE` im Framework-Repo.
