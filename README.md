# pmwh3 — PHP myWebHosting Control Panel

A PHP hosting control panel implemented as a **Cevian framework module**.
It manages customers, domains, email accounts, databases, FTP accounts
and both reseller hierarchies and per-customer quotas/packages.

Current version: **3.0.82** · German documentation: [`README_de.md`](README_de.md)

> The old on-board setup-check documentation lives in `SYSCHECK.md`.

---

## Requirements

- **Cevian ≥ 0.18.3** (prerequisite — pmwh3 is a Cevian *module*)
  - `Config::moduleDb()` / `Config::cachedDatabase()` (module DB node API)
  - `Database::execDdl/tableExists/...` helpers
  - Updater **fresh-install path** (baseline-only) + `SORT_NATURAL`
    migration ordering (0.18.4)
- PHP ≥ 8.0 with `pdo`, `pdo_mysql`, `mbstring` (see `SYSCHECK.md`)
- MariaDB / MySQL ≥ 10.x
- One MySQL/MariaDB database for the module (e.g. `pmwh3`); optional
  separate databases per service (see *Service databases* below).

---

## Installation (fresh install: copy + open URL)

1. **Have Cevian running** (prerequisite). Copy the module into the
   Cevian tree:
   ```bash
   cp -r pmwh3 /path/to/cevian/modules/
   ```

2. **Open the site URL** — the first pmwh3 page load redirects to
   `pmwh3/install` whenever the all-in-one installer state is missing
   (placeholder credentials in `module.json` or absent tables).

3. **Fill the install wizard** (`pmwh3/install`):
   - module DB credentials (host / name / user / pass) — written to
     `modules/pmwh3/module.json`
   - DNS database: `same as module DB` checkbox, or separate
     `dns.database` values (existing pdns/MyDNS zone database)
   - admin password → creates the Ultimate-Admin customer (all
     limits `-1`)
   - POST → baseline replay (`0.0.0_baseline.sql`, **fresh installs
     replay only the baseline** — the migration chain is stamped as
     applied), RBAC roles `pmwh3 / Ultimate Admin / Reseller /
     Customer`, 95 permission keys, consolidated
     `pmwh3_mail_* / pmwh3_web_* / pmwh3_ftp_*` stores — all automatic.

4. **Log in** (`pmwh3/login`) as `admin`.

The installer is **idempotent** — re-running is safe (no duplicate
admin row, no duplicated permission grants).

---

## Service databases (mail / web / ftp / dns)

pmwh3 keeps **one consolidated table family per service**:

| Service | Tables (in the service DB) |
|---|---|
| Mail | `pmwh3_mail_accounts` (email, login, password, name, uid/gid, homedir, maildir, `quota_bytes`, `used_bytes`, `used_messages`, `active`) · `pmwh3_mail_forwardings` (source/destination; catch-all is a row with source `@domain`) |
| Web | `pmwh3_web_subdomains` (subdomain, domain, customer, path, `mode` = directory/ip/alias, ip, alias_of, ssl_cert, custom, adapter, `data`) |
| FTP | `pmwh3_ftp_accounts` · `pmwh3_ftp_groups` · `pmwh3_ftp_quota_limits` · `pmwh3_ftp_quota_tallies` |
| DNS | adapter-owned (`pdns_*` / `mydns_*`) in their **own database** via the `dns.database` node — the exception by design |

There are **no vendor-mirror tables** in the module database: the
daemons (postfix/dovecot/proftpd) read the consolidated `pmwh3_*`
tables with their regular SQL configuration. Templates live in
[`contrib/`](contrib/).

### Module configuration (`module.json`)

Standard fresh install — everything in one database:

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

Separate service databases — only if a service truly needs its own
database (e.g. a pre-existing mail stack database). A missing node
falls back to the module database; an incomplete node is a hard
error:

```json
{
    "mail": { "database": { "type": "mysql", "host": "localhost",
              "name": "mailstack", "user": "MAIL_USER", "pass": "MAIL_PASS" } },
    "web":  { "database": { "type": "mysql", "host": "localhost",
              "name": "webstack", "user": "...", "pass": "..." } },
    "ftp":  { "database": { "type": "mysql", "host": "localhost",
              "name": "ftpstack", "user": "FTP_USER", "pass": "FTP_PASS" } }
}
```

