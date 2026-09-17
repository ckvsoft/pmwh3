<?php

namespace pmwh3\Utils\Mail;

use ckvsoft\mvc\Config;
use ckvsoft\Database;
use ckvsoft\CkvException;
use pmwh3\Config\LazyConfig;
use pmwh3\Utils\ServiceDb;
use pmwh3\Utils\DomainUtil;

/**
 * Postfix + Dovecot via shared SQL tables.
 *
 * Consolidated pmwh3 mail store (2026-09-17, "mail == mail"):
 *
 *   pmwh3_mail_accounts    (email PK, login, password, name, uid, gid,
 *                           homedir, maildir, quota_bytes INT, active Y/N)
 *   pmwh3_mail_forwardings (source PK, destination text - comma list)
 *   pmwh3_mail_transport   (domain PK, destination, master_destination -
 *                           postfix-only routing, provisioned by
 *                           syncDomainTransport() out of MAIL_TRANSPORT /
 *                           MAIL_MASTER_IP on domain create/update/delete)
 *
 * Quota USAGE is NOT stored in these tables -- the live numbers come
 * from the mail system itself (doveadm HTTP API, liveQuota()). Optional
 * quota_clone mirrors them into pmwh3_mail_accounts.used_*. The legacy
 * vendor tables (postfix_users, postfix_forwardings, dovecot_quota) live
 * in their stack database and belong to the postfix/dovecot
 * configuration, NOT to pmwh3.
 *
 * Catchalls are forwardings whose source is '@domain'.
 */
class PostfixAdapter implements MailAdapterInterface
{

