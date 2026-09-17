<?php

namespace pmwh3\Utils;

use ckvsoft\Session;
use ckvsoft\Hash;

/**
 * Handles authentication and session mapping.
 */
class AuthMiddleware extends \ckvsoft\MultiLoginManager
{

    /**
     * Checks if a login exists or if a mapping from the framework user can be used.
     * If mapping exists: Session namespace is automatically set.
     * If not: Module-specific login is required.
     */
    public static function checkLogin(): bool
    {
        $session = Session::getNs('pmwh3');

        if (!empty($session['customer_id']) && !empty($session['customer_key'])) {
            return true;
        }

        if (!self::isFrameworkLoggedIn()) {
            return false;
        }

        $mappedUser = self::getMappedUserForModule('pmwh3');

        if (empty($mappedUser)) {
            return false;
        }

        self::moduleDb();
        $userId = $mappedUser['user_id'];

        $customer = self::$moduleSharedDb->selectOne(
                "SELECT cid, role_id, language FROM pmwh3_customers WHERE cid = :cid",
                ['cid' => $userId]
        );

        if ($customer) {
            $roleId = $customer['role_id'];

            $dataForSession = [
                'role_id' => $roleId,
                'role_id_key' => Hash::create('sha256', $roleId, HASH_KEY),
                'language' => $customer['language'] ?? 'de'
            ];

            \ckvsoft\MultiLoginManager::login('pmwh3', $customer['cid'], $dataForSession);

            Session::setNs('pmwh3', [
                'customer_id' => $customer['cid'],
                'customer_key' => Hash::create('sha256', $customer['cid'], HASH_KEY),
                'role_id' => $customer['role_id'],
                'role_id_key' => Hash::create('sha256', $customer['role_id'], HASH_KEY),
                'customer_language' => $customer['language'] ?? 'de',
            ]);

            \pmwh3\Utils\CustomerUtil::getCustomerNameById($customer['cid'], true);
            \pmwh3\Utils\CustomerUtil::getCustomerGroupNameById($customer['role_id'], true);

            return true;
        }

        return false;
    }

    /**
     * Enforces login by redirecting to login page if not logged in.
     *
     * Before bouncing to /login we stash the URL the user was
     * actually trying to reach in the session under
     *    pmwh3.return_to
     * so Login::submit() can send them back there after they
     * authenticate. Only GETs are remembered -- it makes no sense
     * to replay a POST against a write endpoint.
     */
    public static function enforceLogin(string $redirect = BASE_URI . 'pmwh3/login')
    {
        // Installer gate: a copied module with placeholder config has
        // no schema/RBAC yet -- every pmwh3 page must land on the
        // install wizard instead of an arbitrary database failure.
        // The installer itself never runs enforceLogin (no loop).
        try {
            if (\pmwh3\Utils\InstallBootstrap::needsInstall()) {
                header('Location: ' . BASE_URI . 'pmwh3/install');
                exit;
            }
        } catch (\Throwable $e) {
            \pmwh3\Utils\ErrorHandler::trace("[enforceLogin] install-gate probe failed: " . $e->getMessage());
        }

        if (!self::checkLogin()) {
            self::rememberReturnTo();
            \pmwh3\Utils\ErrorHandler::trace("[enforceLogin] not logged in, return_to set to '"
                    . (\ckvsoft\Session::getNs('pmwh3', 'return_to') ?? '(empty)')
                    . "', redirecting to {$redirect}");
            header('Location: ' . $redirect);
            exit;
        }
        // Logged-in pmwh3 user -- record this hit for the
        // General/Sessions view. Cheap (one UPDATE), guarded
        // against errors internally.
        ActivityTracker::record();

        // Request trace for the Errorlog viewer (level debug) --
        // gives a "who clicked what" timeline there.
        ErrorHandler::trace('[pmwh3] ' . strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'))
                . ' ' . ($_SERVER['REQUEST_URI'] ?? '')
                . ' (user: '
                . ltrim(mb_strimwidth((string) \pmwh3\Utils\CustomerUtil::getCustomerNameById(
                          (int) (Session::getNs('pmwh3', 'customer_id') ?? 0)), 0, 64, ''))
                . ', ip: ' . (string) ($_SERVER['REMOTE_ADDR'] ?? '') . ')');
    }

    /**
     * Stash the current request URL into session.pmwh3.return_to,
     * if it looks like a sensible target to send the user back to.
     * Called from enforceLogin() right before the bounce to /login.
     */
    private static function rememberReturnTo(): void
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'GET') {
            return;  // never replay POSTs
        }

        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        if ($uri === '') {
            return;
        }

        // Don't remember login/logout itself or AJAX hits.
        if (str_contains($uri, '/pmwh3/login')
                || str_contains($uri, '/pmwh3/logout')) {
            return;
        }
        // Don't follow Ajax / fetch requests -- those would just
        // get the user back to a page that triggers ajax again.
        $xhr = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        if ($xhr === 'xmlhttprequest') {
            return;
        }

        Session::setNs('pmwh3', ['return_to' => $uri]);
    }

    /**
     * Redirects to a given page if already logged in.
     */
    public static function redirectIfLoggedIn(string $redirect = BASE_URI . 'pmwh3')
    {
        if (self::checkLogin()) {
            header('Location: ' . $redirect);
            exit;
        }
    }
}
