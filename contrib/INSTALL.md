# pmwh3 contrib — installing the daemon-side glue

> Kurzfassung (DE): contrib/ enthält die Vorlagen für die Daemon-
> Seite (postfix, dovecot, proftpd, apache+mod_perl, rspamd). Werte
> in `render.vars` eintragen, `php contrib/render.php --vars
> render.vars --out ~/pmwh3-contrib-out` ausführen, die fertigen
> Dateien laut Tabelle unten an die Daemon-Orte kopieren, Dienste
> neu starten, Health-Checks fahren. Alles geht auch manuell — jede
> Datei nennt ihre `@VAR@` im Kopfkommentar.

## 0. Prerequisites

- pmwh3 is installed (module wizard done: `module.json` written,
  baseline played, DNS schema applied)
- the daemons run where they can reach the pmwh3 database (same
  container network, or a reachable MySQL host)
- a DB user for the daemons: SELECT is enough for postfix, dovecot
  and the apache vhost reader; proftpd needs UPDATE/INSERT as well
  (it maintains counters, traffic rows and quota tallies)
- Dovecot ≥ 2.4 (the snippets use the 2.4 inline configuration
  style; the old `dovecot-sql.conf.ext` forms do not work there)
- Apache with mod_perl for the web stack

## 1. Fill the variables

```bash
cd modules/pmwh3/contrib
cp render.vars.example render.vars
$EDITOR render.vars
```

| Variable | Meaning |
|---|---|
| `DB_HOST` | MySQL host as seen from the daemons (container name, IP or `localhost`) |
| `DB_NAME` | pmwh3 database name |
| `DB_USER` / `DB_PASS` | the daemons' DB user (see privileges above) |
| `MASTER_IP` | this mail server's public IP — the postfix transport maps split master/backup duty on it |
| `VMAIL_HOME` | vmail base dir (`home` = `<vmail>/<domain>/<user>`) |
| `DOVEADM_PASSWORD` | shared secret for the doveadm HTTP API (same as the pmwh3 setting `DOVEADM_PASSWORD`) |
| `WEBROOT` | customer webspace root (docroots, per-customer log dirs) |
| `BASE_URL` | pmwh3 panel base URL rspamd fetches maps from (scheme+host+path, no trailing slash) |
| `RSPAMD_MAP_TOKEN` | must equal the pmwh3 setting `RSPAMD_MAP_TOKEN` |
| `RSPAMD_URL` / `RSPAMD_PASSWORD` | rspamd controller as seen from dovecot/sieve (= pmwh3 `RSPAMD_API_URL` / `RSPAMD_API_PASSWORD`) |

`render.vars` is git-ignored — keep it that way, it contains
credentials.

## 2. Render

```bash
php contrib/render.php --check --vars render.vars     # dry run
php contrib/render.php --vars render.vars --out ~/pmwh3-contrib-out
```

The output directory must live outside the cevian tree (the script
enforces it). You get the ready-to-copy files, `.example` suffixes
stripped, `rspamc_learn.sh` kept executable, plus a per-file hint
where it belongs.

## 3. Postfix

1. Copy the eight `*.cf` files to `/etc/postfix/sql/` (live stack:
   host dir `/srv/docker/postfix/config/sql/`).
2. Wire them in `main.cf` — the exact block is in
   [`postfix/README.md`](postfix/README.md) (virtual_alias_maps,
   virtual_mailbox_domains, virtual_mailbox_maps,
   smtpd_sender_login_maps, transport_maps). Adjust
   `virtual_transport` / `virtual_mailbox_base` / static uid-gid to
   your vmail setup.
3. Create the routing table once:
   `mysql ... < postfix/postfix_transport.sql` and insert a row per
   hosted domain (`destination` = your delivery transport,
   `master_destination` = `smtp:[<this server's IP>]:25`).
4. Check and reload:

```bash
postmap -q user@domain proxy:mysql:/etc/postfix/sql/mysql-virtual_mailbox_maps.cf
postmap -q domain proxy:mysql:/etc/postfix/sql/mysql-virtual_mailbox_domains.cf
postfix reload
```

## 4. Dovecot

1. Merge `pmwh3-auth.conf.example`, `pmwh3-quota.conf.example` and
   `pmwh3-doveadm.conf.example` into ONE `dovecot.conf` (they share
   the `mysql pmwh3 { }` connection block — keep it once).
2. Check the password scheme: `default_password_scheme` must match
   the pmwh3 setting `ENCRYPT_EMAIL_PASS` (`PLAIN` for N, `CRYPT`
   for Y) — see [`dovecot/README.md`](dovecot/README.md).
