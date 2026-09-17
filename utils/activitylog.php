<?php

namespace pmwh3\Utils;

use ckvsoft\mvc\Config;
use ckvsoft\Session;

/**
 * Append-only audit trail for write operations.
 *
 * Unlike ActivityTracker (which UPDATEs one row per session to show
 * "who is currently doing what"), this class INSERTs one row per
 * successful create / update / delete so we keep a history of
 * changes per target.
 *
 * Read by the domain/details "activity" tab in the "Change history"
 * section. Target is conventionally the domain name for domain ops,
 * the customer name for customer ops, etc. -- whatever you want to
 * filter by in the UI.
 *
 * Failure mode:
 *   - Same as ActivityTracker: try/catch and swallow. The audit log
 *     must never break a request that has already succeeded at the
 *     real DB level.
 *
 * Pruning:
 *   - Lazy: every ~100th insert deletes rows older than 90 days.
 *     No cron required.
 */
class ActivityLog
{

    /** Retention for the lazy cleanup. Days. */
    private const RETENTION_DAYS = 90;

    /**
     * Record one audit entry.
     *
     * @param string $module  Logical module ('domain', 'customer', ...).
     * @param string $action  Verb ('create', 'update', 'delete',
     *                        'dns_add', 'dnssec_secure', ...).
     * @param string $target  What was acted on (domain name, customer,
     *                        record id). Used as the filter key in
     *                        the UI.
     * @param string $detail  Free-form human-readable detail
     *                        ("A www 1.2.3.4", "set DNSSEC on", ...).
     */
    public static function write(
            string $module,
            string $action,
            string $target = '',
            string $detail = ''
    ): void {
        try {
            $cid = (int) (Session::getNs('pmwh3', 'customer_id') ?? 0);
            if ($cid <= 0) {
                return;  // anonymous writes shouldn't happen, but be safe
            }

            $customer = (string) (CustomerUtil::getCustomerNameById($cid) ?? '');
            $ip       = self::clientIp();

            $db = Config::moduleDb();
            $db->insert('pmwh3_activity_log', [
                'cid'      => $cid,
                'customer' => $customer,
                'ip'       => $ip,
                'module'   => substr($module, 0, 64),
                'action'   => substr($action, 0, 64),
                'target'   => substr($target, 0, 255),
                'detail'   => substr($detail, 0, 255),
            ]);

            // Mirror into the pmwh3 errorlog (target file / db / syslog
            // per Options > Errorlog). Shows up as INFO in the Tools >
            // Errorlog viewer once its level allows info entries.
            ErrorHandler::log('info',
                    "[{$module}] {$action}: {$target}"
                    . ($detail !== '' ? " -- {$detail}" : '')
                    . " (user: {$customer}, ip: {$ip})",
                    'app');

            // Lazy cleanup: every ~100th insert prunes old rows.
            if (mt_rand(0, 99) === 0) {
                $db->delete(
                        'pmwh3_activity_log',
                        'created_at < NOW() - INTERVAL ' . self::RETENTION_DAYS . ' DAY',
                        []
                );
            }
        } catch (\Throwable $e) {
            // Audit logging must never break the actual operation.
            error_log('ActivityLog: ' . $e->getMessage());
        }
    }

    /**
     * Best-effort client IP. Mirrors ActivityTracker::clientIp() so
     * both tables agree on what "the user's IP" means.
     */
    private static function clientIp(): string
    {
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']);
            return substr(trim($parts[0]), 0, 45);
        }
        return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    }
}
