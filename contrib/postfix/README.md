# postfix — mail stack glue (SQL maps)

Postfix reads the consolidated mail tables through mysql maps
(`proxy:mysql:/etc/postfix/sql/<name>.cf`). The templates here cover
every map the main.cf wiring needs — the live stack's map set,
translated to the pmwh3 tables:

| main.cf setting | Template | Source table |
|---|---|---|
| `virtual_mailbox_maps` | `mysql-virtual_mailbox_maps.cf.example` | `pmwh3_mail_accounts` (maildir, `active='Y'`) |
| `virtual_alias_maps` (1st) | `mysql-virtual_forwardings.cf.example` | `pmwh3_mail_forwardings` (source → destination; catch-all = source `@domain`) |
| `virtual_alias_maps` (2nd) | `mysql-virtual_email2email.cf.example` | `pmwh3_mail_accounts` (loop breaker) |
| `virtual_mailbox_domains` | `mysql-virtual_mailbox_domains.cf.example` | `pmwh3_mail_transport` (see below) |
| `smtpd_sender_login_maps` (1st) | `mysql-virtual_alias_maps.cf.example` | `pmwh3_mail_accounts` |
| `smtpd_sender_login_maps` (2nd) | `mysql-smtp_sender.cf.example` | `pmwh3_mail_forwardings` |
| `transport_maps` (1st) | `mysql-virtual_transport_maps.cf.example` | `pmwh3_mail_transport` (domains this server is MASTER for) |
| `transport_maps` (2nd) | `mysql-backup_transport_maps.cf.example` | `pmwh3_mail_transport` (domains this server is BACKUP for) |

## main.cf wiring

```
virtual_alias_domains =
virtual_alias_maps =
    proxy:mysql:/etc/postfix/sql/mysql-virtual_forwardings.cf
    proxy:mysql:/etc/postfix/sql/mysql-virtual_email2email.cf
virtual_mailbox_domains =
    proxy:mysql:/etc/postfix/sql/mysql-virtual_mailbox_domains.cf
virtual_mailbox_maps = proxy:mysql:/etc/postfix/sql/mysql-virtual_mailbox_maps.cf
smtpd_sender_login_maps =
    proxy:mysql:/etc/postfix/sql/mysql-virtual_alias_maps.cf
    proxy:mysql:/etc/postfix/sql/mysql-smtp_sender.cf
transport_maps =
    proxy:mysql:/etc/postfix/sql/mysql-virtual_transport_maps.cf
    proxy:mysql:/etc/postfix/sql/mysql-backup_transport_maps.cf
virtual_transport = lmtp:inet:dovecot:24
virtual_mailbox_base = /vhome/vmail/
virtual_uid_maps = static:5000
virtual_gid_maps = static:5000
```

(`virtual_transport`, `virtual_mailbox_base` and the static uid/gid
describe your vmail setup, not pmwh3 — adjust to your stack. Add the
maps to `proxy_read_maps` when you use the proxymap service.)

## pmwh3_mail_transport (pmwh3-owned, created by the baseline)

pmwh3 manages domain routing itself: the fresh-install baseline
creates `pmwh3_mail_transport` in the module DB and
`PostfixAdapter::syncDomainTransport()` provisions rows on domain
create / update / delete out of two pmwh3 settings (Options → Email):

- `MAIL_TRANSPORT` — the `destination` value (default
  `lmtp:inet:dovecot:24` = the Dovecot LMTP transport; keep it in
  sync with `virtual_transport` in main.cf)
- `MAIL_MASTER_IP` — public IP of the domain's **master MX**. Row
  shape: `destination` + `master_destination = smtp:[<IP>]:25`.

The two transport maps split exactly on that IP (`@MASTER_IP@` =
this server's own IP): master MX delivers via `destination`, backup
MX relays to `master_destination`. `virtual_mailbox_domains` returns
non-empty only on the master (directly from `destination`).

Behaviour per domain, mirrored by every stack server from the same
shared DB:

- mail service AND `MAIL_MASTER_IP` set → row created (master/backup
  routing works)
- mail service AND `MAIL_MASTER_IP` empty → **no row** (single-server
  setup; postfix falls back to `virtual_transport`)
- mail service removed or domain deleted → row deleted

Never add or edit rows by hand (the maps are read-only over the
proxymap service). During a live migration from a legacy
`postfix_transport` table, copy the rows once before switching the
maps to `pmwh3_mail_transport`.

## Not ported (legacy live files without main.cf wiring)

The live stack carries three more map files that have no pmwh3
equivalent and no wiring:

- `mysql-virtual_domains.cf` — superseded by
  `mysql-virtual_mailbox_domains.cf`
- `mysql-virtual_mailbox_limit_maps.cf` — unused; the mailbox limit
  is the static `virtual_mailbox_limit` in main.cf, and pmwh3's
  mailbox quota is enforced by dovecot (see `../dovecot/`)
- `mysql-smtpd_restriction_class.cf` — read
  `pmwh2_domains.smtpd_restriction_class`, a column that does not
  exist in `pmwh3_domains`

## Variables

`@DB_HOST@ @DB_NAME@ @DB_USER@ @DB_PASS@ @MASTER_IP@`

The DB user needs SELECT only. `@MASTER_IP@` must be **this** mail
server's public IP (it is the split key, not the domain's master MX).
After wiring, `postmap -q` your checks and `postfix reload`.
