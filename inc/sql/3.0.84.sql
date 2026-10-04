-- 3.0.84: per-vhost PHP selection on pmwh3_web_subdomains.
--
-- ''  = inherit the customer flag (pmwh3_customers.php)
-- 'Y' = PHP enabled for this vhost (rendered with an open_basedir
--       confinement, WEB_OPEN_BASEDIR)
-- 'N' = PHP disabled for this vhost (hard deny for *.php/phtml/phar)
--
-- The customer flag is the ceiling: when pmwh3_customers.php = 'N',
-- the effective mode is always 'N' regardless of this column
-- (enforced in SubdomainManager + WebManager::resolvePhp).
-- Runs exactly once per instance (updater stamps it).

ALTER TABLE `pmwh3_web_subdomains`
    ADD COLUMN `php` CHAR(1) NOT NULL DEFAULT '' AFTER `ssl_cert`;