    public static function getKey(): string  { return 'postfix'; }
    public static function getName(): string { return 'Postfix + Dovecot'; }
    public static function isAvailable(): bool
    {
        try {
            self::initDb();
            return self::db()->tableExists('pmwh3_mail_accounts');
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static ?Database $db = null;

    public static function initDb(): void
    {
        if (self::$db !== null) {
            return;
        }
        self::$db = ServiceDb::get('mail');
    }

    private static function db(): Database
    {
        if (self::$db === null) {
            self::initDb();
        }
        return self::$db;
    }

    // ---- helpers ----

    /** Encrypt password if ENCRYPT_EMAIL_PASS=Y, else store plain. */
    private static function maybeEncrypt(string $plain): string
    {
        $encrypt = strtoupper((string) LazyConfig::get('ENCRYPT_EMAIL_PASS', 'N')) === 'Y';
        return $encrypt ? crypt($plain) : $plain;
    }

    /** Inverse of formatQuota. Returns 0 if unparseable. */
    public static function parseQuota(?string $raw): int
    {
        if ($raw === null || $raw === '') {
            return 0;
        }
        if (preg_match('/^(\d+)/', $raw, $m)) {
            return (int) $m[1];
        }
        return 0;
    }

    /**
     * Build the on-disk maildir path for a given email address using
     * HOMEDIR + MAILDIR pattern from configuration.
     */
    public static function resolveMaildirPath(string $email): array
    {
        [$user, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $homedir = rtrim((string) LazyConfig::get('HOMEDIR', '/vhome/vmail'), '/');
        $pattern = trim((string) LazyConfig::get('MAILDIR', '[DOMAIN]/[USER]'), '/');
        $maildir = str_replace(
                ['[DOMAIN]', '[USER]'],
                [$domain, $user],
                $pattern
        );
        return ['homedir' => $homedir, 'maildir' => $maildir];
    }

    // ---- Mailboxes ----

    public static function listMailboxes(string $domain, string $orderBy = 'email'): array
    {
        $domain = DomainUtil::domainToAscii($domain);
        $orderBy = self::sanitizeOrder($orderBy, ['email', 'login', 'name']);

        $rows = self::db()->select(
                "SELECT * FROM pmwh3_mail_accounts
                  WHERE email LIKE :p
                  ORDER BY {$orderBy}",
                ['p' => "%@{$domain}"]
        );
        foreach ($rows as &$row) {
            $row['email_utf8'] = DomainUtil::domainToUtf8($row['email']);
        }
        unset($row);
        return $rows;
    }

    public static function getMailbox(string $email): ?array
    {
        $email = DomainUtil::domainToAscii($email);
        return self::db()->selectOne(
                "SELECT * FROM pmwh3_mail_accounts WHERE email = :e",
                ['e' => $email]
        ) ?: null;
    }

    /**
     * @param array $row     Required: email. Optional: login, name, quota_bytes.
     * @param string $plainPassword
     */
    public static function createMailbox(array $row, string $plainPassword): bool
    {
        $email = DomainUtil::domainToAscii((string) ($row['email'] ?? ''));
        if ($email === '' || strpos($email, '@') === false) {
            throw new CkvException(__("Invalid email address"));
        }

        // Existing? Reject.
        if (self::getMailbox($email) !== null) {
            throw new CkvException(sprintf(__("Mailbox %s already exists"), $email));
        }

        $paths = self::resolveMaildirPath($email);

        $insertRow = [
            'email'    => $email,
            'login'    => $row['login']    ?? $email,
            'password' => self::maybeEncrypt($plainPassword),
            'name'     => $row['name']     ?? '',
            'uid'      => (int) LazyConfig::get('MAIL_USER_UID', 5000),
            'gid'      => (int) LazyConfig::get('MAIL_USER_GID', 5000),
            'homedir'  => $paths['homedir'],
            'maildir'  => $paths['maildir'],
            'quota_bytes' => max(0, (int) ($row['quota_bytes'] ?? 0)),
            'active'   => 'Y',
        ];

        self::db()->insert('pmwh3_mail_accounts', $insertRow);

        // G7 Auto-Provision (pmwh2 insertEmail semantics, best effort --
        // never let automation break mailbox creation):
        //   AUTO_EMAIL: on the FIRST mailbox of a domain, create the
        //     standard admin-role forwards pointing to this first
        //     mailbox. RFC 2142: postmaster@ is MANDATORY and is
        //     therefore ALWAYS created (option off changes nothing
        //     about it); every other role comes from the AUTO_EMAIL
        //     comma list (webmaster, abuse, hostmaster, ...).
        //   WELCOME_MAIL: send the welcome mail to the new mailbox
        //     (initialises the maildir via first delivery + informs
        //     the user).
        try {
            $domain = ($p = strrpos($email, '@')) !== false ? substr($email, $p + 1) : '';
            if ($domain !== '') {
                $roles = ['postmaster'];
                foreach (array_map('trim',
                        explode(',', (string) LazyConfig::get('AUTO_EMAIL', 'webmaster, abuse, hostmaster')))
                        as $role) {
                    if ($role !== '' && !in_array(strtolower($role), $roles, true)) {
                        $roles[] = strtolower($role);
                    }
                }
                if (self::countMailboxes($domain) === 1) {
                    foreach ($roles as $role) {
                        if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-\._]*)$/', $role)) {
                            continue;
                        }
                        try {
                            self::setForward(strtolower($role) . '@' . $domain, $email);
                        } catch (\Throwable $fe) {
                            \pmwh3\Utils\ErrorHandler::trace("AUTO_EMAIL forward {$role}@{$domain}: "
                                    . $fe->getMessage());
                        }
                    }
                }
                self::sendWelcomeMail($email, $domain);
            }
        } catch (\Throwable $e) {
            \pmwh3\Utils\ErrorHandler::trace("G7 auto-provision for {$email}: " . $e->getMessage());
        }
        return true;
    }

