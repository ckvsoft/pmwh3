<?php

class Login extends \ckvsoft\mvc\BaseController
{

    public function __construct()
    {
        parent::__construct();
        // Redirect wenn Framework oder pmwh3 Login aktiv
        \pmwh3\Utils\AuthMiddleware::redirectIfLoggedIn();
    }

    public function index()
    {
        // Default: send the user to the module root after login.
        $redirect = 'pmwh3';
        $returnTo = (string) (\ckvsoft\Session::getNs('pmwh3', 'return_to') ?? '');
        \pmwh3\Utils\ErrorHandler::trace("[login.index] session.return_to = '{$returnTo}'");
        if ($returnTo !== '') {
            $base = (string) BASE_URI;
            $rel  = ($base !== '' && str_starts_with($returnTo, $base))
                    ? substr($returnTo, strlen($base))
                    : ltrim($returnTo, '/');
            if (str_starts_with($rel, 'pmwh3') && !str_contains($rel, 'pmwh3/login')) {
                $redirect = $rel;
            }
        }
        \pmwh3\Utils\ErrorHandler::trace("[login.index] data-redirect set to '{$redirect}'");

        $captcha = \pmwh3\Utils\CaptchaManager::renderContext();

        // G4: news ticker on the login page (SHOW_NEWS on, newest
        // first, capped by MAX_NEWS). Best effort.
        $news = [];
        try {
            if (strtoupper((string) \pmwh3\Config\LazyConfig::get('SHOW_NEWS', 'Y')) === 'Y') {
                $model  = $this->loadModel('tools', 'pmwh3');
                $news   = $model->listActiveNews(
                        (int) \pmwh3\Config\LazyConfig::get('MAX_NEWS', '10'));
            }
        } catch (\Throwable $e) {
            \pmwh3\Utils\ErrorHandler::trace("[login.index] news fetch skipped: " . $e->getMessage());
            $news = [];
        }

        $this->renderPage([
            ['view' => '/inc/header', 'data' => ['title' => 'Login']],
            ['view' => 'pmwh3/login', 'data' => ['redirectTarget' => $redirect, 'captcha' => $captcha, 'news' => $news]],
            ['view' => '/inc/footer'],
                ],
                "<style>" . $this->loadHelper("css", ['method' => 'getCss', 'args' => ['inc/css/pmwh3.css']]) . "</style>",
                "<script>" . $this->loadScript("inc/js/pmwh3.js",
                [
                    'pmwh3ConfirmChanges' => \pmwh3\Config\LazyConfig::get('CONFIRM_CHANGES', 'Y') === 'Y',
                    'pmwh3ConfirmDelete'  => \pmwh3\Config\LazyConfig::get('CONFIRM_DELETE', 'Y') === 'Y',
                ]) . "</script>"
        );
    }

    public function submit()
    {
        $input = new \ckvsoft\Input();
        try {
            $input->post('customername', true)
                    ->post('password', true)
                    ->format('hash', ['sha256', HASH_KEY]);
            $input->submit();

            if ($input->fetchErrors()) {
                \ckvsoft\Output::error($input->fetchErrors());
                return;
            }

            $data = $input->fetch();

            $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
            if (!\pmwh3\Utils\CaptchaManager::verify(
                    (string) ($_POST['g-recaptcha-response'] ?? ''), $ip)) {
                \pmwh3\Utils\ErrorHandler::log('warning',
                        sprintf('captcha failed for "%s" (%s)',
                                (string) ($data['customername'] ?? ''),
                                $ip),
                        'app');
                \ckvsoft\Auth::sendFlashRedirect(
                        BASE_URI . 'pmwh3/login',
                        'error',
                        __('Login'),
                        __('Captcha verification failed.')
                );
                return;
            }

            $model = $this->loadModel('login');
            $result = $model->customerLogin($data);

            if (!$result) {
                \pmwh3\Utils\ErrorHandler::log('warning',
                        sprintf('login failed for "%s" (%s)',
                                (string) ($data['customername'] ?? ''),
                                $ip),
                        'app');
                \ckvsoft\Auth::sendFlashRedirect(
                        BASE_URI . 'pmwh3/login',
                        'error',
                        __('Login'),
                        __('Wrong user or password.')
                );
                return;
            }

            $roleId = $result['role_id'];

            $dataForSession = [
                'role_id' => $roleId,
                'role_id_key' => \ckvsoft\Hash::create('sha256', $roleId, HASH_KEY),
                'language' => $result['language'] ?? 'de'
            ];

            // MultiLoginManager Login
            \ckvsoft\MultiLoginManager::login('pmwh3', $result['cid'], $dataForSession);

            // Optional: alte pmwh3 Session für Legacy-Module
            ckvsoft\Session::setNs('pmwh3', [
                'customer_id' => $result['cid'],
                'customer_key' => ckvsoft\Hash::create('sha256', $result['cid'], HASH_KEY),
                'role_id' => $result['role_id'],
                'role_id_key' => ckvsoft\Hash::create('sha256', $result['role_id'], HASH_KEY),
                'customer_language' => $result['language'],
            ]);
            // Seed the header top-info session values (customer_name /
            // customer_group are written via useSession caching).
            \pmwh3\Utils\CustomerUtil::getCustomerNameById($result['cid'], true);
            \pmwh3\Utils\CustomerUtil::getCustomerGroupNameById($result['role_id'], true);
            \pmwh3\Utils\ErrorHandler::log('info',
                    sprintf('login ok "%s" (cid %d, role %d, ip %s)',
                            ltrim(mb_strimwidth((string) \pmwh3\Utils\CustomerUtil::getCustomerNameById($result['cid']), 0, 64)),
                            $result['cid'],
                            $result['role_id'],
                            $ip),
                    'app');

            // The login form is a plain POST (not AJAX), so the
            // redirect target has to be decided server-side. The
            // form ships a hidden return_to field populated from
            // localStorage by the login view. Default fallback is
            // the module root if return_to is missing or invalid.
            $returnTo = trim((string) ($_POST['return_to'] ?? ''));
            $target   = BASE_URI . 'pmwh3/general/overview';
            if ($returnTo !== '') {
                // Defense in depth: only same-app URLs, never /login.
                $base = (string) BASE_URI;
                $isSameApp = str_starts_with($returnTo, $base . 'pmwh3')
                        || str_starts_with($returnTo, '/pmwh3');
                $isLogin   = str_contains($returnTo, '/pmwh3/login');
                if ($isSameApp && !$isLogin) {
                    // Normalize to an absolute path under BASE_URI.
                    $target = str_starts_with($returnTo, $base)
                            ? $returnTo
                            : (rtrim($base, '/') . '/' . ltrim($returnTo, '/'));
                }
            }

            \ckvsoft\Output::success();
            \ckvsoft\Auth::sendFlashRedirect(
                    $target,
                    'success',
                    __('Login'),
                    __('Welcome Back!')
            );
        } catch (\ckvsoft\CkvException $e) {
            \ckvsoft\Auth::sendFlashRedirect(
                    BASE_URI . 'pmwh3/login',
                    'error',
                    __('Login'),
                    $input->fetchErrors()
            );
            throw $e;
        }
    }

