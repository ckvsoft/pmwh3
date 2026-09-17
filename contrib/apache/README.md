# apache — mod_perl vhost reader glue

Das Apache-Glue (mod_perl) liest die pmwh3_web_subdomains-Rows
(column: subdomain + path + part) um die VirtualHosts per Request
zu rendern. Migration 3.0.82 benennt die pmwh3-Tabelle von
apache_subdomains auf pmwh3_web_subdomains — das mod_perl-Glue am
Server (IWO) muss im selben Fenster die Tabelle umbenennen, und
die config-DIREKT-Anpassung im Code-Tree (PHP-Part im mod_perl) Nach
dem Switch ist die pmwh3-DB das Eigentum der pmwh3, die Kompat-View
kann dann weg.
