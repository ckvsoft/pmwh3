-- postfix_transport: mail-stack-owned routing table.
--
-- pmwh3 deliberately does NOT manage this table (the baseline does
-- not create it): which domain is delivered where, and which server
-- is the master MX, is a mail-stack decision, not a hosting-panel
-- one. The transport + mailbox_domains maps in this directory read
-- it; maintain the rows with your own tooling.
--
-- destination        = transport for domains THIS server delivers
--                      (e.g. "lmtp:inet:dovecot:24")
-- master_destination = where the domain's master MX lives
--                      (e.g. "smtp:[203.0.113.10]:25"); THIS
--                      server's own IP here means "I am the master" --
--                      that is what the two transport maps split on.

CREATE TABLE IF NOT EXISTS `postfix_transport` (
  `domain`             varchar(128) NOT NULL DEFAULT '',
  `destination`        varchar(128) NOT NULL DEFAULT '',
  `master_destination` varchar(128) NOT NULL DEFAULT '',
  PRIMARY KEY (`domain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
