#!/bin/sh
# Sieve-hook glue: POST the message to rspamd /learnspam|/learnham
# (mounted read-only into dovecot container). Paths as pmwh3 decided
# on 2026-09-15; the LOG_FILE path must be writable by the vmail user.
LOG_FILE="@VMAIL_HOME@/sieve/rspamc_learn.log"
exec 2>>"$LOG_FILE"; echo "[$(date)] $1 $2" >> "$LOG_FILE"
exec curl -s --insecure -H "Password: @RSPAMD_PASSWORD@" \
     --data-binary @- "@RSPAMD_URL@/learn$1" >/dev/null 2>&1 || true
