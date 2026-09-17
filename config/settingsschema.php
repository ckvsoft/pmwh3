<?php

namespace pmwh3\Config;

/**
 * Source of truth for all configuration keys: their group (= options
 * section), type, label, defaults, and (for select-style settings)
 * where the option list comes from.
 *
 * Group names match the pmwh3_menu link suffixes for the Options
 * sub-controller (system, web, email, ftp, dns, ...). Keep them in
 * sync.
 *
 * Field semantics:
 *   group       -> which Options section the setting appears in
 *   label       -> human-readable label, run through __()
 *   help        -> optional description / hint
 *   type        -> text | textarea | select | multi_select | checkbox
 *                  | password | int
 *   default     -> initial value (also used as fallback)
 *   options     -> static option list for select types (key => label)
 *   options_fn  -> alternative: callable string in OptionProviders
 *                  that returns the option list dynamically
 *   level       -> '0' = always shown, '1' = only when CONFIG_LEVEL >= 1,
 *                  '9' = hidden / debug-only
 *   on_save     -> optional callable in OnSaveHooks::xxx that runs
 *                  after the value has been persisted
 */
class SettingsSchema
{

    // Section identifiers -- match pmwh3_menu links: options/<section>
    public const GROUP_SYSTEM       = 'system';
    public const GROUP_WEB          = 'web';
    public const GROUP_EMAIL        = 'email';
    public const GROUP_FTP          = 'ftp';
    public const GROUP_DNS          = 'dns';
    public const GROUP_DATABASES    = 'databases';
    public const GROUP_CUSTOMER     = 'customer';
    public const GROUP_DOMAINS      = 'domains';
    public const GROUP_MESSAGES     = 'messages';
    public const GROUP_CONFIRMATION = 'confirmation';
    public const GROUP_ERRORLOG     = 'errorlog';
    public const GROUP_LAYOUT       = 'layout';

    /** Returns localized labels for the option sections. */
    public static function getGroupLabels(): array
    {
        return [
            self::GROUP_SYSTEM       => __('System'),
            self::GROUP_WEB          => __('Web'),
            self::GROUP_EMAIL        => __('Email'),
            self::GROUP_FTP          => __('FTP'),
            self::GROUP_DNS          => __('DNS'),
            self::GROUP_DATABASES    => __('Databases'),
            self::GROUP_CUSTOMER     => __('Customer'),
            self::GROUP_DOMAINS      => __('Domains'),
            self::GROUP_MESSAGES     => __('Messages'),
            self::GROUP_CONFIRMATION => __('Confirmations'),
            self::GROUP_LAYOUT       => __('Layout'),
            self::GROUP_ERRORLOG     => __('Errorlog'),
        ];
    }

    /** Schema for one key, or null if undefined. */
    public static function get(string $key): ?array
    {
        $all = self::all();
        return $all[$key] ?? null;
    }

    /** All keys belonging to a group, in stable order. */
    public static function getByGroup(string $group): array
    {
        $out = [];
        foreach (self::all() as $key => $def) {
            if (($def['group'] ?? '') === $group) {
                $out[$key] = $def;
            }
        }
        return $out;
    }

