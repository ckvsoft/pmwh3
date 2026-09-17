-- pmwh3 baseline schema
--
-- Establishes the complete pmwh3_* schema in one go for fresh
-- installations. Idempotent (CREATE TABLE IF NOT EXISTS) so existing
-- DBs are not disturbed -- they keep whatever they have, this just
-- ensures missing tables get created.
--
-- Conventions:
--   - InnoDB (transactional, FK-capable)
--   - utf8mb4 / utf8mb4_unicode_ci (full Unicode incl. emoji)
--   - DATE columns default to CURRENT_DATE; *_end columns default
--     to CURRENT_DATE + 1 month so newly registered records have a
--     sensible default lease window.
--
-- Tables NOT touched here (owned by other software):
--   mydns_* / pdns_* live in their DNS databases (dns.database
--   module.json node); legacy amavis_* stays with its stack.
--   The vendor-mirror copies (postfix_users, postfix_forwardings,
--   postfix_transport, dovecot_quota, apache_subdomains,
--   proftpd*) are NOT created by this baseline anymore -- the
--   consolidated pmwh3_mail_* / pmwh3_web_* / pmwh3_ftp_* tables
--   replace them; the daemon configs read them via contrib/.
--
-- The FTP tables ARE created here (IF NOT EXISTS) because pmwh3
-- manages them via CRUD. Existing data is preserved (the live
-- proftpd reads the pmwh3-DB already).

-- =====================================================================
-- ACL: as of Etappe 4d (see MIGRATION_PLAN_RBAC.md), pmwh3 uses the
-- framework's `permissions` + `role_perms` + `user_roles` tables.
-- The old pmwh3_acl_groups / pmwh3_acl_objects / pmwh3_acl_permissions
-- tables are dropped; fresh installs don't get them.
--
-- The customer-group concept now lives in framework `roles` with
-- module='pmwh3'. The old pmwh3_groups lookup table is gone too;
-- pmwh3_customers.role_id points at framework roles.id directly.
-- =====================================================================

-- =====================================================================
-- Configuration (key/value; schema lives in PHP SettingsSchema.php)
-- =====================================================================