(The DNS database is the established special case; mail/web/ftp
follow the same node pattern — `dns` is the only one most
installations actually need.)

---

## Mail quota model

The assigned quota is `pmwh3_mail_accounts.quota_bytes`. **Usage**
(`used_bytes` / `used_messages`) is written by the mail system's
quota driver into the **same row** — there is no separate vendor
quota table. Where the active adapter supports a live endpoint (the
postfix adapter talks to Dovecot's doveadm HTTP API as a live
source), pmwh3 shows live values with fallback to the row columns.
The daemon-side glue (userdb/passdb queries, dict-quota mapping
against `pmwh3_mail_accounts`) lives in
[`contrib/`](contrib/).

---

## Contributing glue (`contrib/`)

`contrib/` holds the **daemon-side configuration templates**
(postfix SQL map files, dovecot auth/dict-quota snippets, rspamd
multimap/settings blocks, apache mod_perl reader note). Placeholder
names only — no credentials, no real domains. pmwh3 is the data
source; the daemon configs consume the consolidated tables.

---

## Architecture

```
controller/   HTTP routing, permission gates, view rendering
   └─ delegates business operations to utils/*Manager
model/        thin view data wrappers around managers
utils/        stateless business logic (managers, utilities, adapters)
view/         pure presentation, no DB access
config/       module version, settings schema, lazy config
helper/       view/menu helpers
i18n/         localization
contrib/      server-side daemon glue templates
```

### Adapter pattern

Each service (dns, mail, web, ftp) has an adapter directory and
interface; a new backend is a single class file:

- `utils/dns/` — `PdnsAdapter`, `MyDnsAdapter` (capability API +
  required-table guard); DNS databases live in their **own**
  databases (`dns.database` node).
- `utils/mail/` — `PostfixAdapter` (postfix + dovecot) today.
- `utils/web/` — `ApacheAdapter` today; an `NginxAdapter` renders
  its own config from the same neutral `pmwh3_web_subdomains` rows.
- `utils/` managers are the business facade (`MailManager`,
  `WebManager`, `SubdomainManager`, `DnsManager`, `FtpManager`,
  `CustomerManager`, ...); controllers stay thin.

Which adapter is active is a module setting (`Options` UI → group):
`DNS_TYPE`, `MAIL_TYPE`, `WEB_TYPE`, `FS_TYPE`, persisted in
`pmwh3_configuration`; defaults for a fresh install are
`pdns` / `postfix` / `apache`.

See [`README_de.md`](README_de.md) for the full German documentation
including the complete "new DNS adapter" walk-through, database
schema tables and the Rspamd filtering cookbook.

---

## Scope (module sections)

- **General** — overview, password change, traffic stats, internal
  messages, session list (admin kick), packages, groups,
  applications
- **Customer** — hierarchy-filtered list, package auto-fill,
  password reset, delete cascades
- **Domain(s)** — domain management, subdomains (directory/ip/alias),
  aliases, whois/DNS/DNSSEC tabs, per-domain activity + webspace
- **Email** — mailboxes, forwards, catch-all, filtering (Rspamd),
  live quota display
- **FTP** — accounts + quota through the proftpd adapter
- **Databases** — MySQL accounts per customer (manager/owner roles)
- **Options** — settings groups with per-group permission slots
- **Tools** — backup (PHP dump/restore), news, applications,
  error log viewer

---

## Migrations

Fresh installs replay **only** the baseline (`0.0.0_baseline.sql`);
from the first git release onward, changes ship as incremental
migrations (`inc/sql/<version>.sql`). Pre-release internal files do
not live in this repository.

---

## RBAC / permissions

pmwh3 uses the framework `permissions` / `role_perms` / `user_roles`
tables (module-keyed, prefix `pmwh3.`). The installer creates the
roles `Ultimate Admin` (all permissions), `Reseller` and `Customer`.
Group-permissions for customers are managed in the Customer/Group
views.

---

## Internationalization

`gettext` catalogs per language under `i18n/locale/` (de_DE fully
translated; other languages follow pmwh2 legacy state). Sources in
`i18n/locale/po/`, compiled `.mo` files per locale; regenerating:
`ctl-i18n/generate_pot.php` + `compile msgfmt` (see i18n/tools/).

---

## License

GPL-2.0-or-later (same as the Cevian framework). Copyright © 2005-2026
Christian Kvasny — see the copyright headers in the source files.
