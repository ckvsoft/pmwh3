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
| `virtual_mailbox_domains` | `mysql-virtual_mailbox_domains.cf.example` | `postfix_transport` (see below) |
| `smtpd_sender_login_maps` (1st) | `mysql-virtual_alias_maps.cf.example` | `pmwh3_mail_accounts` |
| `smtpd_sender_login_maps` (2nd) | `mysql-smtp_sender.cf.example` | `pmwh3_mail_forwardings` |
| `transport_maps` (1st) | `mysql-virtual_transport_maps.cf.example` | `postfix_transport` (domains this server is MASTER for) |
| `transport_maps` (2nd) | `mysql-backup_transport_maps.cf.example` | `postfix_transport` (domains this server is BACKUP for) |

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

## postfix_transport (stack-owned)

pmwh3 does not manage domain routing. The baseline therefore does
not create `postfix_transport`; the transport and mailbox_domains
maps read it. Fresh installs create it once with
`postfix_transport.sql` (same directory) and maintain the rows by
hand or with their own tooling:

- `destination` — transport for domains this server delivers
  (e.g. `lmtp:inet:dovecot:24`)
- `master_destination` — where the domain's master MX lives
  (e.g. `smtp:[203.0.113.10]:25`); this server's own IP here means
  "I am the master". The two transport maps split on exactly that
  (`@MASTER_IP@`).

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

The DB user needs SELECT only. Apply `postfix_transport.sql` to the
same database (`@DB_NAME@`) once, then `postmap -q` your checks and
`postfix reload`.