    public function password_reset()
    {
        $input = new \ckvsoft\Input();
        try {
            $input->post('email', true);
            $input->submit();
            if ($input->fetchErrors()) {
                \ckvsoft\Output::error($input->fetchErrors());
                return;
            }

            if (!\pmwh3\Utils\CaptchaManager::verify(
                    (string) ($_POST['g-recaptcha-response'] ?? ''),
                    (string) ($_SERVER['REMOTE_ADDR'] ?? ''))) {
                \ckvsoft\Auth::sendFlashRedirect(
                        BASE_URI . 'pmwh3/login',
                        'error',
                        __('Reset Your Password'),
                        __('Captcha verification failed.')
                );
                return;
            }

            $data = $input->fetch();
            $email = $data['email'];
            $model = $this->loadModel('login');
            $result = $model->sendResetLink($email);

            \ckvsoft\Auth::sendFlashRedirect(
                    BASE_URI . 'pmwh3/login',
                    'success',
                    __('Reset Your Password'),
                    __('Email successfully sent to the specified address.')
            );
        } catch (\ckvsoft\CkvException $e) {
            \ckvsoft\Output::error($input->fetchErrors());
            throw $e;
        }
    }

    /**
     * Token landing page (linked from the reset email). Renders a
     * "set a new password" form if the token is still valid; flashes
     * an error otherwise. The token is carried through the form as
     * a hidden field so finalize_reset() can pick it up.
     *
     * URL: pmwh3/login/activate/<token>
     */
    public function activate($token = '')
    {
        $token = trim((string) $token);
        if ($token === '') {
            \ckvsoft\Auth::sendFlashRedirect(
                    BASE_URI . 'pmwh3/login',
                    'error',
                    __('Reset Your Password'),
                    __('Invalid activation link.')
            );
            return;
        }
        $model = $this->loadModel('login');
        if ($model->validateToken($token) === null) {
            \ckvsoft\Auth::sendFlashRedirect(
                    BASE_URI . 'pmwh3/login',
                    'error',
                    __('Reset Your Password'),
                    __('Activation link is invalid or has expired.')
            );
            return;
        }

        $this->renderPage([
            ['view' => '/inc/header', 'data' => ['title' => __('Set new password')]],
            ['view' => 'pmwh3/login_activate', 'data' => ['token' => $token]],
            ['view' => '/inc/footer'],
                ],
                "<style>" . $this->loadHelper("css", ['method' => 'getCss', 'args' => ['inc/css/pmwh3.css']]) . "</style>",
                "<script>" . $this->loadScript("inc/js/pmwh3.js",
                [
                    'pmwh3ConfirmChanges' => \pmwh3\Config\LazyConfig::get('CONFIRM_CHANGES', 'Y') === 'Y',
                    'pmwh3ConfirmDelete'  => \pmwh3\Config\LazyConfig::get('CONFIRM_DELETE', 'Y') === 'Y',
                ]) . "</script>"
        );
    }

