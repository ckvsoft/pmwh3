<?php

namespace pmwh3\Utils;

use ckvsoft\mvc\Config;
use ckvsoft\Session;

/**
 * Records the logged-in customer's most recent request into
 * pmwh3_activity. The General/Sessions view reads from this table
 * to show "who's online doing what".
 *
 * Called from AuthMiddleware::enforceLogin() right after the login
 * check passes -- so every page hit by a logged-in user produces
 * exactly one row update (UPSERT on session_id, see below).
 *
 * Identity model:
 *   - One row per (cid, session_id). PHP session id stays stable for
 *     the lifetime of the browser session, so the same row gets
 *     touched (UPDATE) on every page hit during one session and the
 *     updated_at column reflects the last seen time. A different
 *     browser / device produces a different session id and therefore
 *     a separate row, which is the BBS-style "two terminals, one
 *     user" behaviour we want.
 *
 * Failure mode:
 *   - All write attempts are wrapped in try/catch and silently
 *     swallow errors. The activity table being missing or temporarily
 *     unreachable must not break a request -- this is an observability
 *     feature, not a correctness one.
 */
class ActivityTracker
{

    /** Cached "did we already record this request?" so multiple
     *  controllers in the same request only write once. */
    private static bool $recordedThisRequest = false;

    public static function record(): void
    {
        if (self::$recordedThisRequest) {
            return;
        }
        self::$recordedThisRequest = true;

        try {
            $cid = (int) (Session::getNs('pmwh3', 'customer_id') ?? 0);
            if ($cid <= 0) {
                return;  // not logged in -- nothing to record
            }

            // Resolve the customer name once. CustomerUtil caches.
            $customer = (string) (CustomerUtil::getCustomerNameById($cid) ?? '');

            $sessionId  = (string) session_id();
            $ip         = self::clientIp();
            $userAgent  = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
            $url        = substr((string) ($_SERVER['REQUEST_URI']     ?? ''), 0, 512);
            [$module, $action] = self::parseRoute($url);

            $db = Config::moduleDb();

            // UPSERT on (cid, session_id). The composite is not the
            // PK (id is) so we have to detect-and-update manually.
            $existing = $db->selectOne(
                    "SELECT id, last_url, session_start FROM pmwh3_activity
                      WHERE cid = :c AND session_id = :s
                      LIMIT 1",
                    ['c' => $cid, 's' => $sessionId]
            );

            if ($existing) {
                // Carry the previous last_url forward as prev_url so
                // the UI can show "user is on X, came from Y". We only
                // overwrite prev_url when the URL actually changes --
                // otherwise refreshes / same-page hits would lose the
                // referrer-within-app information.
                $oldUrl = (string) ($existing['last_url'] ?? '');
                $updateFields = [
                    'customer'    => $customer,
                    'ip'          => $ip,
                    'user_agent'  => $userAgent,
                    'last_url'    => $url,
                    'last_module' => $module,
                    'last_action' => $action,
                    // updated_at auto-updates via ON UPDATE CURRENT_TIMESTAMP
                ];
                if ($oldUrl !== '' && $oldUrl !== $url) {
                    $updateFields['prev_url'] = $oldUrl;
                }
                // Backfill session_start once on rows that pre-date
                // the column (or were created by older code). Use
                // updated_at as a reasonable approximation of when
                // the session was last seen -- not perfect, but
                // better than leaving the UI's "Logged in since"
                // empty forever.
                if (empty($existing['session_start'])) {
                    $updateFields['session_start'] = date('Y-m-d H:i:s');
                }
                $db->update('pmwh3_activity', $updateFields,
                        'id = :i', ['i' => (int) $existing['id']]);
            } else {
                // First hit of this session -- stamp session_start once.
                // Subsequent updates leave session_start untouched.
                $db->insert('pmwh3_activity', [
                    'cid'           => $cid,
                    'customer'      => $customer,
                    'session_id'    => $sessionId,
                    'ip'            => $ip,
                    'user_agent'    => $userAgent,
                    'last_url'      => $url,
                    'prev_url'      => '',
                    'last_module'   => $module,
                    'last_action'   => $action,
                    'session_start' => date('Y-m-d H:i:s'),
                ]);
            }

            // Opportunistic cleanup: every ~50 hits, prune rows older
            // than 7 days. Cheap, keeps the table tidy.
            if (mt_rand(0, 49) === 0) {
                $db->delete(
                        'pmwh3_activity',
                        'updated_at < NOW() - INTERVAL 7 DAY',
                        []
                );
            }
        } catch (\Throwable $e) {
            // Activity logging must never break a request.
            error_log('ActivityTracker: ' . $e->getMessage());
        }
    }

    /**
     * Remove one session row from pmwh3_activity ("kick").
     *
     * Authorization: the row's owner cid must match the caller, or
     * the caller must be the ultimate admin (cid=1). The General/
     * Sessions controller already enforces login; this method just
     * enforces "can't kick someone else's session unless admin".
     *
     * @return bool true on delete, false if the row doesn't exist
     *              or the caller isn't allowed to touch it.
     */
    public static function removeSession(int $id, int $callerCid): bool
    {
        if ($id <= 0 || $callerCid <= 0) {
            return false;
        }
        $db = Config::moduleDb();
        $row = $db->selectOne(
                "SELECT cid FROM pmwh3_activity WHERE id = :i",
                ['i' => $id]
        );
        if (!$row) {
            return false;
        }
        // Ultimate admin (cid=1) can kick anyone; otherwise only
        // the row's own cid.
        if ($callerCid !== 1 && (int) $row['cid'] !== $callerCid) {
            return false;
        }
        $db->delete('pmwh3_activity', 'id = :i', ['i' => $id]);
        return true;
    }

    /**
     * Best-effort client IP. Trusts X-Forwarded-For only if present;
     * falls back to REMOTE_ADDR. We don't validate the chain because
     * we're behind a known reverse proxy in this deployment.
     */
    private static function clientIp(): string
    {
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']);
            return substr(trim($parts[0]), 0, 45);
        }
        return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    }

    /**
     * Pulls module + action segments from the URL.
     * "/cevian/pmwh3/customer/edit/42" -> ["customer", "edit"]
     * The module name itself ("pmwh3") is implicit -- we're inside
     * pmwh3 by construction.
     */
    private static function parseRoute(string $url): array
    {
        $url = strtok($url, '?');  // drop query string
        $parts = array_values(array_filter(explode('/', (string) $url), 'strlen'));

        // Find the "pmwh3" segment and take what follows.
        $i = array_search('pmwh3', $parts, true);
        if ($i === false) {
            return ['', ''];
        }
        $module = (string) ($parts[$i + 1] ?? '');
        $action = (string) ($parts[$i + 2] ?? '');
        return [substr($module, 0, 64), substr($action, 0, 64)];
    }
}
