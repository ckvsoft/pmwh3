#!/bin/sh
# Sieve-hook glue: POST the message to rspamd /learnspam|/learnham.
# Called by the imapsieve rules when a user moves mail to/from the
# Junk folder (report-spam / report-ham). Mount or copy it to where
# your sieve rules call it; the log file must exist and be writable
# by the vmail user, otherwise the learning fails silently.
#
# Variables: @VMAIL_HOME@ @RSPAMD_URL@ @RSPAMD_PASSWORD@
#   @RSPAMD_URL@ / @RSPAMD_PASSWORD@ = the rspamd controller (the
#   same values as the pmwh3 settings RSPAMD_API_URL /
#   RSPAMD_API_PASSWORD).
LOG_FILE="@VMAIL_HOME@/sieve/rspamc_learn.log"
exec 2>>"$LOG_FILE"; echo "[$(date)] $1 $2" >> "$LOG_FILE"
exec curl -s --insecure -H "Password: @RSPAMD_PASSWORD@" \
     --data-binary @- "@RSPAMD_URL@/learn$1" >/dev/null 2>&1 || true