    /**
     * POST target for the activation form: take the token + the new
     * password, write the password, kill the token. URL:
     * pmwh3/login/finalize_reset
     */
    public function finalize_reset()
    {
        $input = new \ckvsoft\Input();
        try {
            $input->post('token', true)
                    ->post('new_password', true)
                    ->post('new_password2', true);
            $input->submit();
            if ($input->fetchErrors()) {
                \ckvsoft\Auth::sendFlashRedirect(
                        BASE_URI . 'pmwh3/login',
                        'error',
                        __('Reset Your Password'),
                        __('Missing fields.')
                );
                return;
            }
            $data = $input->fetch();
            $token = (string) $data['token'];
            $pw1   = (string) $data['new_password'];
            $pw2   = (string) $data['new_password2'];

            if ($pw1 !== $pw2) {
                \ckvsoft\Auth::sendFlashRedirect(
                        BASE_URI . 'pmwh3/login/activate/' . urlencode($token),
                        'error',
                        __('Reset Your Password'),
                        __('Passwords do not match.')
                );
                return;
            }
            if (!\pmwh3\Utils\PasswordUtil::isValidLength($pw1)) {
                $min = \pmwh3\Utils\PasswordUtil::minLength();
                \ckvsoft\Auth::sendFlashRedirect(
                        BASE_URI . 'pmwh3/login/activate/' . urlencode($token),
                        'error',
                        __('Reset Your Password'),
                        sprintf(__('Password must be at least %d characters.'), $min)
                );
                return;
            }

            $model = $this->loadModel('login');
            $ok = $model->finalizeReset($token, $pw1);

            \ckvsoft\Auth::sendFlashRedirect(
                    BASE_URI . 'pmwh3/login',
                    $ok ? 'success' : 'error',
                    __('Reset Your Password'),
                    $ok ? __('Password changed. You can now log in.')
                        : __('Activation link is invalid or has expired.')
            );
        } catch (\ckvsoft\CkvException $e) {
            \ckvsoft\Auth::sendFlashRedirect(
                    BASE_URI . 'pmwh3/login',
                    'error',
                    __('Reset Your Password'),
                    $input->fetchErrors()
            );
            throw $e;
        }
    }

    /**
     * Change a mail (Postfix) or FTP (Proftpd) password from the
     * login page. Caller proves they own the account by supplying
     * the current password; the matching Manager's verifyPassword
     * decides whether the old one matches the stored hash chain
     * for that backend.
     *
     * URL: pmwh3/login/change_password
     */
    public function change_password()
    {
        $input = new \ckvsoft\Input();
        try {
            $input->post('type', true)
                    ->post('username', true)
                    ->post('old_password', true)
                    ->post('new_password', true)
                    ->post('confirm_password', true);
            $input->submit();
            if ($input->fetchErrors()) {
                \ckvsoft\Auth::sendFlashRedirect(
                        BASE_URI . 'pmwh3/login',
                        'error',
                        __('Change Password'),
                        __('Missing fields.')
                );
                return;
            }
            $data = $input->fetch();
            $type = strtolower((string) $data['type']);
            $username = trim((string) $data['username']);
            $oldPw    = (string) $data['old_password'];
            $newPw    = (string) $data['new_password'];
            $confirm  = (string) $data['confirm_password'];

            if ($newPw !== $confirm) {
                \ckvsoft\Auth::sendFlashRedirect(
                        BASE_URI . 'pmwh3/login',
                        'error',
                        __('Change Password'),
                        __('Passwords do not match.')
                );
                return;
            }
            if (!\pmwh3\Utils\PasswordUtil::isValidLength($newPw)) {
                $min = \pmwh3\Utils\PasswordUtil::minLength();
                \ckvsoft\Auth::sendFlashRedirect(
                        BASE_URI . 'pmwh3/login',
                        'error',
                        __('Change Password'),
                        sprintf(__('Password must be at least %d characters.'), $min)
                );
                return;
            }
            if (!in_array($type, ['email', 'ftp'], true)) {
                \ckvsoft\Auth::sendFlashRedirect(
                        BASE_URI . 'pmwh3/login',
                        'error',
                        __('Change Password'),
                        __('Invalid account type.')
                );
                return;
            }

            if ($type === 'email') {
                $ok = \pmwh3\Utils\MailManager::verifyPassword($username, $oldPw)
                        && \pmwh3\Utils\MailManager::changePassword($username, $newPw);
            } else {
                $ok = \pmwh3\Utils\FtpManager::verifyPassword($username, $oldPw)
                        && \pmwh3\Utils\FtpManager::changePassword($username, $newPw);
            }

            \ckvsoft\Auth::sendFlashRedirect(
                    BASE_URI . 'pmwh3/login',
                    $ok ? 'success' : 'error',
                    __('Change Password'),
                    $ok ? __('Password changed.')
                        : __('Wrong account or password.')
            );
        } catch (\ckvsoft\CkvException $e) {
            \ckvsoft\Auth::sendFlashRedirect(
                    BASE_URI . 'pmwh3/login',
                    'error',
                    __('Change Password'),
                    $input->fetchErrors()
            );
            throw $e;
        }
    }
}
