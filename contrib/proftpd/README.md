# proftpd — FTP stack glue (mod_sql)

pmwh3 manages FTP accounts, groups and quota in the consolidated
`pmwh3_ftp_*` tables; proftpd authenticates against them with
mod_sql + mod_sql_passwd. The live stack already runs this way
(reading the pmwh3 database).

## Files

- `pmwh3-sql.conf.example` — merge into your proftpd.conf (or drop
  into an included conf.d directory). Variables:
  `@DB_HOST@ @DB_NAME@ @DB_USER@ @DB_PASS@`

## Passwords

pmwh3 stores FTP passwords either plain (`ENCRYPT_FTP_PASS = N`,
the default) or as **unsalted SHA-256 hex** (`ENCRYPT_FTP_PASS = Y`,
`FtpManager::encodePassword()`). proftpd must verify the same way:

- keep the `SQLPasswordSaltFile` **empty** (or remove the line) — a
  non-empty salt breaks verification of pmwh3-written rows, because
  pmwh3 prepends no salt
- `SQLAuthTypes Plaintext SHA256 Crypt Empty` covers both settings

## What the SQLLog lines feed

- `pmwh3_traffic` — FTP bytes per domain/user (the Tools → Traffic
  statistics read that table)
- per-account counters in `pmwh3_ftp_accounts` (`count`, `accessed`,
  `bytes_in`, `bytes_out`)
- quota tallies in `pmwh3_ftp_quota_tallies` (limits come from
  `pmwh3_ftp_quota_limits`)

## Layout notes from the live stack

- The live container bakes `conf/` into the image (docker-compose
  build) — mount your conf dir instead, or rebuild after changes.
- `SQLLogFile` (enable it in your main config) is the first place to
  look when logins fail.
- `SQLDefaultUID/GID` are fallbacks for rows with NULL uid/gid —
  adjust to your conventions.
