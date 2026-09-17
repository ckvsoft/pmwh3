# dovecot — pmwh3 glue

## Authentifizierung / userdb via pmwh3_mail_accounts
(dovecot.conf, sql_driver, passdb/userdb — query-templates).

## Quota
- Die Verwendungszahlen kommen LIVE von Dovecot's per-index driver:
  via doveadm HTTP API (doveadm_password am doveadm service,
  24424 internal) — pmwh3 holt sie per liveQuota().
- KEINE separate `dovecot_quota`-Tabelle im pmwh3-DB (konsolidierung
  per Welle 9): usage ist das Einzige was die pem-Configs vielleicht
  hanno — ligas pmwh3's doveadm call.