    /**
     * Send the WELCOME_MAIL template to a freshly created mailbox.
     * Placeholders (pmwh2 semantics): [EMAILUSER] local part,
     * [EMAIL] address, [DOMAIN] domain, [CREATOR] user name of the
     * domain owner, [CREATOR_REALNAME] real name of the domain owner.
     * Best effort: SMTP failures are logged, never fatal.
     */
    private static function sendWelcomeMail(string $email, string $domain): void
    {
        $tmpl = (string) LazyConfig::get('WELCOME_MAIL', '');
        if (trim($tmpl) === '') {
            return;
        }
        $owner = Config::moduleDb()->selectOne(
                "SELECT cid FROM pmwh3_domains WHERE domain = :d LIMIT 1",
                ['d' => $domain]) ?: null;
        $creatorName    = '';
        $creatorRealname = '';
        $cid = (int) ($owner['cid'] ?? 0);
        if ($cid > 0) {
            $cust = Config::moduleDb()->selectOne(
                    "SELECT customer, realname FROM pmwh3_customers WHERE cid = :c LIMIT 1",
                    ['c' => $cid]);
            $creatorName     = (string) ($cust['customer'] ?? '');
            $creatorRealname = (string) ($cust['realname'] ?? '');
        }
        $user = ($p = strpos($email, '@')) !== false ? substr($email, 0, $p) : '';
        $body = str_replace(
                ['[EMAILUSER]', '[EMAIL]', '[DOMAIN]', '[CREATOR]', '[CREATOR_REALNAME]'],
                [$user, $email, $domain, $creatorName, $creatorRealname],
                $tmpl);
        $subject = sprintf(__('Welcome to %s'), $domain);

        // Class exists? (unit-test isolation: Mailer may be absent in
        // stripped-down environments) -- keep G7 automation non-fatal.
        if (!class_exists('\\ckvsoft\\Mailer')) {
            \pmwh3\Utils\ErrorHandler::trace("WELCOME_MAIL skipped (Mailer class unavailable) for {$email}");
            return;
        }
        $mailer = new \ckvsoft\Mailer();
        if (!$mailer->send($email, $subject, $body)) {
            \pmwh3\Utils\ErrorHandler::trace("WELCOME_MAIL send failed for {$email}: "
                    . (string) $mailer->lastError);
        }
    }

    public static function updateMailbox(string $email, array $row): bool
    {
        $email = DomainUtil::domainToAscii($email);

        $update = [];
        foreach (['login', 'name'] as $f) {
            if (array_key_exists($f, $row)) {
                $update[$f] = $row[$f];
            }
        }
        if (array_key_exists('quota_bytes', $row)) {
            $update['quota_bytes'] = max(0, (int) $row['quota_bytes']);
        }
        if (array_key_exists('active', $row)) {
            $update['active'] = (strtoupper((string) $row['active']) === 'Y') ? 'Y' : 'N';
        }
        if (empty($update)) {
            return true;
        }
        self::db()->update('pmwh3_mail_accounts', $update, 'email = :e', ['e' => $email]);
        return true;
    }

    public static function changePassword(string $email, string $plainPassword): bool
    {
        $email = DomainUtil::domainToAscii($email);
        self::db()->update('pmwh3_mail_accounts',
                ['password' => self::maybeEncrypt($plainPassword)],
                'email = :e', ['e' => $email]);
        return true;
    }

    public static function verifyPassword(string $email, string $plainPassword): bool
    {
        if ($email === '' || $plainPassword === '') {
            return false;
        }
        $email = DomainUtil::domainToAscii($email);
        $row = self::db()->selectOne(
                "SELECT password FROM pmwh3_mail_accounts WHERE email = :e",
                ['e' => $email]
        );
        if (!$row) {
            return false;
        }
        $stored  = (string) ($row['password'] ?? '');
        if ($stored === '') {
            return false;
        }
        // Branch on the same option that controls storage:
        //   Y -> crypt() with stored hash as salt
        //   N -> plain compare
        $encrypt = strtoupper((string) LazyConfig::get('ENCRYPT_EMAIL_PASS', 'N')) === 'Y';
        if ($encrypt) {
            return hash_equals($stored, crypt($plainPassword, $stored));
        }
        return hash_equals($stored, $plainPassword);
    }

    public static function deleteMailbox(string $email): bool
    {
        $email = DomainUtil::domainToAscii($email);
        self::db()->delete('pmwh3_mail_accounts', 'email = :e', ['e' => $email]);
        return true;
    }

    public static function countMailboxes(string $domain): int
    {
        $domain = DomainUtil::domainToAscii($domain);
        $row = self::db()->selectOne(
                "SELECT COUNT(*) AS c FROM pmwh3_mail_accounts WHERE email LIKE :p",
                ['p' => "%@{$domain}"]
        );
        return (int) ($row['c'] ?? 0);
    }

