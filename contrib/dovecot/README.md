# dovecot — auth, quota, doveadm glue

All snippets are written for the Dovecot **2.4** inline
configuration style and are meant to be merged into ONE dovecot.conf
(the live stack keeps a single file). The `mysql pmwh3 { }`
connection block lives in the auth snippet and is shared by
everything SQL — do not duplicate it.

## Files

- `pmwh3-auth.conf.example` — passdb/userdb against
  `pmwh3_mail_accounts` / `pmwh3_mail_forwardings`; carries the
  shared `mysql pmwh3 { }` connection block. Variables:
  `@DB_HOST@ @DB_NAME@ @DB_USER@ @DB_PASS@ @VMAIL_HOME@`
- `pmwh3-quota.conf.example` — quota setup: `driver = count`
  (authoritative, live-proven) + an optional commented quota-clone
  block that mirrors usage into `pmwh3_mail_accounts.used_bytes` /
  `used_messages`.
- `pmwh3-doveadm.conf.example` — doveadm HTTP API listener; pmwh3's
  live quota display (`liveQuota()`) reads current usage through it.
  Variables: `@DOVEADM_PASSWORD@`
- `rspamc_learn.sh` — sieve hook that POSTs moved messages to
  rspamd `/learnspam` / `/learnham`. Variables:
  `@VMAIL_HOME@ @RSPAMD_URL@ @RSPAMD_PASSWORD@`

## Password scheme

`default_password_scheme` must match how pmwh3 stores mailbox
passwords (pmwh3 setting `ENCRYPT_EMAIL_PASS`, Options → Email):

| ENCRYPT_EMAIL_PASS | stored as | default_password_scheme |
|---|---|---|
| `N` (default) | plain text | `PLAIN` |
| `Y` | PHP `crypt()` hashes | `CRYPT` |

## Quota display

pmwh3 shows live usage (`X of Y`, percent, message count) fetched
via the doveadm HTTP API; the row columns `used_bytes` /
`used_messages` are the fallback when the API is unreachable. Match
the pmwh3 settings (Options → Email): `DOVEADM_URL` (e.g.
`http://dovecot:24424`) and `DOVEADM_PASSWORD` — the same secret as
in `pmwh3-doveadm.conf.example`.

About the driver: Dovecot 2.x removed the old `quota=dict:` backend —
usage is computed by the `count` driver from the filesystem (index
files) and read live via IMAP QUOTA (e.g. Roundcube) or the doveadm
HTTP API (pmwh3). There is **no SQL write path** in the default
setup. If you need the numbers inside SQL anyway (external consumers
that only see the database), the optional `quota_clone` block in
`pmwh3-quota.conf.example` is the only 2.4-conform way to mirror the
current usage into `used_bytes` / `used_messages` — it does not
change how usage is computed and never touches `quota_bytes` (the
limit stays pmwh3's job via passdb).

## rspamc_learn.sh

- Mount/copy it to where your sieve rules call it (live:
  `/vhome/etc/sbin/rspamc_learn.sh`, invoked by the imapsieve
  report-spam / report-ham rules).
- `@VMAIL_HOME@/sieve/rspamc_learn.log` must be writable by the
  vmail user (`touch` + `chmod 666`) — otherwise the wrapper fails
  silently and the learning looks broken while it is not even
  running.
- `@RSPAMD_URL@` / `@RSPAMD_PASSWORD@` = the rspamd controller, the
  same values as the pmwh3 settings `RSPAMD_API_URL` /
  `RSPAMD_API_PASSWORD`.
