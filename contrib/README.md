# pmwh3 contrib/ — server-side glue (outside the module tree)

pmwh3 verwaltet die Daten, die postfix/dovecot/proftpd/rspamd NICHT
selbst kennen. Diese Verzeichnis hält die daemonseitigen
Template-Konfigurationen (placeholdiert — keine echten Creds, keine
echten Domains). Die Live-Config liegt am jeweiligen Server
(/srv/docker/...); pmwh3 ist NUR die Datenquelle.

## Migrations-Regel (wichtig)
Die Daemon-CREDS (MySQL user/pass) stehen NICHT in diesem Repo —
am Server in den Container-Configs. Die Templates hier benutzen
Platzhalter (DB_HOST, DB_USER, DB_PASS, WEBHOST, TOKEN, DOVEADM_*).