    // ---- Forwards ----

    public static function listForwards(string $domain, string $orderBy = 'source'): array
    {
        $domain = DomainUtil::domainToAscii($domain);
        $orderBy = self::sanitizeOrder($orderBy, ['source', 'destination']);

        $rows = self::db()->select(
                "SELECT * FROM pmwh3_mail_forwardings
                  WHERE source LIKE :p
                    AND source <> :catchall
                    AND source <> destination
               ORDER BY {$orderBy}",
                ['p' => "%@{$domain}", 'catchall' => "@{$domain}"]
        );
        foreach ($rows as &$row) {
            $row['source_utf8'] = DomainUtil::domainToUtf8($row['source']);
        }
        unset($row);
        return $rows;
    }

    public static function getForward(string $source): ?array
    {
        $source = DomainUtil::domainToAscii($source);
        $row = self::db()->selectOne(
                "SELECT * FROM pmwh3_mail_forwardings WHERE source = :s",
                ['s' => $source]
        );
        return $row ?: null;
    }

    public static function setForward(string $source, $destinations): bool
    {
        $source = DomainUtil::domainToAscii($source);

        if (is_array($destinations)) {
            $destList = $destinations;
        } else {
            $destList = preg_split('/[\r\n,]+/', (string) $destinations) ?: [];
        }
        $destList = array_filter(array_map('trim', $destList));
        if (empty($destList)) {
            throw new CkvException(__("At least one destination is required"));
        }

        $destString = implode(',', $destList);

        $existing = self::getForward($source);
        if ($existing) {
            self::db()->update('pmwh3_mail_forwardings',
                    ['destination' => $destString],
                    'source = :s', ['s' => $source]);
        } else {
            self::db()->insert('pmwh3_mail_forwardings', [
                'source'      => $source,
                'destination' => $destString,
            ]);
        }
        return true;
    }

    public static function deleteForward(string $source): bool
    {
        $source = DomainUtil::domainToAscii($source);
        self::db()->delete('pmwh3_mail_forwardings', 'source = :s', ['s' => $source]);
        return true;
    }

    public static function countForwards(string $domain): int
    {
        $domain = DomainUtil::domainToAscii($domain);
        $row = self::db()->selectOne(
                "SELECT COUNT(*) AS c FROM pmwh3_mail_forwardings
                  WHERE source LIKE :p AND source <> :catchall",
                ['p' => "%@{$domain}", 'catchall' => "@{$domain}"]
        );
        return (int) ($row['c'] ?? 0);
    }

    // ---- Catchalls ----

    public static function listCatchalls(string $domain): array
    {
        $domain = DomainUtil::domainToAscii($domain);
        return self::db()->select(
                "SELECT * FROM pmwh3_mail_forwardings WHERE source = :s",
                ['s' => "@{$domain}"]
        );
    }

    public static function setCatchall(string $domain, string $destination): bool
    {
        return self::setForward('@' . DomainUtil::domainToAscii($domain), $destination);
    }

    public static function deleteCatchall(string $domain): bool
    {
        return self::deleteForward('@' . DomainUtil::domainToAscii($domain));
    }

    public static function countCatchalls(string $domain): int
    {
        $domain = DomainUtil::domainToAscii($domain);
        $row = self::db()->selectOne(
                "SELECT COUNT(*) AS c FROM pmwh3_mail_forwardings WHERE source = :s",
                ['s' => "@{$domain}"]
        );
        return (int) ($row['c'] ?? 0);
    }

    // ---- safety ----

