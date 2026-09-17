<?php

// modules/pmwh3/controller/ftp.php

use ckvsoft\mvc\BaseController;
use ckvsoft\Session;
use pmwh3\Utils\ActivityLog;
use pmwh3\Utils\CustomerUtil;
use pmwh3\Utils\FtpManager;

class Ftp extends BaseController
{

    public function __construct()
    {
        parent::__construct();
        \pmwh3\Utils\AuthMiddleware::enforceLogin();
    }

    private function render($view, $data = null)
    {
        $pmwh3menuHelper = $this->loadHelper("pmwh3/pmwh3menu");
        $pmwh3menu = $pmwh3menuHelper->getMenu($data['activeBox'] ?? null);

        $this->renderPage([
            ['view' => '/inc/header', 'data' => ['title' => __('FTP management')]],
            ['view' => 'pmwh3/inc/navigation', 'data' => ['menu' => $pmwh3menu]],
            ['view' => $view, 'data' => ['data' => $data]],
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

    private function flash(string $kind, string $title, string $msg, string $where = 'overview'): void
    {
        // Empty $where used to route to pmwh3/ftp/ which the
        // dispatcher resolves to ftp/overview -- triggering an
        // infinite redirect loop if the flash was a
        // "permission denied" on overview itself. Redirect out
        // of the /ftp/ namespace in that case.
        $url = $where === ''
                ? BASE_URI . 'pmwh3/general/overview'
                : BASE_URI . 'pmwh3/ftp/' . $where;
        \ckvsoft\Auth::sendFlashRedirect($url, $kind, $title, $msg);
    }

    public function index()
    {
        $this->overview();
    }

    /**
     * Pick a domain and jump to the per-domain account list. URL:
     *   pmwh3/ftp/pick/<domain>
     * Mirrors the email picker. Validates that the domain belongs
     * to the current customer's tree before setting it as the
     * session's current domain.
     */
    public function pick($domain = '')
    {
        if (!CustomerUtil::hasAccess('view_ftp')) {
            $this->flash('error', __('FTP'), __('Permission denied'), '');
            return;
        }
        if ($domain !== '') {
            $cid = (int) Session::getNs('pmwh3', 'customer_id');
            $domains = CustomerUtil::getDomainsByCustomerId($cid);
            if (in_array($domain, array_column($domains, 'domain'), true)
                    || in_array($domain, array_column($domains, 'idn'), true)) {
                CustomerUtil::setCurrentDomain($domain);
            }
        }
        $this->location(BASE_URI . 'pmwh3/ftp/accounts');
    }

    // ===== Overview (domain -> account count) ===========================

    /**
     * pmwh2-style overview: one row per domain the viewer can see,
     * with the number of FTP accounts on that domain. Click a row
     * to drill into the per-domain account list.
     */
    public function overview()
    {
        if (!CustomerUtil::hasAccess('view_ftp')) {
            $this->flash('error', __('FTP'), __('Permission denied'), '');
            return;
        }
        $cid = (int) Session::getNs('pmwh3', 'customer_id');
        $domains = CustomerUtil::getDomainsByCustomerId($cid);

        $rows = [];
        foreach ($domains as $d) {
            $name = (string) ($d['domain'] ?? $d['idn'] ?? '');
            if ($name === '') continue;
            $rows[] = [
                'domain'   => $name,
                'idn'      => (string) ($d['idn'] ?? $name),
                'accounts' => FtpManager::countForDomain($name),
            ];
        }

        $this->render('pmwh3/ftp/overview', [
            'activeBox'   => 'ftp/overview',
            'domains'     => $rows,
            'create_perm' => CustomerUtil::hasAccess('create_ftp'),
        ]);
    }

    // ===== Per-domain accounts list ====================================

    /**
     * List FTP accounts for the customer's current domain. If no
     * domain is picked yet, show a hint pointing back to the
     * overview.
     */
    public function accounts()
    {
        if (!CustomerUtil::hasAccess('view_ftp')) {
            $this->flash('error', __('FTP'), __('Permission denied'), '');
            return;
        }
        $domain = (string) (CustomerUtil::currentDomain() ?? '');
        $accounts = $domain !== '' ? FtpManager::listForDomain($domain) : [];

        $this->render('pmwh3/ftp/accounts', [
            'activeBox'   => 'ftp/accounts',
            'domain'      => $domain,
            'accounts'    => $accounts,
            'create_perm' => CustomerUtil::hasAccess('create_ftp'),
            'edit_perm'   => CustomerUtil::hasAccess('edit_ftp'),
            'delete_perm' => CustomerUtil::hasAccess('delete_ftp'),
        ]);
    }

    // ===== Create / Edit form ==========================================

    public function new_account()
    {
        if (!CustomerUtil::hasAccess('create_ftp')) {
            $this->flash('error', __('FTP'), __('Permission denied'));
            return;
        }
        $this->renderEditForm(null);
    }

    public function edit_account($username = '')
    {
        if (!CustomerUtil::hasAccess('edit_ftp')) {
            $this->flash('error', __('FTP'), __('Permission denied'));
            return;
        }
        if ($username === '') {
            $this->flash('error', __('FTP'), __('No account specified'));
            return;
        }
        $row = FtpManager::getByUsername($username);
        if (!$row) {
            $this->flash('error', __('FTP'), __('Account not found'));
            return;
        }
        $this->renderEditForm($row);
    }

    private function renderEditForm(?array $row): void
    {
        $cid = (int) Session::getNs('pmwh3', 'customer_id');

        // Domain dropdown source (so the user picks a known domain
        // rather than typing it freehand and miss-spelling it).
        $domainModel = $this->loadModel('domain', 'pmwh3');
        $domains = $domainModel->getDomains($cid);

        $quota = null;
        if ($row) {
            $quotaRow = FtpManager::getQuotaLimit((string) $row['username']);
            if ($quotaRow) {
                $quota = (int) (($quotaRow['bytes_in_avail'] ?? 0) / 1024 / 1024);
            }
        }

        $this->render('pmwh3/ftp/edit', [
            'activeBox' => 'ftp/overview',
            'row'       => $row,
            'domains'   => $domains,
            'quota_mb'  => $quota,
            'is_new'    => $row === null,
        ]);
    }

    // ===== Insert =======================================================

    public function insert_account()
    {
        if (!CustomerUtil::hasAccess('create_ftp')) {
            $this->flash('error', __('FTP'), __('Permission denied'));
            return;
        }

        $payload = filter_input_array(INPUT_POST, [
            'config' => [
                'filter' => FILTER_DEFAULT,
                'flags'  => FILTER_REQUIRE_ARRAY,
            ],
        ]);
        $config = (array) ($payload['config'] ?? []);
        $username = trim((string) ($config['username'] ?? ''));
        $password =       (string) ($config['password'] ?? '');
        $homedir  = trim((string) ($config['homedir']  ?? ''));
        $domain   = trim((string) ($config['domain']   ?? ''));
        $quotaMb  =   (int) ($config['quota_mb'] ?? 0);

        if ($username === '' || $password === '' || $homedir === '') {
            $this->flash('error', __('FTP'),
                    __('Username, password and home directory are required'));
            return;
        }
        if (!\pmwh3\Utils\PasswordUtil::isValidLength($password)) {
            $this->flash('error', __('FTP'),
                    sprintf(__('Password must be at least %d characters.'), \pmwh3\Utils\PasswordUtil::minLength()));
            return;
        }
        if (FtpManager::getByUsername($username)) {
            $this->flash('error', __('FTP'),
                    __('That username already exists'));
            return;
        }

        // master = the customer name of the logged-in user
        $cid    = (int) Session::getNs('pmwh3', 'customer_id');
        $master = (string) (CustomerUtil::getCustomerNameById($cid) ?? '');

        $ok = FtpManager::create([
            'username' => $username,
            'password' => $password,
            'homedir'  => $homedir,
            'domain'   => $domain,
            'master'   => $master,
            'quota_mb' => $quotaMb,
        ]);

        if (!$ok) {
            $this->flash('error', __('FTP'),
                    __('Failed to create account'));
            return;
        }

        ActivityLog::write('ftp', 'create', $username,
                "domain={$domain}; homedir={$homedir}");
        $this->flash('success', __('FTP'),
                __('Account created'));
    }

    // ===== Update =======================================================

    public function change_account()
    {
        if (!CustomerUtil::hasAccess('edit_ftp')) {
            $this->flash('error', __('FTP'), __('Permission denied'));
            return;
        }
        $payload = filter_input_array(INPUT_POST, [
            'config' => [
                'filter' => FILTER_DEFAULT,
                'flags'  => FILTER_REQUIRE_ARRAY,
            ],
        ]);
        $config = (array) ($payload['config'] ?? []);
        $username = trim((string) ($config['username'] ?? ''));
        if ($username === '') {
            $this->flash('error', __('FTP'),
                    __('No account specified'));
            return;
        }
        $existing = FtpManager::getByUsername($username);
        if (!$existing) {
            $this->flash('error', __('FTP'),
                    __('Account not found'));
            return;
        }

        $fields = [
            'homedir' => (string) ($config['homedir'] ?? ''),
            'domain'  => (string) ($config['domain']  ?? ''),
        ];
        if (!empty($config['password'])) {
            $fields['password'] = (string) $config['password'];
        }
        if (array_key_exists('quota_mb', $config)) {
            $fields['quota_mb'] = (int) $config['quota_mb'];
        }

        // Diff for the audit log
        $diff = [];
        foreach (['homedir', 'domain'] as $k) {
            $old = (string) ($existing[$k] ?? '');
            $new = (string) ($fields[$k] ?? '');
            if ($old !== $new) {
                $diff[] = "{$k}={$new}";
            }
        }
        if (isset($fields['password'])) {
            $diff[] = 'password=changed';
        }
        if (array_key_exists('quota_mb', $fields)) {
            $diff[] = 'quota_mb=' . $fields['quota_mb'];
        }

        FtpManager::update($username, $fields);

        if (!empty($diff)) {
            ActivityLog::write('ftp', 'update', $username,
                    substr(implode('; ', $diff), 0, 255));
        }
        $this->flash('success', __('FTP'), __('Account updated'));
    }

    // ===== Delete =======================================================

    public function delete_account($username = '')
    {
        if (!CustomerUtil::hasAccess('delete_ftp')) {
            $this->flash('error', __('FTP'), __('Permission denied'));
            return;
        }
        if ($username === '') {
            $this->flash('error', __('FTP'),
                    __('No account specified'));
            return;
        }
        $existing = FtpManager::getByUsername($username);
        if (!$existing) {
            $this->flash('error', __('FTP'),
                    __('Account not found'));
            return;
        }

        FtpManager::delete($username);

        ActivityLog::write('ftp', 'delete', $username,
                'domain=' . (string) ($existing['domain'] ?? ''));
        $this->flash('success', __('FTP'),
                __('Account deleted'));
    }
}