    /**
     * The full schema. Settings are placed in the section that matches
     * the pmwh3_menu link (options/system, options/web, ...).
     */
    public static function all(): array
    {
        return [
            // ============================================================
            // SYSTEM
            // ============================================================
            'CONFIG_LEVEL' => [
                'group'   => self::GROUP_SYSTEM,
                'label'   => 'Configuration Level',
                'help'    => 'Easy hides advanced settings.',
                'type'    => 'select',
                'default' => '1',
                'options' => ['0' => 'Easy', '1' => 'Advanced'],
                'level'   => '0',
            ],
            'IP_ADDRESS' => [
                'group'   => self::GROUP_SYSTEM,
                'label'   => 'Server IP Addresses',
                'help'    => 'One IP per line. These are treated as "own" -- any other IP is considered a foreign host.',
                'type'    => 'textarea',
                'default' => '',
                'level'   => '0',
            ],
            'PASSWORD_LENGTH' => [
                'group'   => self::GROUP_SYSTEM,
                'label'   => 'Generated Password Length',
                'help'    => 'Length for generated passwords and minimum length for passwords chosen in forms.',
                'type'    => 'int',
                'default' => '8',
                'level'   => '1',
            ],
            'RESERVED_NAMES' => [
                'group'   => self::GROUP_SYSTEM,
                'label'   => 'Reserved Names',
                'help'    => 'Comma-separated names that may not be chosen as customer, mailbox or subdomain names.',
                'type'    => 'text',
                'default' => 'root,postfix,pmwh,vmail',
                'level'   => '1',
            ],
            'CAPTCHA_TYPE' => [
                'group'   => self::GROUP_SYSTEM,
                'label'   => 'Captcha Type',
                'help'    => 'none disables captcha. recaptcha_v2 renders the checkbox widget; recaptcha_v3 is invisible and uses a score. Login and password reset are protected once the site/secret keys are set.',
                'type'    => 'select',
                'default' => 'none',
                'options' => ['none' => 'none', 'recaptcha_v2' => 'reCAPTCHA v2', 'recaptcha_v3' => 'reCAPTCHA v3'],
                'level'   => '1',
            ],
            'RECAPTCHA_SITE_KEY' => [
                'group'   => self::GROUP_SYSTEM,
                'label'   => 'reCAPTCHA Site Key',
                'type'    => 'text',
                'default' => '',
                'level'   => '1',
            ],
            'RECAPTCHA_SECRET_KEY' => [
                'group'   => self::GROUP_SYSTEM,
                'label'   => 'reCAPTCHA Secret Key',
                'type'    => 'password',
                'default' => '',
                'level'   => '1',
            ],
            'RECAPTCHA_SCORE' => [
                'group'   => self::GROUP_SYSTEM,
                'label'   => 'reCAPTCHA v3 Score Threshold',
                'help'    => 'Minimum v3 score (0.0 - 1.0) for humans. Higher = stricter; 0.5 is Google\'s suggested default.',
                'type'    => 'text',
                'default' => '0.5',
                'level'   => '1',
            ],
            'SI_STANDARDIZATION' => [
                'group'   => self::GROUP_SYSTEM,
                'label'   => 'SI Units (1000 vs 1024)',
                'help'    => 'Y uses base-1000 (KB/MB/GB); N uses base-1024 (KiB/MiB/GiB).',
                'type'    => 'checkbox',
                'default' => 'Y',
                'level'   => '1',
            ],
            'ADMIN_LEVEL' => [
                'group'   => self::GROUP_SYSTEM,
                'label'   => 'Admin Level',
                'type'    => 'select',
                'default' => 'ALL',
                'options' => ['0' => '0', '1' => '1', 'ALL' => 'ALL'],
                'level'   => '1',
            ],
            'DEFAULT_LANGUAGE' => [
                'group'      => self::GROUP_SYSTEM,
                'label'      => 'Default Language',
                'type'       => 'select',
                'default'    => 'en_GB',
                'options_fn' => 'languages',
                'level'      => '0',
            ],
            'DEFAULT_ENCODING' => [
                'group'      => self::GROUP_SYSTEM,
                'label'      => 'Default Encoding',
                'type'       => 'select',
                'default'    => 'utf-8',
                'options_fn' => 'encodings',
                'level'      => '1',
            ],
            // ============================================================
            // WEB (was pmwh2's "web" -- vhost / Apache config / webroot)
            // ============================================================
            'WEB_TYPE' => [
                'group'      => self::GROUP_WEB,
                'label'      => 'Web Server Adapter',
                'help'       => 'Adapters detected automatically from utils/web/. Adding a new backend (nginx, ...) only requires dropping the file in.',
                'type'       => 'select',
                'default'    => 'apache',
                'options_fn' => 'webAdapters',
                'level'      => '0',
            ],
            'WEB_VHOST_IP_PORT' => [
                'group'   => self::GROUP_WEB,
                'label'   => 'Generated VHost Address (http)',
                'help'    => "Value of the <VirtualHost ...> address the web adapter renders for the plain-http block (e.g. '*:80'). The https block uses '*:443' when a certificate is set.",
                'type'    => 'text',
                'default' => '*:80',
                'level'   => '1',
            ],
            'WEB_SSL_DIR' => [
                'group'   => self::GROUP_WEB,
                'label'   => 'Certificate directory',
                'help'    => 'Directory containing <name>.pem + <name>.key certificate pairs (wildcard = "_.domain.tld"). Must be readable by pmwh3 for the certificate selection and by the web server, since the paths are rendered into the vhost as SSLCertificateFile/KeyFile.',
                'type'    => 'text',
                'default' => '/vhome/ssl',
                'level'   => '1',
            ],
            'WEBROOT' => [
                'group'   => self::GROUP_WEB,
                'label'   => 'Web Root',
                'type'    => 'text',
                'default' => '/vhome',
                'level'   => '0',
            ],
            'STRUCTURE_WEBROOT' => [
                'group'   => self::GROUP_WEB,
                'label'   => 'Web Root Structure',
                'help'    => 'Relative path under WEBROOT. Placeholders: [CUSTOMER], [DOMAIN]',
                'type'    => 'text',
                'default' => '[CUSTOMER]/[DOMAIN]/',
                'level'   => '1',
            ],
            'FS_TYPE' => [
                'group'      => self::GROUP_WEB,
                'label'      => 'Filesystem Adapter',
                'help'       => 'Adapters detected automatically from utils/fs/.',
                'type'       => 'select',
                'default'    => 'local',
                'options_fn' => 'fsAdapters',
                'level'      => '1',
            ],
            'CREATE_INDEXFILE' => [
                'group'   => self::GROUP_WEB,
                'label'   => 'Index Filename',
                'help'    => 'Name of the placeholder file dropped into new web roots. Empty = none.',
                'type'    => 'text',
                'default' => 'index.html',
                'level'   => '1',
            ],
            // CONTENT_INDEXFILE: Inhalt der Index-Datei (wenn leer,
            // wird ein Built-in "under construction" Fallback verwendet).
            'CONTENT_INDEXFILE' => [
                'group'   => self::GROUP_WEB,
                'label'   => 'Index File Content',
                'help'    => 'Content written into the index file created in new web roots. Empty = default "under construction" page.',
                'type'    => 'textarea',
                'default' => '',
                'level'   => '1',
            ],
            'APACHECONFIG' => [
                'group'   => self::GROUP_WEB,
                'label'   => 'Apache VHost Snippet',
                'help'    => 'Inserted into every generated vhost. Placeholders: [DOMAIN], [SUBDOMAIN], [DOCROOT], [CUSTOMER]',
                'type'    => 'textarea',
                'default' => '',
                'level'   => '1',
                'on_save' => 'updateApacheconfig',
            ],

            // ============================================================
            // EMAIL
            // ============================================================
            'MAIL_TYPE' => [
                'group'      => self::GROUP_EMAIL,
                'label'      => 'Mail Backend',
                'help'       => 'Adapters detected automatically from utils/mail/.',
                'type'       => 'select',
                'default'    => 'postfix',
                'options_fn' => 'mailAdapters',
                'level'      => '0',
            ],
            'HOMEDIR' => [
                'group'   => self::GROUP_EMAIL,
                'label'   => 'Mail Home Directory',
                'type'    => 'text',
                'default' => '/vhome/vmail',
                'level'   => '0',
                'on_save' => 'updateHomedir',
            ],
            'MAIL_USER_UID' => [
                'group'   => self::GROUP_EMAIL,
                'label'   => 'Mail User UID',
                'type'    => 'int',
                'default' => '5000',
                'level'   => '0',
                'on_save' => 'updateMailUid',
            ],
            'MAIL_USER_GID' => [
                'group'   => self::GROUP_EMAIL,
                'label'   => 'Mail User GID',
                'type'    => 'int',
                'default' => '5000',
                'level'   => '0',
                'on_save' => 'updateMailGid',
            ],
            'MAILDIR' => [
                'group'   => self::GROUP_EMAIL,
                'label'   => 'Maildir Pattern',
                'help'    => 'Placeholders: [DOMAIN], [USER]',
                'type'    => 'text',
                'default' => '[DOMAIN]/[USER]',
                'level'   => '1',
            ],
            'MAIL_TRANSPORT' => [
                'group'   => self::GROUP_EMAIL,
                'label'   => 'Mail transport for new domains',
                'help'    => 'Postfix transport used as destination when provisioning pmwh3_mail_transport rows for new mail domains (postfix adapter). Default lmtp:inet:dovecot:24 = Dovecot LMTP, matching virtual_transport in your postfix main.cf.',
                'type'    => 'text',
                'default' => 'lmtp:inet:dovecot:24',
                'level'   => '1',
            ],
            'MAIL_MASTER_IP' => [
                'group'   => self::GROUP_EMAIL,
                'label'   => 'Master MX IP address',
                'help'    => 'Public IP of the domain master MX. New mail domains get a pmwh3_mail_transport row with master_destination smtp:[<IP>]:25 so the postfix transport maps route master vs backup (single value on every server; the maps split by the local server own IP). Leave empty for single-server: no transport rows are created and postfix uses its default transport.',
                'type'    => 'text',
                'default' => '',
                'level'   => '1',
            ],
            // ROADMAP (G7 Auto-Provision): AUTO_EMAIL wird bei Domain-Add nicht angelegt.
            'AUTO_EMAIL' => [
                'group'   => self::GROUP_EMAIL,
                'label'   => 'Auto-create email accounts',
                'help'    => 'Role forwards created on the FIRST mailbox of a domain, pointing to that mailbox. postmaster@ is mandatory (RFC 2142) and is always created; every other role comes from this comma list (default webmaster, abuse, hostmaster).',
                'type'    => 'text',
                'default' => 'webmaster, abuse, hostmaster',
                'level'   => '1',
            ],
            'WELCOME_MAIL' => [
                'group'   => self::GROUP_EMAIL,
                'label'   => 'Welcome Mail Template',
                'help'    => 'Sent to a freshly created mailbox (initialises the maildir via first delivery). Placeholders: [EMAILUSER], [EMAIL], [DOMAIN], [CREATOR], [CREATOR_REALNAME]. Empty = no welcome mail.',
                'type'    => 'textarea',
                'default' => '',
                'level'   => '1',
            ],
            'ENCRYPT_EMAIL_PASS' => [
                'group'   => self::GROUP_EMAIL,
                'label'   => 'Encrypt Email Passwords',
                'type'    => 'checkbox',
                'default' => 'N',
                'level'   => '1',
            ],

            // ============================================================
            // FTP
            // ============================================================
            'FTP_DEFAULT_UID' => [
                'group'   => self::GROUP_FTP,
                'label'   => 'Default UID for new FTP accounts',
                'help'    => 'Numeric UID stored in the proftpd row. Must be >= SQLMinID in proftpd config (typically 500).',
                'type'    => 'text',
                'default' => '5000',
                'level'   => '1',
            ],
            'FTP_DEFAULT_GID' => [
                'group'   => self::GROUP_FTP,
                'label'   => 'Default GID for new FTP accounts',
                'help'    => 'Numeric GID stored in the proftpd row. Used unless a per-domain group exists.',
                'type'    => 'text',
                'default' => '5000',
                'level'   => '1',
            ],
            'FTP_QUOTA' => [
                'group'   => self::GROUP_FTP,
                'label'   => 'FTP Quota Type',
                'help'    => 'Default limit_type for new pmwh3_ftp_quota_limits rows. soft = warn; hard = block.',
                'type'    => 'select',
                'default' => 'soft',
                'options' => ['soft' => 'soft', 'hard' => 'hard'],
                'level'   => '1',
            ],
            'ENCRYPT_FTP_PASS' => [
                'group'   => self::GROUP_FTP,
                'label'   => 'Encrypt FTP Passwords',
                'help'    => 'When off, plain passwords are stored. When on, SHA-256 hex (proftpd SQLPasswordEngine on / Encoding hex).',
                'type'    => 'checkbox',
                'default' => 'N',
                'level'   => '1',
            ],

            // ============================================================
            // DNS
            // ============================================================
            'DNS_SERVERS' => [
                'group'   => self::GROUP_DNS,
                'label'   => 'Nameservers',
                'help'    => 'One per line. The first is treated as the primary NS in the SOA record.',
                'type'    => 'textarea',
                'default' => '',
                'level'   => '0',
                'on_save' => 'updateNameservers',
            ],
            'MX_SERVERS' => [
                'group'   => self::GROUP_DNS,
                'label'   => 'MX Servers',
                'help'    => 'One per line. Used to populate MX records on new zones.',
                'type'    => 'textarea',
                'default' => '',
                'level'   => '0',
            ],
            'DNS_TYPE' => [
                'group'      => self::GROUP_DNS,
                'label'      => 'DNS Backend',
                'help'       => 'Adapters detected automatically from utils/dns/. Adding a new adapter only requires dropping the file in.',
                'type'       => 'select',
                'default'    => 'pdns',
                'options_fn' => 'dnsAdapters',
                'level'      => '0',
            ],
            'FILTER_POLICY_TYPE' => [
                'group'   => self::GROUP_EMAIL,
                'label'   => 'Filter policy export',
                'help'    => 'Where pmwh3_filtering / pmwh3_wblist are exported to. rspamd serves HTTP map endpoints (rspamd pulls), none keeps the tables UI-only.',
                'type'    => 'select',
                'default' => 'rspamd',
                'options' => [
                    'rspamd' => 'Rspamd (HTTP maps)',
                    'none'   => 'No automation (UI only)',
                ],
                'level'   => '0',
            ],
            'RSPAMD_MAP_TOKEN' => [
                'group'   => self::GROUP_EMAIL,
                'label'   => 'Rspamd map token',
                'help'    => 'Optional shared secret; rspamd map URLs must then carry ?key=<token>. Empty = open (internal network only).',
                'type'    => 'text',
                'default' => '',
                'level'   => '0',
            ],
            'RSPAMD_API_URL' => [
                'group'   => self::GROUP_EMAIL,
                'label'   => 'Rspamd controller API URL',
                'help'    => 'Used by pmwh3 for ping / stat / learning calls. Default http://rspamd:11334; empty disables API calls.',
                'type'    => 'text',
                'default' => 'http://rspamd:11334',
                'level'   => '0',
            ],
            'RSPAMD_API_PASSWORD' => [
                'group'   => self::GROUP_EMAIL,
                'label'   => 'Rspamd controller password',
                'help'    => 'Optional; sent as the "Password" header to the controller API.',
                'type'    => 'text',
                'default' => '',
                'level'   => '0',
            ],
            'RSPAMD_WORKER_URL' => [
                'group'   => self::GROUP_EMAIL,
                'label'   => 'Rspamd worker-normal URL',
                'help'    => 'For message checks from pmwh3 (POST /checkv2), Default http://rspamd:11333; empty disables the scan helper.',
                'type'    => 'text',
                'default' => 'http://rspamd:11333',
                'level'   => '0',
            ],
            'DOVEADM_URL' => [
                'group'   => self::GROUP_EMAIL,
                'label'   => 'Dovecot doveadm endpoint',
                'help'    => 'host:port of the doveadm-server inside the dovecot container (None then live quota lookups). Used by the mail adapter (postfix) for accurate "used" numbers that match what mail clients see.',
                'type'    => 'text',
                'default' => 'dovecot:24424',
                'level'   => '0',
            ],
            'DOVEADM_PASSWORD' => [
                'group'   => self::GROUP_EMAIL,
                'label'   => 'Dovecot doveadm password',
                'help'    => 'Shared secret for the doveadm-server (must match the dovecot config). Stored plain in pmwh3_configuration like the other service credentials.',
                'type'    => 'password',
                'default' => '',
                'level'   => '0',
            ],
            'BACKUP_DIR' => [
                'group'   => self::GROUP_SYSTEM,
                'label'   => 'Backup directory',
                'help'    => 'Directory for pmwh3 SQL dumps. Must NOT be inside the source tree and must be writable by the web server user. The directory is created on first backup.',
                'type'    => 'text',
                'default' => '/vhome/backup/pmwh3',
                'level'   => '0',
            ],
            'PDNS_API_URL' => [
                'group'   => self::GROUP_DNS,
                'label'   => 'PowerDNS API base URL',
                'help'    => 'Base URL of the PowerDNS HTTP API, e.g. http://pdns:8081/api/v1. Required for DNSSEC zone signing / disabling. If empty, DNSSEC is read-only.',
                'type'    => 'text',
                'default' => '',
                'level'   => '1',
            ],
            'PDNS_API_KEY' => [
                'group'   => self::GROUP_DNS,
                'label'   => 'PowerDNS API key',
                'help'    => 'Value of the api-key= setting in pdns.conf. Sent as the X-API-Key header.',
                'type'    => 'password',
                'default' => '',
                'level'   => '1',
            ],
            'PDNS_API_SERVER_ID' => [
                'group'   => self::GROUP_DNS,
                'label'   => 'PowerDNS API server id',
                'help'    => 'Server identifier in the API path (.../api/v1/servers/<server_id>/...). Defaults to "localhost", which is correct for almost every install.',
                'type'    => 'text',
                'default' => 'localhost',
                'level'   => '1',
            ],
            'DNS_REFRESH' => [
                'group'   => self::GROUP_DNS,
                'label'   => 'SOA Refresh',
                'type'    => 'int',
                'default' => '10800',
                'level'   => '1',
                'on_save' => 'updateSoaTimers',
            ],
            'DNS_RETRY' => [
                'group'   => self::GROUP_DNS,
                'label'   => 'SOA Retry',
                'type'    => 'int',
                'default' => '3600',
                'level'   => '1',
                'on_save' => 'updateSoaTimers',
            ],
            'DNS_EXPIRE' => [
                'group'   => self::GROUP_DNS,
                'label'   => 'SOA Expire',
                'type'    => 'int',
                'default' => '604800',
                'level'   => '1',
                'on_save' => 'updateSoaTimers',
            ],
            'DNS_MINIMUM' => [
                'group'   => self::GROUP_DNS,
                'label'   => 'SOA Minimum',
                'type'    => 'int',
                'default' => '3600',
                'level'   => '1',
                'on_save' => 'updateSoaTimers',
            ],
            'DNS_TTL' => [
                'group'   => self::GROUP_DNS,
                'label'   => 'Default Record TTL',
                'type'    => 'int',
                'default' => '3600',
                'level'   => '1',
                'on_save' => 'updateSoaTimers',
            ],

            // ============================================================
            // DATABASES
            // ============================================================
            'DB_TYPE' => [
                'group'   => self::GROUP_DATABASES,
                'label'   => 'Database backend',
                'help'    => 'Which DB server pmwh3 provisions customer databases on. Only mysql is implemented for now; the value is here so adapter selection can be added later (postgres, etc).',
                'type'    => 'select',
                'options' => ['mysql' => 'MySQL / MariaDB'],
                'default' => 'mysql',
                'level'   => '1',
            ],
            'DB_HOST' => [
                'group'   => self::GROUP_DATABASES,
                'label'   => 'DB server host',
                'help'    => 'Hostname or IP of the database server pmwh3 should provision new customer databases on.',
                'type'    => 'text',
                'default' => 'mariadb',
                'level'   => '1',
            ],
            'DB_PORT' => [
                'group'   => self::GROUP_DATABASES,
                'label'   => 'DB server port',
                'type'    => 'int',
                'default' => '3306',
                'level'   => '1',
            ],
            'DB_ADMIN_USER' => [
                'group'   => self::GROUP_DATABASES,
                'label'   => 'DB admin user',
                'help'    => 'User pmwh3 connects with to run CREATE DATABASE / GRANT / DROP. Needs CREATE, GRANT OPTION, DROP, and the ability to create users (CREATE USER). Not root by default -- a dedicated pmwh3_admin user is recommended.',
                'type'    => 'text',
                'default' => '',
                'level'   => '1',
            ],
            'DB_ADMIN_PASS' => [
                'group'   => self::GROUP_DATABASES,
                'label'   => 'DB admin password',
                'help'    => 'Password for the DB admin user above. Stored as plain text in pmwh3_configuration. Make sure the file/DB is not world-readable.',
                'type'    => 'password',
                'default' => '',
                'level'   => '1',
            ],
            'DB_NAME_PREFIX' => [
                'group'   => self::GROUP_DATABASES,
                'label'   => 'Database name prefix style',
                'help'    => 'How new database names are composed. customer = prefix with the customer name and underscore (e.g. customer_blog). none = use the suffix as-is.',
                'type'    => 'select',
                'options' => ['customer' => 'customer_<suffix>', 'none' => '<suffix>'],
                'default' => 'customer',
                'level'   => '1',
            ],
            'DB_DEFAULT_HOST' => [
                'group'   => self::GROUP_DATABASES,
                'label'   => 'Default user host for new grants',
                'help'    => 'The HOST part of new GRANTs (user@host). % allows connections from anywhere; localhost or an IP restricts access. % is the usual choice when the web server and the database server are separate containers/hosts.',
                'type'    => 'text',
                'default' => '%',
                'level'   => '1',
            ],
            'DB_ADVANCED' => [
                'group'   => self::GROUP_DATABASES,
                'label'   => 'Advanced DB Management',
                'help'    => 'Show per-grant privilege checkboxes (SELECT/INSERT/UPDATE/...) in the new-user form. Off = "full access" toggle only.',
                'type'    => 'checkbox',
                'default' => 'Y',
                'level'   => '0',
            ],
            'PHPMYADMIN_URL' => [
                'group'   => self::GROUP_DATABASES,
                'label'   => 'phpMyAdmin URL',
                'help'    => 'Full URL of a phpMyAdmin instance, including scheme (e.g. https://pma.example.com). Used by the "Phpmyadmin" sidebar link in the Databases box; opens in a new tab. Leave empty to hide the link.',
                'type'    => 'text',
                'default' => '',
                'level'   => '0',
            ],

            // ============================================================
            // CUSTOMER
            // ============================================================
            'CUSTOMER_NO' => [
                'group'   => self::GROUP_CUSTOMER,
                'label'   => 'Next Customer Number',
                'type'    => 'int',
                'default' => '1',
                'level'   => '1',
            ],

            // ============================================================
            // DOMAINS (per-domain handling: subdomain auto-creation, ...)
            // ============================================================
            'CREATE_SUBDOMAIN' => [
                'group'   => self::GROUP_DOMAINS,
                'label'   => 'Create Default Subdomains',
                'help'    => 'On a new domain, automatically create the standard subdomains from the pattern below. Only when the customer row has "Standard subdomains" checked.',
                'type'    => 'checkbox',
                'default' => 'Y',
                'level'   => '1',
            ],
            'AUTO_SUBDOMAIN' => [
                'group'   => self::GROUP_DOMAINS,
                'label'   => 'Auto-Subdomain Pattern',
                'help'    => 'Format: name(alias1,alias2);name(alias1) -- name becomes a directory subdomain, the aliases become alias rows of it.',
                'type'    => 'text',
                'default' => 'www(ftp);smtp(imap,pop3,mail)',
                'level'   => '1',
            ],
            'CREATE_DIRECTORIES' => [
                'group'   => self::GROUP_DOMAINS,
                'label'   => 'Create Filesystem Directories',
                'type'    => 'checkbox',
                'default' => 'Y',
                'level'   => '1',
            ],
            'COUNT_SUBDOMAIN_ALIASES' => [
                'group'   => self::GROUP_DOMAINS,
                'label'   => 'Count Subdomain Aliases',
                'help'    => 'When Y, aliases consume the same quota as subdomains.',
                'type'    => 'checkbox',
                'default' => 'N',
                'level'   => '1',
            ],
            'DELETE_SUBDOMAIN_ALIASES' => [
                'group'   => self::GROUP_DOMAINS,
                'label'   => 'Cascade-Delete Subdomain Aliases',
                'type'    => 'checkbox',
                'default' => 'Y',
                'level'   => '1',
            ],

            // ============================================================
            // MESSAGES
            // ============================================================
            'USE_MESSAGE_SYSTEM' => [
                'group'   => self::GROUP_MESSAGES,
                'label'   => 'Use Message System',
                'type'    => 'checkbox',
                'default' => 'Y',
                'level'   => '1',
            ],

            // ============================================================
            // CONFIRMATION
            // ============================================================
'CONFIRM_CHANGES' => [
                'group'   => self::GROUP_CONFIRMATION,
                'label'   => 'Confirm Changes',
                'type'    => 'checkbox',
                'default' => 'Y',
                'help'    => 'Show a confirmation dialog before saving changes (e.g. password resets, DNSSEC signing). When off, changes apply immediately.',
                'level'   => '1',
            ],
            'CONFIRM_DELETE' => [
                'group'   => self::GROUP_CONFIRMATION,
                'label'   => 'Confirm Deletes',
                'type'    => 'checkbox',
                'default' => 'Y',
                'help'    => 'Show a confirmation dialog before deleting entries (customers, domains, mailboxes, records, etc.). When off, deletes apply immediately.',
                'level'   => '1',
            ],

            // ============================================================
            // SESSION
            // ============================================================
            // LAYOUT
            // ============================================================
            'APPS_PER_ROW' => [
                'group'   => self::GROUP_LAYOUT,
                'label'   => 'Apps per Row',
                'type'    => 'select',
                'default' => '2',
                'options' => [
                    'OFF' => 'OFF', '1' => '1', '2' => '2', '3' => '3', '4' => '4', '5' => '5',
                ],
                'level'   => '1',
            ],
            'MAX_NEWS' => [
                'group'   => self::GROUP_LAYOUT,
                'label'   => 'Max News Items',
                'help'    => 'How many news items the login ticker and the admin news overview show at most.',
                'type'    => 'int',
                'default' => '10',
                'level'   => '1',
            ],
            'ICON_THEME' => [
                'group'      => self::GROUP_LAYOUT,
                'label'      => 'Icon Theme',
                'type'       => 'select',
                'default'    => '16x16-CC',
                'options_fn' => 'iconThemes',
                'level'      => '1',
            ],
            'SHOW_NEWS' => [
                'group'   => self::GROUP_LAYOUT,
                'label'   => 'Show News',
                'type'    => 'checkbox',
                'default' => 'Y',
                'level'   => '1',
            ],

            // ============================================================
            // ERRORLOG (configuration only -- the viewer is in Tools)
            // ============================================================
            'ERRORLOG_TARGET' => [
                'group'   => self::GROUP_ERRORLOG,
                'label'   => 'Errorlog target',
                'help'    => 'Where errors are written. "file" writes to the log path the cevian deployment configures (php_settings.error_log_path in config/app.json, default var/log/error.log). "syslog" goes to the system logger (not viewable in the Errorlog viewer). "db" persists into pmwh3_errorlog (table created on first write).',
                'type'    => 'select',
                'default' => 'file',
                'options' => [
                    'file'   => 'File (cevian log path)',
                    'syslog' => 'Syslog',
                    'db'     => 'Database',
                ],
                'level'   => '0',
            ],
            // The "file" target path comes from the cevian deployment
            // config (php_settings.error_log_path in config/app.json,
            // default var/log/error.log) -- see
            // ErrorHandler::logFilePath(). Not settable here.
            'ERRORLOG_LEVEL' => [
                'group'   => self::GROUP_ERRORLOG,
                'label'   => 'Minimum log level',
                'type'    => 'select',
                'default' => 'warning',
                'options' => [
                    'debug'   => 'Debug',
                    'info'    => 'Info',
                    'notice'  => 'Notice',
                    'warning' => 'Warning',
                    'error'   => 'Error',
                    'critical'=> 'Critical',
                ],
                'level'   => '0',
            ],
            'ERRORLOG_SCOPE' => [
                'group'   => self::GROUP_ERRORLOG,
                'label'   => 'Capture scope',
                'help'    => 'Which sources to log. Multiple selections allowed. "php" is required for PHP native errors.',
                'type'    => 'multi_select',
                'default' => 'app,framework,php',
                'options' => [
                    'app'        => 'Application (pmwh3 modules)',
                    'framework'  => 'Framework (cevian)',
                    'php'        => 'PHP errors / warnings',
                    'database'   => 'Database errors',
                ],
                'level'   => '1',
            ],
            'ERRORLOG_INCLUDE_TRACE' => [
                'group'   => self::GROUP_ERRORLOG,
                'label'   => 'Include stack traces',
                'help'    => 'Helpful for debugging, but verbose. Turn off in production if log size is a concern.',
                'type'    => 'checkbox',
                'default' => 'Y',
                'level'   => '1',
            ],
            'ERRORLOG_RETAIN_DAYS' => [
                'group'   => self::GROUP_ERRORLOG,
                'label'   => 'Retention (days)',
                'help'    => 'Older entries are eligible for purging by the Errorlog viewer / a cron job. 0 = keep forever.',
                'type'    => 'int',
                'default' => '30',
                'level'   => '1',
            ],
        ];
    }
}
