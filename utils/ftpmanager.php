<?php

namespace pmwh3\Utils;

use ckvsoft\mvc\Config;
use pmwh3\Config\LazyConfig;
use pmwh3\Utils\ServiceDb;

/**
 * Facade over the proftpd* tables. Read functions return arrays
 * exactly as the UI needs them (already joined with quotalimits
 * / quotatallies where applicable). Writes are guarded against
 * the common mistakes:
 *   - never run on partial data
 *   - never bypass password hashing
 *   - never leave stale quota rows behind on delete
 *
 * Naming convention: methods are verb-first and the noun matches
 * what the UI calls them (Account, Group, Quota).
 *
 * Passwords:
 *   pmwh3_configuration.ENCRYPT_FTP_PASS  -- 'Y' / 'N'
 *     N (default) -> store as plain text (proftpd SQLAuthTypes
 *                    must include Plaintext)
 *     Y           -> store SHA-256 hex (proftpd
 *                    SQLPasswordEngine on, Encoding hex)
 *
 * Whatever's chosen, the same value MUST be readable by proftpd's
 * configured auth chain. If you change the option while there are
 * existing accounts, old plain passwords keep working (proftpd's
 * SQLAuthTypes list usually still has Plaintext as fallback) but
 * new accounts use the new format.
 */
class FtpManager
{
    // ===== Read =========================================================

    /**
     * All FTP accounts visible to one viewer (admin sees every
     * row, customers see rows where master = their username).
     */
    public static function listAllVisible(int $viewerCid): array
    {
        $db = ServiceDb::get('ftp');

        // Resolve the viewer's customer name -- pmwh3_ftp_accounts.master is
        // a customer name, not a cid.
        $viewer = CustomerUtil::getCustomerNameById($viewerCid);
        $isAdmin = CustomerUtil::hasAccess('view_customers');

        if ($isAdmin) {
            return $db->select(
                    "SELECT * FROM pmwh3_ftp_accounts ORDER BY domain, username"
            );
        }
        return $db->select(
                "SELECT * FROM pmwh3_ftp_accounts WHERE master = :m ORDER BY domain, username",
                ['m' => (string) $viewer]
        );
    }

    /** FTP accounts belonging to one domain. */
    public static function listForDomain(string $domain): array
    {
        return ServiceDb::get('ftp')->select(
                "SELECT * FROM pmwh3_ftp_accounts WHERE domain = :d ORDER BY username",
                ['d' => $domain]
        );
    }

    /** Count FTP accounts for a single domain. */
    public static function countForDomain(string $domain): int
    {
        $row = ServiceDb::get('ftp')->selectOne(
                "SELECT COUNT(*) AS c FROM pmwh3_ftp_accounts WHERE domain = :d",
                ['d' => $domain]
        );
        return (int) ($row['c'] ?? 0);
    }

    public static function getByUsername(string $username): ?array
    {
        $row = ServiceDb::get('ftp')->selectOne(
                "SELECT * FROM pmwh3_ftp_accounts WHERE username = :u LIMIT 1",
                ['u' => $username]
        );
        return $row ?: null;
    }

    /**
     * Quota row for one account. Returns null if no row exists
     * (proftpd defaults: no quota limit).
     */
    public static function getQuotaLimit(string $name, string $type = 'user'): ?array
    {
        $row = ServiceDb::get('ftp')->selectOne(
                "SELECT * FROM pmwh3_ftp_quota_limits
                  WHERE name = :n AND quota_type = :t LIMIT 1",
                ['n' => $name, 't' => $type]
        );
        return $row ?: null;
    }

    /** Used-bytes counter for one account. */
    public static function getQuotaTally(string $name, string $type = 'user'): ?array
    {
        $row = ServiceDb::get('ftp')->selectOne(
                "SELECT * FROM pmwh3_ftp_quota_tallies
                  WHERE name = :n AND quota_type = :t LIMIT 1",
                ['n' => $name, 't' => $type]
        );
        return $row ?: null;
    }

    // ===== Writes =======================================================

    /**
     * Create a new FTP account.
     *
     * Required keys:  username, password, homedir
     * Optional keys:  domain, master, uid, gid, quota_mb
     *
     * Returns true on success.
     */
    public static function create(array $row): bool
    {
        $username = trim((string) ($row['username'] ?? ''));
        $password =       (string) ($row['password'] ?? '');
        $homedir  = trim((string) ($row['homedir']  ?? ''));
        if ($username === '' || $password === '' || $homedir === '') {
            return false;
        }
        // Uniqueness
        if (self::getByUsername($username) !== null) {
            return false;
        }

        $uid = (int) ($row['uid']
                ?? LazyConfig::get('FTP_DEFAULT_UID', '5000'));
        $gid = (int) ($row['gid']
                ?? LazyConfig::get('FTP_DEFAULT_GID', '5000'));

        $db = ServiceDb::get('ftp');
        $db->insert('pmwh3_ftp_accounts', [
            'username' => $username,
            'password' => self::encodePassword($password),
            'uid'      => $uid,
            'gid'      => $gid,
            'homedir'  => $homedir,
            'domain'   => (string) ($row['domain'] ?? ''),
            'master'   => (string) ($row['master'] ?? ''),
        ]);

        if (isset($row['quota_mb']) && (int) $row['quota_mb'] > 0) {
            self::setQuotaLimitMb($username, (int) $row['quota_mb']);
        }
        return true;
    }

