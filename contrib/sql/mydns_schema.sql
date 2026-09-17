-- MyDNS SQL schema (snapshot 2026-09-17, MariaDB).
-- Source: ck000005_pmwh2 live legacy `mydns_soa` / `mydns_rr`
-- (SHOW CREATE TABLE), adjusted: CREATE TABLE IF NOT EXISTS +
-- MyISAM kept because MyDNS-NG expects the legacy layout; pmwh3's
-- MyDnsAdapter reads these tables read-mostly (origin/serial bump).
-- The DNS schema belongs to the SERVER installation; pmwh3 only
-- applies this file when the wizard's DNS-schema step asks for it.

CREATE TABLE IF NOT EXISTS `mydns_soa` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `origin` char(255) NOT NULL DEFAULT '',
  `ns` char(255) NOT NULL DEFAULT '',
  `mbox` char(255) NOT NULL DEFAULT '',
  `serial` int(10) unsigned NOT NULL DEFAULT 1,
  `refresh` int(10) unsigned NOT NULL DEFAULT 28800,
  `retry` int(10) unsigned NOT NULL DEFAULT 7200,
  `expire` int(10) unsigned NOT NULL DEFAULT 604800,
  `minimum` int(10) unsigned NOT NULL DEFAULT 86400,
  `ttl` int(10) unsigned NOT NULL DEFAULT 86400,
  `xfer` char(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `origin` (`origin`),
  KEY `origin_index` (`origin`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

CREATE TABLE IF NOT EXISTS `mydns_rr` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `zone` int(10) unsigned NOT NULL DEFAULT 0,
  `name` char(64) NOT NULL DEFAULT '',
  `type` enum('A','AAAA','ALIAS','CNAME','HINFO','MX','NS','PTR','RP','SRV','TXT') DEFAULT NULL,
  `data` char(128) NOT NULL DEFAULT '',
  `aux` int(10) unsigned NOT NULL DEFAULT 0,
  `ttl` int(10) unsigned NOT NULL DEFAULT 86400,
  PRIMARY KEY (`id`),
  KEY `mydns_rr_index` (`zone`,`name`,`type`,`data`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;