CREATE TABLE IF NOT EXISTS `pmwh3_configuration` (
    `id`                  INT          NOT NULL AUTO_INCREMENT,
    `configuration_key`   VARCHAR(100) NOT NULL DEFAULT '',
    `configuration_value` TEXT         DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `configuration_key` (`configuration_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- Customers
-- =====================================================================

CREATE TABLE IF NOT EXISTS `pmwh3_customers` (
    `cid`             INT           NOT NULL AUTO_INCREMENT,
    `customer`        VARCHAR(100)  NOT NULL DEFAULT '',
    `role_id`         INT(11)       NOT NULL DEFAULT 0,
    `email`           VARCHAR(254)  NOT NULL DEFAULT '',
    `realname`        VARCHAR(100)  NOT NULL DEFAULT '',
    `customer_number` VARCHAR(30)   NOT NULL DEFAULT '0',
    `street`          VARCHAR(100)  NOT NULL DEFAULT '',
    `postcode`        VARCHAR(10)   NOT NULL DEFAULT '',
    `city`            VARCHAR(50)   NOT NULL DEFAULT '',
    `country`         VARCHAR(50)   NOT NULL DEFAULT '',
    `telephone`       VARCHAR(50)   NOT NULL DEFAULT '',
    `facsimile`       VARCHAR(50)   NOT NULL DEFAULT '',
    `password`        VARCHAR(64)   NOT NULL DEFAULT '',
    `creator`         INT           NOT NULL DEFAULT 0,
    `php`             CHAR(1)       NOT NULL DEFAULT 'N',
    `cgi`             CHAR(1)       NOT NULL DEFAULT 'N',
    `language`        VARCHAR(20)   DEFAULT NULL,
    `package`         VARCHAR(50)   DEFAULT NULL,
    `standard_subdomain` CHAR(1)    NOT NULL DEFAULT 'N',
    PRIMARY KEY (`cid`),
    UNIQUE KEY `customers_name` (`customer`),
    KEY `customers_creator` (`creator`),
    KEY `pmwh3_customers_role` (`role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- Quota counters: 'max' / 'used' / 'granted' rows per customer.
-- max = -1 means unlimited; max = 0 means service not available.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `pmwh3_countings` (
    `cid`        INT       NOT NULL DEFAULT 0,
    `type`       VARCHAR(7) NOT NULL DEFAULT 'max',
    `webspace`   INT       NOT NULL DEFAULT 0,
    `traffic`    INT       NOT NULL DEFAULT 0,
    `domains`    INT       NOT NULL DEFAULT 0,
    `subdomains` INT       NOT NULL DEFAULT 0,
    `emails`     INT       NOT NULL DEFAULT 0,
    `forwards`   INT       NOT NULL DEFAULT 0,
    `dbases`     INT       NOT NULL DEFAULT 0,
    PRIMARY KEY (`cid`, `type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- Hosting packages (quota templates pickable when creating customers)
-- =====================================================================

CREATE TABLE IF NOT EXISTS `pmwh3_packages` (
    `package_name` VARCHAR(50) NOT NULL,
    `webspace`     INT         NOT NULL DEFAULT 0,
    `traffic`      INT         NOT NULL DEFAULT 0,
    `domains`      INT         NOT NULL DEFAULT 0,
    `subdomains`   INT         NOT NULL DEFAULT 0,
    `emails`       INT         NOT NULL DEFAULT 0,
    `forwards`     INT         NOT NULL DEFAULT 0,
    `dbases`       INT         NOT NULL DEFAULT 0,
    `php`          CHAR(1)     NOT NULL DEFAULT 'N',
    `cgi`          CHAR(1)     NOT NULL DEFAULT 'N',
    `creator`      INT         NOT NULL DEFAULT 0,
    PRIMARY KEY (`package_name`, `creator`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- Domains
-- =====================================================================

CREATE TABLE IF NOT EXISTS `pmwh3_domains` (
    `domain`     VARCHAR(255)             NOT NULL,
    `customer`   VARCHAR(100)             NOT NULL DEFAULT '',
    `cid`        INT                      NOT NULL DEFAULT 0,
    `path`       VARCHAR(512)             NOT NULL DEFAULT '',
    `ip`         VARCHAR(45)              DEFAULT NULL,
    `services`   SET('web','mail','dns')  NOT NULL DEFAULT 'web,mail,dns',
    `whois`      MEDIUMTEXT               DEFAULT NULL,
    `reg_start`  DATE                     NOT NULL DEFAULT (CURRENT_DATE),
    `reg_end`    DATE                     NOT NULL DEFAULT (CURRENT_DATE + INTERVAL 1 MONTH),
    `host_start` DATE                     NOT NULL DEFAULT (CURRENT_DATE),
    `host_end`   DATE                     NOT NULL DEFAULT (CURRENT_DATE + INTERVAL 1 MONTH),
    PRIMARY KEY (`domain`),
    KEY `domains_customer` (`customer`),
    KEY `domains_cid`      (`cid`),
    KEY `domains_reg_end`  (`reg_end`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- Activity log (replaces old pmwh3_sessions). Updated on each request
-- by AuthMiddleware. Debounced: same URL within window only updates ts.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `pmwh3_activity` (
    `id`            BIGINT       NOT NULL AUTO_INCREMENT,
    `cid`           INT          NOT NULL,
    `customer`      VARCHAR(100) NOT NULL DEFAULT '',
    `session_id`    VARCHAR(64)  NOT NULL DEFAULT '',
    `ip`            VARCHAR(45)  NOT NULL DEFAULT '',
    `user_agent`    VARCHAR(255) NOT NULL DEFAULT '',
    `last_url`      VARCHAR(512) NOT NULL DEFAULT '',
    `prev_url`      VARCHAR(512) NOT NULL DEFAULT '',
    `last_module`   VARCHAR(64)  NOT NULL DEFAULT '',
    `last_action`   VARCHAR(64)  NOT NULL DEFAULT '',
    `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `session_start` TIMESTAMP    NULL     DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `activity_cid_updated` (`cid`, `updated_at`),
    KEY `activity_session`     (`session_id`),
    KEY `activity_updated`     (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- Activity change-log (audit trail of write operations). Append-only;
-- one row per successful create / update / delete. Read in the
-- domain/details "activity" tab as the "Change history" section.
-- Lazy-pruned by ActivityLog::write() (every ~100th insert, deletes
-- rows older than 90 days).
-- =====================================================================

CREATE TABLE IF NOT EXISTS `pmwh3_activity_log` (
    `id`         BIGINT       NOT NULL AUTO_INCREMENT,
    `cid`        INT          NOT NULL,
    `customer`   VARCHAR(100) DEFAULT NULL,
    `ip`         VARCHAR(45)  DEFAULT NULL,
    `module`     VARCHAR(64)  DEFAULT NULL,
    `action`     VARCHAR(64)  DEFAULT NULL,
    `target`     VARCHAR(255) DEFAULT NULL,
    `detail`     VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `actlog_target_created` (`target`, `created_at`),
    KEY `actlog_cid_created`    (`cid`, `created_at`),
    KEY `actlog_created`        (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- Internal messaging
-- =====================================================================

CREATE TABLE IF NOT EXISTS `pmwh3_messages` (
    `id`        INT          NOT NULL AUTO_INCREMENT,
    `sender`    VARCHAR(100) NOT NULL DEFAULT '',
    `recipient` VARCHAR(100) NOT NULL DEFAULT '',
    `date`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `subject`   VARCHAR(255) NOT NULL DEFAULT '',
    `text`      TEXT         DEFAULT NULL,
    `msg_read`  CHAR(1)      NOT NULL DEFAULT 'N',
    PRIMARY KEY (`id`),
    KEY `messages_recipient` (`recipient`, `msg_read`),
    KEY `messages_sender`    (`sender`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- News (login-page announcements)
-- =====================================================================

CREATE TABLE IF NOT EXISTS `pmwh3_news` (
    `id`       INT          NOT NULL AUTO_INCREMENT,
    `datetime` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `news`     TEXT         DEFAULT NULL,
    `active`   CHAR(1)      NOT NULL DEFAULT 'N',
    `author`   VARCHAR(100) NOT NULL DEFAULT '',
    `authorid` INT          NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `news_active_dt` (`active`, `datetime`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- Filtering (per-scope spam thresholds, pulled by rspamd over HTTP)
-- =====================================================================

CREATE TABLE IF NOT EXISTS `pmwh3_filtering` (
    `id`              INT          NOT NULL AUTO_INCREMENT,
    `scope_type`      ENUM('email','domain','global') NOT NULL DEFAULT 'domain',
    `scope`           VARCHAR(254) NOT NULL DEFAULT '',
    `tag_threshold`   DECIMAL(5,1) DEFAULT NULL,
    `kill_threshold`  DECIMAL(5,1) DEFAULT NULL,
    `updated_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by`      VARCHAR(100) NOT NULL DEFAULT '',
    PRIMARY KEY (`id`),
    UNIQUE KEY `filtering_scope` (`scope`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- NOTE: no seeded '@.' global row by design -- server-wide defaults
-- belong to rspamd's own actions.conf; pmwh3 only manages domain/
-- mailbox scopes (the editing domain). Pegged global rows would
-- shadow rspamd globals without adding value.

-- =====================================================================
-- WBList (recipient whitelist/blacklist, amavis W/B semantics)
-- =====================================================================

CREATE TABLE IF NOT EXISTS `pmwh3_wblist` (
    `id`         INT          NOT NULL AUTO_INCREMENT,
    `scope_type` ENUM('email','domain','global') NOT NULL DEFAULT 'domain',
    `scope`      VARCHAR(254) NOT NULL DEFAULT '',
    `list_type`  ENUM('W','B') NOT NULL DEFAULT 'W',
    `address`    VARCHAR(254) NOT NULL DEFAULT '',
    `comment`    VARCHAR(255) NOT NULL DEFAULT '',
    `created`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by` VARCHAR(100) NOT NULL DEFAULT '',
    PRIMARY KEY (`id`),
    KEY `wblist_scope` (`scope`, `list_type`),
    KEY `wblist_address` (`address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- Applications (external app shortcuts listed in the Tools tab)
-- =====================================================================

CREATE TABLE IF NOT EXISTS `pmwh3_applications` (
    `id`   INT          NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(100) NOT NULL DEFAULT '',
    `link` VARCHAR(255) NOT NULL DEFAULT '',
    `sort` INT          NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- Password reset tokens
-- =====================================================================

CREATE TABLE IF NOT EXISTS `pmwh3_password_request` (
    `token`    VARCHAR(64) NOT NULL,
    `cid`      INT         NOT NULL,
    `password` VARCHAR(64) NOT NULL,
    `expires`  TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`token`),
    KEY `pwreq_cid` (`cid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- Traffic (filled by external log parsers; pmwh3 only reads)
-- =====================================================================

CREATE TABLE IF NOT EXISTS `pmwh3_traffic` (
    `timestamp` DATE         NOT NULL DEFAULT (CURRENT_DATE),
    `domain`    VARCHAR(255) NOT NULL DEFAULT '',
    `username`  VARCHAR(255) NOT NULL DEFAULT '',
    `apache`    BIGINT       NOT NULL DEFAULT 0,
    `ftp`       BIGINT       NOT NULL DEFAULT 0,
    `mail`      BIGINT       NOT NULL DEFAULT 0,
    PRIMARY KEY (`timestamp`, `domain`, `username`),
    KEY `traffic_domain` (`domain`),
    KEY `traffic_user`   (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- Customer databases: no bookkeeping table.
--
-- pmwh2 went straight against the MariaDB server (SHOW DATABASES
-- filtered by <customer>_ prefix; mysql.db for grants). pmwh3 does
-- the same -- single source of truth, no drift when someone makes
-- a DB by hand. See pmwh3\Utils\DatabaseManager.
-- =====================================================================
--
-- Schema mirrors the historical proftpd shape (SQLUserInfo /
-- SQLGroupInfo). The historical proftpd standard schema uses MyISAM
-- but ProFTPD itself doesn't care -- InnoDB gives us proper
-- row-level locking for the high-frequency quota tally updates and
-- crash-recovery. Charset is utf8mb4 in line with the rest of the
-- schema; password column is BINARY-collated separately so
-- proftpd's byte-exact hash compare still works.
--
-- pmwh3 manages these tables but they are read by an external
-- daemon. Don't add foreign keys to pmwh3_* here; that would break
-- if proftpd is configured against a different db user/DB node.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `pmwh3_ftp_accounts` (
    `id`               INT(11)              NOT NULL AUTO_INCREMENT,
    `username`         VARCHAR(250)         NOT NULL DEFAULT '',
    `password`         VARCHAR(64)          CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
    `uid`              INT(10) UNSIGNED     NOT NULL DEFAULT 1001,
    `gid`              INT(10) UNSIGNED     NOT NULL DEFAULT 1001,
    `homedir`          VARCHAR(250)         NOT NULL DEFAULT '',
    `domain`           VARCHAR(200)         NOT NULL DEFAULT '',
    `get_ftp_password` VARCHAR(50)          NOT NULL DEFAULT '',
    `accessed`         DATETIME             NOT NULL DEFAULT '1000-01-01 00:00:00',
    `count`            INT(6)               NOT NULL DEFAULT 0,
    `bytes_in`         BIGINT               NOT NULL DEFAULT 0,
    `bytes_out`        BIGINT               NOT NULL DEFAULT 0,
    `master`           VARCHAR(250)         NOT NULL DEFAULT '',
    PRIMARY KEY (`username`),
    UNIQUE KEY `pmwh3_ftp_accounts_id` (`id`),
    KEY `pmwh3_ftp_accounts_domain` (`domain`),
    KEY `pmwh3_ftp_accounts_master` (`master`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pmwh3_ftp_groups` (
    `groupname` VARCHAR(100) NOT NULL DEFAULT '',
    `gid`       INT(6)       NOT NULL DEFAULT 5500,
    `members`   VARCHAR(255) NOT NULL DEFAULT '',
    PRIMARY KEY (`groupname`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pmwh3_ftp_quota_limits` (
    `name`             VARCHAR(30) NOT NULL DEFAULT '',
    `quota_type`       VARCHAR(5)  NOT NULL DEFAULT 'user',
    `per_session`      VARCHAR(5)  NOT NULL DEFAULT 'false',
    `limit_type`       VARCHAR(4)  NOT NULL DEFAULT 'soft',
    `bytes_in_avail`   BIGINT      NOT NULL DEFAULT 0,
    `bytes_out_avail`  BIGINT      NOT NULL DEFAULT 0,
    `bytes_xfer_avail` BIGINT      NOT NULL DEFAULT 0,
    `files_in_avail`   INT(10) UNSIGNED NOT NULL DEFAULT 0,
    `files_out_avail`  INT(10) UNSIGNED NOT NULL DEFAULT 0,
    `files_xfer_avail` INT(10) UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`name`, `quota_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pmwh3_ftp_quota_tallies` (
    `name`            VARCHAR(30) NOT NULL DEFAULT '',
    `quota_type`      VARCHAR(5)  NOT NULL DEFAULT 'user',
    `bytes_in_used`   BIGINT      NOT NULL DEFAULT 0,
    `bytes_out_used`  BIGINT      NOT NULL DEFAULT 0,
    `bytes_xfer_used` BIGINT      NOT NULL DEFAULT 0,
    `files_in_used`   INT(10) UNSIGNED NOT NULL DEFAULT 0,
    `files_out_used`  INT(10) UNSIGNED NOT NULL DEFAULT 0,
    `files_xfer_used` INT(10) UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`name`, `quota_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- Mail stack tables (consolidated, "mail == mail"): pmwh3-DB owns the
-- canonical rows that the postfix/dovecot stack config reads (see
-- contrib/). quota_usd is the ASSIGNMENT (bytes), quota usage itself
-- comes from the mail system live (doveadm HTTP API). Catchall is a
-- forward row with source '@domain'.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `pmwh3_mail_accounts` (
    `email`       VARCHAR(128) NOT NULL DEFAULT '',
    `login`       VARCHAR(128) NOT NULL DEFAULT '',
    `password`    VARCHAR(128) NOT NULL DEFAULT '',
    `name`        VARCHAR(255) NOT NULL DEFAULT '',
    `uid`         INT(11)      NOT NULL DEFAULT 99999,
    `gid`         INT(11)      NOT NULL DEFAULT 99999,
    `homedir`     VARCHAR(128) NOT NULL DEFAULT '',
    `maildir`     VARCHAR(255) NOT NULL DEFAULT '',
    `quota_bytes` BIGINT       NOT NULL DEFAULT 20000000,
    `used_bytes`     BIGINT       NOT NULL DEFAULT 0,
    `used_messages`  BIGINT       NOT NULL DEFAULT 0,
    `active`      CHAR(1)      NOT NULL DEFAULT 'Y',
    PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pmwh3_mail_forwardings` (
    `source`      VARCHAR(80)  NOT NULL,
    `destination` MEDIUMTEXT   DEFAULT NULL,
    PRIMARY KEY (`source`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- pmwh3_web_subdomains: the WEB (vhost) store -- consolidated,
-- adapter-neutral (WEB = WEB). Structural columns are owned by
-- pmwh3; each web adapter renders its own rendered text lives in `data`\n-- (owner marked by `adapter`; a future nginx adapter would render
-- its own text into `data` when active, or use its own daemon-DB). `custom` holds the
-- user-provided override block extracted from the old
-- ### START/END CUSTOM ### marker contract. Row kinds map to
-- mode: 'directory' | 'ip' | 'alias'.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `pmwh3_web_subdomains` (
    `subdomain`     VARCHAR(255) NOT NULL DEFAULT '',
    `domain`        VARCHAR(255) NOT NULL DEFAULT '',
    `customer`      VARCHAR(255) NOT NULL DEFAULT '',
    `path`          VARCHAR(255) NOT NULL DEFAULT '',
    `mode`          VARCHAR(16)  NOT NULL DEFAULT 'directory',
    `ip`            VARCHAR(45)  DEFAULT NULL,
    `alias_of`      VARCHAR(128) DEFAULT NULL,
    `ssl_cert`      VARCHAR(255) DEFAULT NULL,
    `custom`        TEXT         DEFAULT NULL,
    `adapter`       VARCHAR(32)  NOT NULL DEFAULT 'apache',
    `data`          LONGTEXT     DEFAULT NULL,
    PRIMARY KEY (`subdomain`),
    KEY `pmwh3_web_subdomains_alias_of_index` (`alias_of`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- Menu (filled by module migrations; baseline only creates the table)
-- =====================================================================

CREATE TABLE IF NOT EXISTS `pmwh3_menu` (
    `name`       VARCHAR(255) NOT NULL DEFAULT '',
    `link`       VARCHAR(255) NOT NULL DEFAULT '',
    `box`        INT          NOT NULL DEFAULT 0,
    `sort`       INT          NOT NULL DEFAULT 0,
    `hide`       CHAR(1)      NOT NULL DEFAULT 'N',
    `icon`       VARCHAR(255) NOT NULL DEFAULT '',
    `permission` VARCHAR(255) NOT NULL DEFAULT '',
    PRIMARY KEY (`box`, `sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default menu rows. PK is (box, sort) so INSERT IGNORE is safe to
-- re-run. Box layout:
--   box  0  -> General (no header row, default landing items)
--   box 10  -> Customers
--   box 20  -> Domains
--   box 30  -> Databases (with Phpmyadmin row)
--   box 40  -> Email     (with per-tab View rows)
--   box 45  -> FTP       (Overview + Accounts)
--   box 50  -> Tools     (Overview + sub-tools)
--   box 500 -> Options
--
-- Links are stored WITHOUT the leading "pmwh3/" prefix
-- (e.g. "customer/overview", not "pmwh3/customer/overview"). The
-- helper / navigation view prepends the module path.
--
-- The Phpmyadmin row uses a placeholder "[PHPMYADMIN]" which the
-- helper substitutes 1:1 with the value of the PHPMYADMIN_URL
-- setting at render time. The setting MUST include the scheme
-- (https:// or http://) -- the helper rejects anything else and
-- drops the row instead of rendering a broken link.

INSERT IGNORE INTO `pmwh3_menu` (`name`, `link`, `box`, `sort`, `hide`, `icon`, `permission`) VALUES
    -- General box (box 0) -- no header row by convention
    ('Overview',         'general/overview',          0,  10, 'N', 'menu_overview.png',     'view_menu_general_overview'),
    ('Password',         'general/password',          0,  20, 'N', 'menu_password.png',     'view_menu_general_password'),
    ('Traffic',          'general/traffic',           0,  25, 'N', 'menu_traffic.png',      'view_menu_general_traffic'),
    ('Messages',         'message/inbox',             0,  30, 'N', 'menu_messages.png',     'view_menu_general_messages'),
    ('Session list',     'general/sessions',          0,  40, 'N', 'menu_sessions.png',     'view_menu_general_sessions'),
    ('Packages',         'package/overview',          0,  50, 'N', 'menu_packages.png',     'view_packages'),
    ('Groups',           'group/overview',            0,  60, 'N', 'menu_groups.png',       'view_groups'),
    -- Customers box (box 10)
    ('Customers',        '',                         10,   0, 'N', 'menu_customers.png',    'view_menu_customers'),
    ('Overview',         'customer/overview',        10,  10, 'N', 'menu_overview.png',     'view_menu_customers_overview'),
    -- Domains box (box 20)
    ('Domains',          '',                         20,   0, 'N', 'menu_domains.png',      'view_menu_domains'),
    ('Overview',         'domain/overview',          20,  10, 'N', 'menu_overview.png',     'view_menu_domains_overview'),
    -- Databases box (box 30)
    ('Databases',        '',                         30,   0, 'N', 'menu_databases.png',    'view_menu_databases'),
    ('Overview',         'databases/overview',       30,  10, 'N', 'menu_overview.png',     'view_menu_databases_overview'),
    ('Phpmyadmin',       '[PHPMYADMIN]',             30,  30, 'N', 'menu_phpmyadmin.png',   'view_menu_databases_phpmyadmin'),
    -- Email box (box 40)
    ('Email',            '',                         40,   0, 'N', 'menu_email.png',        'view_menu_email'),
    ('Overview',         'email/overview',           40,  10, 'N', 'menu_overview.png',     'view_menu_email_overview'),
    ('View email',       'email/details/email',      40,  20, 'N', 'menu_email.png',        'view_menu_email_email'),
    ('View forward',     'email/details/forward',    40,  30, 'N', 'menu_forward.png',      'view_menu_email_forward'),
    ('View catchall',    'email/details/catchall',   40,  40, 'N', 'menu_catchall.png',     'view_menu_email_catchall'),
    ('View filtering',   'email/details/filtering',  40,  50, 'N', 'menu_filtering.png',    'view_menu_email_filtering'),
    ('View wblist',      'email/details/wblist',     40,  60, 'N', 'menu_blacklist.png',    'view_menu_email_wblist'),
    -- FTP box (box 45)
    ('Ftp',              '',                         45,   0, 'N', 'menu_ftp.png',          'view_menu_ftp'),
    ('Overview',         'ftp/overview',             45,  10, 'N', 'menu_overview.png',     'view_menu_ftp_overview'),
    ('View ftp',         'ftp/accounts',             45,  20, 'N', 'menu_ftp_accounts.png', 'view_menu_ftp_accounts'),
    -- Tools box (box 50)
    ('Tools',            '',                         50,   0, 'N', 'menu_tools.png',        'view_menu_tools'),
    ('Overview',         'tools/overview',           50,  10, 'N', 'menu_overview.png',     'view_menu_tools_overview'),
    ('Errorlog',         'tools/errorlog',           50,  20, 'N', 'menu_errorlog.png',     'view_menu_tools_errorlog'),
    ('Menu',             'tools/menu',               50,  50, 'Y', 'menu_menu.png',         'view_menu_tools_menu'),
    ('Backup',           'tools/backup',             50,  60, 'N', 'menu_backup.png',       'view_menu_tools_backup'),
    ('News',             'tools/news',               50, 100, 'N', 'menu_news.png',         'view_menu_tools_news'),
    ('Applications',     'tools/applications',       50, 110, 'N', 'menu_applications.png', 'view_menu_tools_applications'),
    -- Options box (box 500)
    ('Options',          '',                        500,   0, 'N', '',                      'view_menu_options'),
    ('Overview',         'options',                 500,  10, 'N', '',                      'view_menu_options');

-- =====================================================================
-- WHOIS server map (per TLD)
-- =====================================================================

CREATE TABLE IF NOT EXISTS `pmwh3_whois` (
    `tld`                 VARCHAR(20)  NOT NULL DEFAULT '',
    `whois_server`        VARCHAR(200) NOT NULL DEFAULT '',
    `avail_string`        VARCHAR(200) NOT NULL DEFAULT '',
    `default_info_server` VARCHAR(200) NOT NULL DEFAULT '',
    `backup_info_server`  VARCHAR(200) NOT NULL DEFAULT '',
    PRIMARY KEY (`tld`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- ACL seed: empty.
-- Permissions and roles are now seeded by framework + pmwh3 RBAC
-- bootstrap (see scripts/bootstrap_rbac.php). Fresh installs need to
-- run it once after install to populate the framework permissions
-- table with pmwh3.* entries plus the role tree under module='pmwh3'.
-- See MIGRATION_PLAN_RBAC.md.
-- =====================================================================