3. Restart, then verify:

```bash
doveadm auth test user@domain          # passdb
doveadm user user@domain               # userdb fields
curl -u doveadm:<DOVEADM_PASSWORD> http://dovecot:24424/doveadm/v1  # HTTP API
```

4. pmwh3 side (Options → Email): `DOVEADM_URL` = the listener
   (e.g. `http://dovecot:24424`), `DOVEADM_PASSWORD` = the same
   secret. The email details view then shows live quota (`X of Y`,
   percent, message count).

## 5. ProFTPD

1. Merge `proftpd/pmwh3-sql.conf.example` into your proftpd.conf
   (or drop it into an included conf.d dir).
2. Salt: keep the `SQLPasswordSaltFile` **empty** — pmwh3 writes
   unsalted SHA-256 hex (`ENCRYPT_FTP_PASS = Y`) or plain text (N);
   a non-empty salt breaks verification of pmwh3-written rows.
3. Restart and test an FTP login; on failure check `SQLLogFile`
   (enable it in your main config first).

## 6. Apache (mod_perl vhost reader)

1. Copy `apache/vhost-reader.conf` to your `sites-enabled`
   directory (e.g. `vhost.conf`).
2. mod_perl must be loaded — without it the file is a silent no-op
   and no pmwh3 vhost is served.
3. `apachectl configtest && apachectl restart`. The error log shows
   `PMWH3: SQL query succeeded, N rows fetched` and `PMWH3: done`.
4. Rows are read once at startup — **restart Apache after every
   pmwh3 vhost change** (create/rename/delete, TLS rebind).

pmwh3 side (Options → Web): `WEBROOT`, `WEB_VHOST_IP_PORT`,
`WEB_SSL_DIR` (pem/key pairs `<name>.pem` + `<name>.key`, wildcard
`_.<domain>`), `APACHECONFIG`, `CREATE_INDEXFILE` shape the rendered
`data` blocks.

## 7. Rspamd

1. **Append** the two blocks from `rspamd/multimap.conf` to your
   `/etc/rspamd/local.d/multimap.conf` and the block from
   `rspamd/settings.conf` to `/etc/rspamd/local.d/settings.conf`
   (append — do not replace existing content).
2. Set the pmwh3 setting `RSPAMD_MAP_TOKEN` (Options → Email) to
   the **same** token you rendered into the URLs.
3. Restart rspamd (`docker restart rspamd` on the live stack).
4. Verify — the map fetch and, with a real incoming mail to a
   domain that has a policy, the threshold lookup:

```bash
curl "https://<panel>/pmwh3/filtering/map_wblist/W?key=<token>"
grep "apply settings from external" <rspamd log>
```

A second rspamd host (backup MX) gets the **same two blocks with
the same token**; it then falls back to rspamd defaults by timeout
while this host is unreachable.

## 8. Health-check summary

| Daemon | Check | Expected |
|---|---|---|
| postfix | `postmap -q <mailbox> .../mysql-virtual_mailbox_maps.cf` | the maildir path |
| postfix | `postmap -q <domain> .../mysql-virtual_mailbox_domains.cf` | non-empty |
| dovecot | `doveadm auth test <mailbox>` | `passdb: user@domain authenticated` |
| dovecot | doveadm HTTP API curl | JSON response, no 401 |
| proftpd | FTP login of a pmwh3-managed account | 230 + quota info line |
| apache | error log after restart | `PMWH3: done`, vhost answers |
| rspamd | map curl + log grep | list contents / `apply settings from external` |

## Troubleshooting

- **renderer fails with "missing/empty"** — the listed variables
  are blank in `render.vars`; fill them and re-run `--check`.
- **dovecot auth fails** — scheme mismatch: `ENCRYPT_EMAIL_PASS`
  vs `default_password_scheme` (see the table in
  `dovecot/README.md`).
- **postfix maps return nothing** — `DB_HOST` must be reachable
  from the postfix container; test with `postmap -q`.
- **no vhosts served** — mod_perl not loaded, or apache not
  restarted after the last pmwh3 change; check the error log for
  the `PMWH3:` lines.
- **rspamd map fetch fails** — `BASE_URL` must be resolvable and
  reachable from the rspamd container, and the token must match the
  pmwh3 setting exactly.
- **proftpd logins fail** — salt file non-empty? `SQLLogFile` tells
  what was compared; also check the account's `active`-like flags
  in the pmwh3 UI.
