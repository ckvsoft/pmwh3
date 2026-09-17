# apache — mod_perl vhost reader glue

pmwh3 renders the complete VirtualHost text for every web subdomain
into `pmwh3_web_subdomains.data` (`ApacheAdapter::buildVhostData()`:
`<VirtualHost>` skeleton with ServerName/ServerAlias and
DocumentRoot, an optional `*:443` TLS block whose certificate paths
come from the pmwh3 setting `WEB_SSL_DIR`, and the customer's
`### START/END CUSTOM ###` section). The reader here does nothing
but SELECT those blocks and hand them to Apache at startup — one
mechanism, no duplicated rendering logic.

## Files

- `vhost-reader.conf.example` — drop-in for
  `/etc/apache2/sites-enabled/vhost.conf` (variables:
  `@DB_HOST@ @DB_NAME@ @DB_USER@ @DB_PASS@ @WEBROOT@`)

## Install

1. Render or edit the file (see the variables above).
2. Copy it to your Apache `sites-enabled` directory (the live stack
   mounts `/srv/docker/apache2/sites_enabled` →
   `/etc/apache2/sites-enabled`).
3. mod_perl must be loaded — without it the `<IfModule>` guard makes
   the file a silent no-op and no pmwh3 vhost is served.
4. `apachectl configtest`, then restart. The error log shows
   `PMWH3: SQL query succeeded, N rows fetched` and `PMWH3: done`.

Rows are read **once at startup** — restart Apache after every pmwh3
vhost change (create/rename/delete, TLS rebind). Alias- and IP-mode
rows carry no `data` and are skipped by the `data <> ''` filter; the
`adapter = 'apache'` filter keeps rows of other web adapters out.

## Trust model

The `data` column is executed as Apache configuration. Only pmwh3
(and your DB admins) can write it — treat the pmwh3 database and the
credentials in this file as privileged. The reader also creates the
per-customer log dir (`@WEBROOT@/<customer>/logs`) and the docroot
on demand.

## pmwh3 side

- setting `WEB_TYPE` = `apache`
- `WEBROOT`, `WEB_VHOST_IP_PORT`, `WEB_SSL_DIR`, `APACHECONFIG`,
  `CREATE_INDEXFILE` shape the rendered blocks (Options → Web)