    /**
     * Update an existing account. $fields can contain any of:
     * password, homedir, uid, gid, domain, master, quota_mb.
     *
     * Empty password leaves the existing one alone.
     */
    public static function update(string $username, array $fields): bool
    {
        $existing = self::getByUsername($username);
        if (!$existing) {
            return false;
        }
        $db = ServiceDb::get('ftp');

        $update = [];
        foreach (['homedir', 'domain', 'master'] as $k) {
            if (array_key_exists($k, $fields)) {
                $update[$k] = (string) $fields[$k];
            }
        }
        foreach (['uid', 'gid'] as $k) {
            if (array_key_exists($k, $fields)) {
                $update[$k] = (int) $fields[$k];
            }
        }
        $newPass = (string) ($fields['password'] ?? '');
        if ($newPass !== '') {
            $update['password'] = self::encodePassword($newPass);
        }

        if (!empty($update)) {
            $db->update('pmwh3_ftp_accounts', $update,
                    'username = :u', ['u' => $username]);
        }

        if (array_key_exists('quota_mb', $fields)) {
            $mb = (int) $fields['quota_mb'];
            if ($mb > 0) {
                self::setQuotaLimitMb($username, $mb);
            } else {
                self::clearQuotaLimit($username);
            }
        }
        return true;
    }

    /**
     * Delete an account. Removes the pmwh3_ftp_accounts row plus any
     * quota rows that match the username. Group memberships
     * are left to the caller (deleting the whole group would
     * affect other members).
     */
    public static function delete(string $username): bool
    {
        $db = ServiceDb::get('ftp');
        $db->delete('pmwh3_ftp_accounts',
                'username = :u', ['u' => $username]);
        $db->delete('pmwh3_ftp_quota_limits',
                'name = :n AND quota_type = :t',
                ['n' => $username, 't' => 'user']);
        $db->delete('pmwh3_ftp_quota_tallies',
                'name = :n AND quota_type = :t',
                ['n' => $username, 't' => 'user']);
        return true;
    }

    /**
     * Convenience: just change the password.
     */
    public static function changePassword(string $username, string $newPassword): bool
    {
        if ($newPassword === '') {
            return false;
        }
        ServiceDb::get('ftp')->update('pmwh3_ftp_accounts',
                ['password' => self::encodePassword($newPassword)],
                'username = :u', ['u' => $username]);
        return true;
    }

    /**
     * Verify a plaintext password against the stored proftpd row.
     * Honors the same ENCRYPT_FTP_PASS option as create / changePassword,
     * so what we wrote in is what we compare against here.
     */
    public static function verifyPassword(string $username, string $plainPassword): bool
    {
        if ($username === '' || $plainPassword === '') {
            return false;
        }
        $row = self::getByUsername($username);
        if (!$row) {
            return false;
        }
        $stored = (string) ($row['password'] ?? '');
        if ($stored === '') {
            return false;
        }
        return hash_equals($stored, self::encodePassword($plainPassword));
    }

    // ===== Quota helpers ================================================

    public static function setQuotaLimitMb(string $name, int $mb, string $type = 'user'): void
    {
        $bytes = $mb * 1024 * 1024;
        $limitType = (string) LazyConfig::get('FTP_QUOTA', 'soft');
        $db = ServiceDb::get('ftp');

        // Replace-or-insert (PK on (name, quota_type))
        $existing = self::getQuotaLimit($name, $type);
        if ($existing) {
            $db->update('pmwh3_ftp_quota_limits', [
                'limit_type'     => $limitType,
                'bytes_in_avail' => $bytes,
            ], 'name = :n AND quota_type = :t',
                    ['n' => $name, 't' => $type]);
        } else {
            $db->insert('pmwh3_ftp_quota_limits', [
                'name'             => $name,
                'quota_type'       => $type,
                'per_session'      => 'true',
                'limit_type'       => $limitType,
                'bytes_in_avail'   => $bytes,
                'bytes_out_avail'  => 0,
                'bytes_xfer_avail' => 0,
                'files_in_avail'   => 0,
                'files_out_avail'  => 0,
                'files_xfer_avail' => 0,
            ]);
        }
        // Make sure a tally row exists so proftpd's quota engine
        // has something to update on uploads.
        if (!self::getQuotaTally($name, $type)) {
            $db->insert('pmwh3_ftp_quota_tallies', [
                'name'       => $name,
                'quota_type' => $type,
            ]);
        }
    }

    public static function clearQuotaLimit(string $name, string $type = 'user'): void
    {
        ServiceDb::get('ftp')->delete('pmwh3_ftp_quota_limits',
                'name = :n AND quota_type = :t',
                ['n' => $name, 't' => $type]);
    }

    // ===== Internal =====================================================

    /**
     * Apply the configured password encoding.
     *
     * ENCRYPT_FTP_PASS = N -> plain text (default)
     * ENCRYPT_FTP_PASS = Y -> SHA-256 hex (matches proftpd
     *                          SQLPasswordEngine on / Encoding hex)
     */
    private static function encodePassword(string $password): string
    {
        $enc = strtoupper((string) LazyConfig::get('ENCRYPT_FTP_PASS', 'N'));
        if ($enc === 'Y') {
            return hash('sha256', $password);
        }
        return $password;
    }
}
