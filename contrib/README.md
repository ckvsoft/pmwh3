# pmwh3 contrib/ — daemon-side glue templates

pmwh3 manages the data (customers, domains, mailboxes, forwards,
vhosts, FTP accounts) in its consolidated tables; the daemons
(postfix, dovecot, proftpd, apache+mod_perl, rspamd) read those
tables with their own native SQL/HTTP mechanisms. This directory
holds the **templates** for that daemon-side configuration —
placeholder-only, no real credentials, domains or IPs. The filled-in
versions live on the server only.

## Layout

| Directory | Contents | Consumed by |
|---|---|---|
| `apache/` | mod_perl vhost reader — applies the rendered `data` column of `pmwh3_web_subdomains` as Apache config at startup | Apache + mod_perl |
| `postfix/` | SQL map templates against `pmwh3_mail_accounts` / `pmwh3_mail_forwardings` (+ the stack-owned `postfix_transport` routing table) | Postfix (`proxy:mysql:`) |
| `dovecot/` | passdb/userdb SQL, quota (`driver = count`), doveadm HTTP API, rspamd learn wrapper | Dovecot 2.4 |
| `proftpd/` | mod_sql configuration against the `pmwh3_ftp_*` tables | ProFTPD (mod_sql) |
| `rspamd/` | W/B multimap + per-scope thresholds, pulled from pmwh3 over HTTP | Rspamd |
| `sql/` | DNS schema snapshots (PowerDNS / MyDNS) applied by the install wizard's DNS step — file names are load-bearing (`InstallBootstrap::runDnsSchema()` reads them), do not rename | pmwh3 installer |

## Filling the templates

Two ways — the full walkthrough lives in [INSTALL.md](INSTALL.md):

1. **Render script** (recommended): copy `render.vars.example` to
   `render.vars`, fill in the values, run
   `php contrib/render.php --vars render.vars --out ~/pmwh3-contrib-out`
   — you get ready-to-copy files (`.example` suffix stripped,
   `rspamc_learn.sh` kept executable). `--check` validates without
   writing anything.
2. **Manual**: replace every `@VAR@` by hand; each file's header
   comment lists the variables it uses.

`render.vars` never belongs in the repository — it is git-ignored,
and rendered output belongs outside the tree as well (the render
script enforces both).

## Variables

| Variable | Meaning | Used by |
|---|---|---|
| `@DB_HOST@` | MySQL/MariaDB host as seen from the daemons (container name, IP or `localhost`) | apache, postfix, dovecot, proftpd |
| `@DB_NAME@` | pmwh3 database name | all SQL consumers |
| `@DB_USER@` | DB user for the daemons (SELECT is enough for postfix/dovecot/apache; proftpd needs UPDATE/INSERT) | all SQL consumers |
| `@DB_PASS@` | its password | all SQL consumers |
| `@MASTER_IP@` | THIS mail server's public IP — the transport maps split on it to tell "I am the master MX for this domain" from "I am the backup" (`postfix_transport.master_destination`) | postfix transport maps |
| `@VMAIL_HOME@` | vmail base directory (`home` = `<vmail>/<domain>/<user>`) | dovecot |
| `@DOVEADM_PASSWORD@` | shared secret for the doveadm HTTP API (same value as the pmwh3 setting `DOVEADM_PASSWORD`) | dovecot |
| `@WEBROOT@` | customer webspace root (docroots, per-customer log dirs) | apache |
| `@BASE_URL@` | pmwh3 panel base URL, scheme + host + path, no trailing slash (e.g. `https://host.example/cevian`) | rspamd |
| `@RSPAMD_MAP_TOKEN@` | must equal the pmwh3 setting `RSPAMD_MAP_TOKEN` (generate e.g. `openssl rand -hex 32`) | rspamd |
| `@RSPAMD_URL@` | rspamd controller URL as seen from the dovecot/sieve side (same as the pmwh3 setting `RSPAMD_API_URL`) | rspamc_learn.sh |
| `@RSPAMD_PASSWORD@` | rspamd controller password (same as the pmwh3 setting `RSPAMD_API_PASSWORD`; empty = no auth) | rspamc_learn.sh |