    /**
     * Sync the postfix routing row (pmwh3_mail_transport) for a domain.
     *
     * Postfix-only concept (soft capability -- MailManager::syncDomainTransport()
     * no-ops for adapters without this method). Keeps the two transport maps
     * in contrib/postfix working:
     *
     *   - enabled + MAIL_MASTER_IP set  -> row (destination = MAIL_TRANSPORT,
     *     master_destination = smtp:[<IP>]:25) so master/backup routing works
     *   - enabled + MAIL_MASTER_IP empty -> no row (single-server: postfix
     *     uses its default virtual_transport)
     *   - disabled                       -> row deleted
     */
    public static function syncDomainTransport(string $domain, bool $enabled): bool
    {
        $db   = self::db();
        $db->delete('pmwh3_mail_transport', 'domain = :d', ['d' => $domain]);
        if (!$enabled) {
            return true;
        }
        $masterIp = strtolower(trim((string) LazyConfig::get('MAIL_MASTER_IP', '')));
        if ($masterIp === '') {
            return true;
        }
        $destination = trim((string) LazyConfig::get('MAIL_TRANSPORT', 'lmtp:inet:dovecot:24'));
        if ($destination === '') {
            $destination = 'lmtp:inet:dovecot:24';
        }
        $db->insert('pmwh3_mail_transport', [
            'domain'             => $domain,
            'destination'        => $destination,
            'master_destination' => 'smtp:[' . $masterIp . ']:25',
        ]);
        return true;
    }

    private static function sanitizeOrder(string $order, array $allowed): string
    {
        return in_array($order, $allowed, true) ? $order : $allowed[0];
    }

    // ---- live quota (doveadm HTTP API) -------------------------------

    /**
     * Live quota values straight from Dovecot via the doveadm HTTP API
     * (DOVEADM_URL + DOVEADM_PASSWORD, default dovecot:24424).
     *
     * This is the source of truth mail clients see (the quota count
     * driver tracks usage in the index files) -- unlike the
     * dovecot_quota SQL rows, which are stale leftovers from the
     * legacy dict era and NOT kept current by Dovecot 2.4.
     *
     * Adapter-specific on purpose: only the postfix adapter talks to
     * a dovecot instance. Other mail adapters simply don't have it.
     *
     * @return array{storage_kb:int, storage_limit_kb:int, percent:int, messages:int}|null
     *         null when not configured or the endpoint is unreachable.
     */
    public static function liveQuota(string $email): ?array
    {
        $secret = trim((string) LazyConfig::get('DOVEADM_PASSWORD', ''));
        $host   = trim((string) LazyConfig::get('DOVEADM_URL', 'dovecot:24424'));
        if ($secret === '' || $host === '') {
            return null;
        }
        if (!str_contains($host, '://')) {
            $host = 'http://' . $host;
        }

        $payload = json_encode([['quotaGet',
                ['user' => DomainUtil::domainToAscii($email)], 't1']]);
        $ctx = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => 'Authorization: Basic ' . base64_encode('doveadm:' . $secret)
                           . "\r\nContent-Type: application/json\r\nContent-Length: " . strlen($payload),
                'content' => $payload,
                'timeout' => 5,
                'ignore_errors' => true,
            ],
        ]);
        $body = @file_get_contents($host . '/doveadm/v1', false, $ctx);
        if ($body === false) {
            \pmwh3\Utils\ErrorHandler::trace(
                    "[PostfixAdapter.liveQuota] doveadm unreachable at {$host}");
            return null;
        }

        $rows  = null;
        foreach ((array) json_decode((string) $body, true) as $item) {
            if (($item[0] ?? '') === 'doveadmResponse' && ($item[2] ?? '') === 't1') {
                $rows = (array) ($item[1] ?? []);
            }
        }
        if ($rows === null) {
            \pmwh3\Utils\ErrorHandler::trace(
                    '[PostfixAdapter.liveQuota] doveadm error response: '
                    . substr((string) $body, 0, 200));
            return null;
        }

        $storage  = null;
        $messages = 0;
        foreach ($rows as $row) {
            $t = (string) ($row['type'] ?? '');
            if ($t === 'STORAGE') {
                $storage = $row;
            } elseif ($t === 'MESSAGE') {
                $messages = (int) ($row['value'] ?? 0);
            }
        }
        if ($storage === null) {
            \pmwh3\Utils\ErrorHandler::trace(
                    '[PostfixAdapter.liveQuota] no STORAGE row for ' . $email);
            return null;
        }
        return [
            'storage_kb'       => (int) ($storage['value'] ?? 0),
            'storage_limit_kb' => (int) ($storage['limit'] ?? 0),
            'percent'          => (int) ($storage['percent'] ?? 0),
            'messages'         => $messages,
        ];
    }
}
