# postfix — pmwh3 mail-stack glue

Postfix liest die konsolidierten pmwh3-Tabellen via jeho SQL maps
(/etc/postfix/sql/*.cf, proxy:mysql). Templates hier zeigen den
PMWH3-Charset; echte Creds am Server in den .cf-Dateien, NICHT hier.

## Tabellen
- pmwh3_mail_accounts   (= postfix_users-Ersatz: email/login/password/
   name/uid/gid/homedir/maildir/quota_bytes/active)
- pmwh3_mail_forwardings (= postfix_forwardings: source/destination,
   Catchall-Row source '@domain')

## virt_domains / virtual_alias domains
pmwh3_domains PMWH3 die Domain-Verwaltung (Zonen-Inhaber). Ein
virt. account Eintrag:
  query = SELECT DISTINCT SUBSTRING_INDEX(email,'@',-1) FROM pmwh3_mail_accounts
  WHERE '{SQL}'
