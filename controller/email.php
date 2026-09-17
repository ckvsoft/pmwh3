<?php

// modules/pmwh3/controller/email.php

use pmwh3\Utils\CustomerUtil;
use pmwh3\Utils\MailManager;

class Email extends \ckvsoft\mvc\BaseController
{

    /** Map of detail-tab name -> view permission. */
    private const VIEW_PERM_MAP = [
        'email'     => 'view_menu_email_email',
        'forward'   => 'view_menu_email_forward',
        'catchall'  => 'view_menu_email_catchall',
        'filtering' => 'view_menu_email_filtering',
        'wblist'    => 'view_menu_email_wblist',
    ];

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
            ['view' => '/inc/header', 'data' => ['title' => __('Email')]],
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
        $url = $where === ''
                ? BASE_URI . 'pmwh3/general/overview'
                : BASE_URI . 'pmwh3/email/' . $where;
        \ckvsoft\Auth::sendFlashRedirect($url, $kind, $title, $msg);
    }

    private function currentDomain(): ?string
    {
        return CustomerUtil::currentDomain();
    }

    public function index()
    {
        $this->overview();
    }

    /** Domain picker / overview. */
    public function overview()
    {
        if (!CustomerUtil::hasAccess('view_menu_email_overview')
                && !CustomerUtil::hasAccess('view_email')) {
            $this->flash('error', __('Email'), __('Permission denied'));
            return;
        }

        $sessionCid = (int) (\ckvsoft\Session::getNs('pmwh3', 'customer_id') ?? 0);
        $model = $this->loadModel('email', 'pmwh3');

        $domains = $model->getDomainsForCustomer($sessionCid);

        $this->render('pmwh3/email/overview', [
            'activeBox' => 'email/overview',
            'domains'   => $domains,
            'current'   => $this->currentDomain(),
        ]);
    }

    /**
     * Set the "current domain" in the session and redirect back to
     * either the email overview or one of the detail tabs.
     *
     * URL: pmwh3/email/pick/<domain>[/<next>]
     *      where <next> is one of: email, forward, catchall,
     *      filtering, wblist (default: empty -> overview).
     */
    public function pick($domain = '', $next = '')
    {
        if ($domain !== '') {
            $sessionCid = (int) (\ckvsoft\Session::getNs('pmwh3', 'customer_id') ?? 0);
            $model = $this->loadModel('email', 'pmwh3');
            $domains = $model->getDomainsForCustomer($sessionCid);
            if (in_array($domain, array_column($domains, 'domain'), true)) {
                CustomerUtil::setCurrentDomain($domain);
            }
        }

        $allowedNext = ['email', 'forward', 'catchall', 'filtering', 'wblist'];
        if (in_array($next, $allowedNext, true)) {
            $this->location(BASE_URI . 'pmwh3/email/details/' . $next);
        } else {
            $this->location(BASE_URI . 'pmwh3/email/overview');
        }
    }

    /**
     * Tab-style detail view. URL: pmwh3/email/details/<tab>
     * where <tab> is one of: email, forward, catchall, filtering, wblist
     */
    public function details($view = 'email')
    {
        $view = strtolower((string) $view);
        $perm = self::VIEW_PERM_MAP[$view] ?? null;
        if ($perm === null) {
            $this->flash('error', __('Email'), __('Unknown view: %s'));
            return;
        }
        if (!CustomerUtil::hasAccess($perm)) {
            $this->flash('error', __('Email'), __('Permission denied'));
            return;
        }

        $domain = $this->currentDomain();
        if (!$domain) {
            $sessionCid = (int) (\ckvsoft\Session::getNs('pmwh3', 'customer_id') ?? 0);
            $model = $this->loadModel('email', 'pmwh3');
            $domains = $model->getDomainsForCustomer($sessionCid);
            $this->render('pmwh3/email/picker', [
                'activeBox' => 'email/overview',
                'domains'   => $domains,
                'targetTab' => $view,
            ]);
            return;
        }

        $model = $this->loadModel('email', 'pmwh3');

        switch ($view) {
            case 'email':
                $rows = $model->getMailboxes($domain);
                break;
            case 'forward':
                $rows = $model->getForwards($domain);
                break;
            case 'catchall':
                $rows = $model->getCatchall($domain);
                break;
            case 'filtering':
                // Per-scope threshold policies; global '@.' row plus
                // @domain / mailbox-of-domain rows are relevant here.
                $filterModel = $this->loadModel('filtering', 'pmwh3');
                $all = $filterModel->listAll();
                $rows = array_values(array_filter($all, function ($r) use ($domain) {
                    $s = (string) ($r['scope'] ?? '');
                    if ($s === '@.') {
                        // Server-global belongs to rspamd's actions.conf;
                        // pmwh3 lists and edits ONLY the editing domain.
                        return false;
                    }
                    $bare = (string) substr($s, 1);
                    if (str_starts_with($s, '@')) {
                        return rtrim($bare, ' ') === rtrim((string) $domain, '.')
                            || \pmwh3\Utils\DomainUtil::isSubdomain($bare, (string) $domain);
                    }
                    $at = strrpos($s, '@');
                    return $at !== false
                        && substr($s, $at + 1) === rtrim((string) $domain, '.');
                }));
                break;
            case 'wblist':
                // The wblist tab renders rowsWhite / rowsBlack instead
                // of "rows" -- keep the variable defined for 'rows'.
                $rows = [];
                $filterModel = $this->loadModel('filtering', 'pmwh3');
                $allW = $filterModel->listWb('W');
                $allB = $filterModel->listWb('B');
                $wbFilter = function (array $rows) use ($domain) {
                    return array_values(array_filter($rows, function (array $r) use ($domain) {
                        $s = (string) ($r['scope'] ?? '');
                        if ($s === '@.') {
                            return CustomerUtil::hasAccess('view_all_customers');
                        }
                        if (str_starts_with($s, '@')) {
                            $bare = (string) substr($s, 1);
                            return $bare === rtrim((string) $domain, '.')
                                || \pmwh3\Utils\DomainUtil::isSubdomain($bare, (string) $domain);
                        }
                        $at = strrpos($s, '@');
                        return $at !== false
                            && substr($s, $at + 1) === rtrim((string) $domain, '.');
                    }));
                };
                $rowsWhite = $wbFilter($allW);
                $rowsBlack = $wbFilter($allB);
                break;
            default:
                $rows = [];
        }

        // Action permissions are passed to the view so it renders the
        // right buttons.
        $perms = [
            'create_email_email'    => CustomerUtil::hasAccess('create_email_email'),
            'edit_email_email'      => CustomerUtil::hasAccess('edit_email_email'),
            'delete_email_email'    => CustomerUtil::hasAccess('delete_email_email'),
            'change_email_password' => CustomerUtil::hasAccess('change_email_password'),
            'change_email_quota'    => CustomerUtil::hasAccess('change_email_quota'),
            'create_email_forward'  => CustomerUtil::hasAccess('create_email_forward'),
            'edit_email_forward'    => CustomerUtil::hasAccess('edit_email_forward'),
            'change_email_forward'  => CustomerUtil::hasAccess('change_email_forward'),
            'delete_email_forward'  => CustomerUtil::hasAccess('delete_email_forward'),
            'clone_email_forward'   => CustomerUtil::hasAccess('clone_email_forward'),
            'create_email_catchall' => CustomerUtil::hasAccess('create_email_catchall'),
            'change_email_catchall' => CustomerUtil::hasAccess('change_email_catchall'),
            'delete_email_catchall' => CustomerUtil::hasAccess('delete_email_catchall'),
            'create_email_filtering' => CustomerUtil::hasAccess('create_email_filtering'),
            'edit_email_filtering'   => CustomerUtil::hasAccess('edit_email_filtering'),
            'delete_email_filtering' => CustomerUtil::hasAccess('delete_email_filtering'),
            'create_email_wblist'    => CustomerUtil::hasAccess('create_email_wblist'),
            'edit_email_wblist'      => CustomerUtil::hasAccess('edit_email_wblist'),
            'delete_email_wblist'    => CustomerUtil::hasAccess('delete_email_wblist'),
        ];

        $payload = [
            'activeBox' => 'email/details/' . $view,
            'domain'    => $domain,
            'view'      => $view,
            'rows'      => $rows,
            'perms'     => $perms,
        ];
        if ($view === 'wblist') {
            $payload['rowsWhite'] = $rowsWhite ?? [];
            $payload['rowsBlack'] = $rowsBlack ?? [];
        }

        $this->render('pmwh3/email/details_' . $view, $payload);
    }

    // -----------------------------------------------------------------
    // Mailbox actions
    // -----------------------------------------------------------------

    public function new_email()
    {
        if (!CustomerUtil::hasAccess('create_email_email')) {
            $this->flash('error', __('Email'), __('Permission denied'));
            return;
        }
        $domain = $this->currentDomain();
        if (!$domain) {
            $this->flash('error', __('Email'), __('Pick a domain first'));
            return;
        }
        $this->render('pmwh3/email/new_email', [
            'activeBox' => 'email/details/email',
            'domain'    => $domain,
        ]);
    }

    public function insert_email()
    {
        if (!CustomerUtil::hasAccess('insert_email_email')
                && !CustomerUtil::hasAccess('create_email_email')) {
            $this->flash('error', __('Email'), __('Permission denied'));
            return;
        }
        $domain = $this->currentDomain();
        $input = new \ckvsoft\Input();
        $input->post('local', true)->post('password', true)->post('quota');
        $in = $input->fetch();
        $local  = trim((string) ($in['local']    ?? ''));
        $pass   = (string)       ($in['password'] ?? '');
        $quota  = (int)          ($in['quota']    ?? 0);

        if ($domain === '' || $domain === null || $local === '' || $pass === '') {
            $this->flash('error', __('Email'), __('Missing fields'),
                    'details/email');
            return;
        }
        if (\pmwh3\Utils\CustomerUtil::isReservedName($local)) {
            $this->flash('error', __('Email'),
                    sprintf(__('Mailbox name %s is reserved'), $local),
                    'details/email');
            return;
        }
        if (!\pmwh3\Utils\PasswordUtil::isValidLength($pass)) {
            $this->flash('error', __('Email'),
                    sprintf(__('Password must be at least %d characters.'), \pmwh3\Utils\PasswordUtil::minLength()),
                    'details/email');
            return;
        }

        // Quota check on the domain's owner
        $ownerCid = \pmwh3\Utils\DomainUtil::getCidOfDomain((string) $domain);
        if ($ownerCid > 0 && !\pmwh3\Utils\CountingUtil::isAllowed($ownerCid, 'emails')) {
            $q = \pmwh3\Utils\CountingUtil::getQuota($ownerCid, 'emails');
            $msg = $q['max'] === 0
                    ? __('Email accounts are not included in this customer\'s package.')
                    : sprintf(__('Email quota exhausted (%d/%d used).'),
                              $q['used'] + $q['granted'], $q['max']);
            $this->flash('error', __('Email'), $msg, 'details/email');
            return;
        }

        $email = $local . '@' . $domain;
        $ok = MailManager::createMailbox(
                ['email' => $email, 'quota' => $quota],
                $pass
        );
        if ($ok && $ownerCid > 0) {
            \pmwh3\Utils\CountingUtil::increment($ownerCid, 'emails');
        }
        $this->flash($ok ? 'success' : 'error', __('Email'),
                $ok ? __('Mailbox created') : __('Could not create mailbox'),
                'details/email');
    }

    public function change_email($email = '')
    {
        if (!CustomerUtil::hasAccess('edit_email_email')) {
            $this->flash('error', __('Email'), __('Permission denied'));
            return;
        }
        $email = urldecode((string) $email);
        $model = $this->loadModel('email', 'pmwh3');
        $row = $model->getMailbox($email);
        if (!$row) {
            $this->flash('error', __('Email'), __('Mailbox not found'),
                    'details/email');
            return;
        }
        $this->render('pmwh3/email/change_email', [
            'activeBox' => 'email/details/email',
            'row'       => $row,
        ]);
    }

    public function save_email($email = '')
    {
        if (!CustomerUtil::hasAccess('edit_email_email')) {
            $this->flash('error', __('Email'), __('Permission denied'));
            return;
        }
        $email = urldecode((string) $email);
        $input = new \ckvsoft\Input();
        $input->post('quota');
        $quota = (int) ($input->fetch()['quota'] ?? 0);
        $ok = MailManager::updateMailbox($email, ['quota' => $quota]);
        $this->flash($ok ? 'success' : 'error', __('Email'),
                $ok ? __('Mailbox updated') : __('Could not update mailbox'),
                'details/email');
    }

    public function delete_email($email = '')
    {
        if (!CustomerUtil::hasAccess('delete_email_email')) {
            $this->flash('error', __('Email'), __('Permission denied'));
            return;
        }
        $email = urldecode((string) $email);
        $domain = '';
        if (str_contains($email, '@')) {
            [, $domain] = explode('@', $email, 2);
        }
        $ownerCid = $domain !== ''
                ? \pmwh3\Utils\DomainUtil::getCidOfDomain($domain)
                : 0;
        $ok = MailManager::deleteMailbox($email);
        if ($ok && $ownerCid > 0) {
            \pmwh3\Utils\CountingUtil::decrement($ownerCid, 'emails');
        }
        $this->flash($ok ? 'success' : 'error', __('Email'),
                $ok ? __('Mailbox deleted') : __('Could not delete mailbox'),
                'details/email');
    }

    public function change_mailbox_password($email = '')
    {
        if (!CustomerUtil::hasAccess('change_email_password')) {
            $this->flash('error', __('Email'), __('Permission denied'));
            return;
        }
        $email = urldecode((string) $email);
        $this->render('pmwh3/email/password', [
            'activeBox' => 'email/details/email',
            'email'     => $email,
        ]);
    }

    public function save_mailbox_password($email = '')
    {
        if (!CustomerUtil::hasAccess('change_email_password')) {
            $this->flash('error', __('Email'), __('Permission denied'));
            return;
        }
        $email = urldecode((string) $email);
        $input = new \ckvsoft\Input();
        $input->post('password', true);
        $pass = (string) ($input->fetch()['password'] ?? '');
        if ($pass === '') {
            $this->flash('error', __('Email'), __('Password empty'),
                    'details/email');
            return;
        }
        $ok = MailManager::changePassword($email, $pass);
        $this->flash($ok ? 'success' : 'error', __('Email'),
                $ok ? __('Password changed') : __('Could not change password'),
                'details/email');
    }

    // -----------------------------------------------------------------
    // Forward actions
    // -----------------------------------------------------------------

    public function new_forward()
    {
        if (!CustomerUtil::hasAccess('create_email_forward')) {
            $this->flash('error', __('Email'), __('Permission denied'));
            return;
        }
        $domain = $this->currentDomain();
        if (!$domain) {
            $this->flash('error', __('Email'), __('Pick a domain first'));
            return;
        }
        $this->render('pmwh3/email/edit_forward', [
            'activeBox'   => 'email/details/forward',
            'domain'      => $domain,
            'mode'        => 'new',
            'source'      => '',
            'local'       => '',
            'destinations' => '',
        ]);
    }

    /**
     * Edit an existing forward. URL: email/edit_forward/<source>
     * where <source> is the urlencoded full source address
     * (e.g. info%40example.com).
     */
    public function edit_forward($source = '')
    {
        if (!CustomerUtil::hasAccess('edit_email_forward')
                && !CustomerUtil::hasAccess('change_email_forward')) {
            $this->flash('error', __('Email'), __('Permission denied'),
                    'details/forward');
            return;
        }
        $domain = $this->currentDomain();
        if (!$domain) {
            $this->flash('error', __('Email'), __('Pick a domain first'));
            return;
        }
        $source = urldecode((string) $source);
        $row = MailManager::getForward($source);
        if (!$row) {
            $this->flash('error', __('Email'), __('Forward not found'),
                    'details/forward');
            return;
        }
        // Split the stored comma-list into one-per-line for the
        // textarea. The model still stores comma-joined; the view
        // is just nicer.
        $dest = (string) ($row['destination'] ?? '');
        $destinations = implode("\n",
                array_filter(array_map('trim',
                        preg_split('/[\r\n,]+/', $dest) ?: [])));

        // The local-part is everything before the @ in the source.
        $local = '';
        if (str_contains($source, '@')) {
            [$local, ] = explode('@', $source, 2);
        }

        $this->render('pmwh3/email/edit_forward', [
            'activeBox'    => 'email/details/forward',
            'domain'       => $domain,
            'mode'         => 'edit',
            'source'       => $source,
            'local'        => $local,
            'destinations' => $destinations,
        ]);
    }

    public function insert_forward()
    {
        if (!CustomerUtil::hasAccess('insert_email_forward')
                && !CustomerUtil::hasAccess('create_email_forward')) {
            $this->flash('error', __('Email'), __('Permission denied'));
            return;
        }
        $domain = $this->currentDomain();
        $input = new \ckvsoft\Input();
        $input->post('local', true)->post('destinations', true);
        $in = $input->fetch();
        $local  = trim((string) ($in['local']        ?? ''));
        $dest   = trim((string) ($in['destinations'] ?? ''));
        if ($domain === '' || $domain === null || $local === '' || $dest === '') {
            $this->flash('error', __('Email'), __('Missing fields'),
                    'details/forward');
            return;
        }

        // Quota check: a new forward only counts if it didn't exist
        // before (setForward is upsert). Look it up first.
        $source = $local . '@' . $domain;
        $ownerCid = \pmwh3\Utils\DomainUtil::getCidOfDomain((string) $domain);
        $model = $this->loadModel('email', 'pmwh3');
        $existing = $model->getForwards((string) $domain);
        $isNew = true;
        foreach ($existing as $f) {
            if (((string) ($f['source'] ?? '')) === $source) {
                $isNew = false;
                break;
            }
        }
        if ($isNew && $ownerCid > 0
                && !\pmwh3\Utils\CountingUtil::isAllowed($ownerCid, 'forwards')) {
            $q = \pmwh3\Utils\CountingUtil::getQuota($ownerCid, 'forwards');
            $msg = $q['max'] === 0
                    ? __('Mail forwards are not included in this customer\'s package.')
                    : sprintf(__('Forward quota exhausted (%d/%d used).'),
                              $q['used'] + $q['granted'], $q['max']);
            $this->flash('error', __('Email'), $msg, 'details/forward');
            return;
        }

        // setForward handles multi-line / comma input internally
        // (see PostfixAdapter::setForward).
        $ok = MailManager::setForward($source, $dest);
        if ($ok && $isNew && $ownerCid > 0) {
            \pmwh3\Utils\CountingUtil::increment($ownerCid, 'forwards');
        }
        $this->flash($ok ? 'success' : 'error', __('Email'),
                $ok ? __('Forward created') : __('Could not create forward'),
                'details/forward');
    }

    /**
     * Save edits to an existing forward. URL POST target:
     * pmwh3/email/save_forward/<source>
     */
    public function save_forward($source = '')
    {
        if (!CustomerUtil::hasAccess('edit_email_forward')
                && !CustomerUtil::hasAccess('change_email_forward')) {
            $this->flash('error', __('Email'), __('Permission denied'),
                    'details/forward');
            return;
        }
        $source = urldecode((string) $source);
        $input = new \ckvsoft\Input();
        $input->post('destinations', true);
        $dest = trim((string) ($input->fetch()['destinations'] ?? ''));
        if ($source === '' || $dest === '') {
            $this->flash('error', __('Email'), __('Missing fields'),
                    'details/forward');
            return;
        }
        $ok = MailManager::setForward($source, $dest);
        $this->flash($ok ? 'success' : 'error', __('Email'),
                $ok ? __('Forward updated') : __('Could not update forward'),
                'details/forward');
    }

    public function delete_forward($source = '')
    {
        if (!CustomerUtil::hasAccess('delete_email_forward')) {
            $this->flash('error', __('Email'), __('Permission denied'));
            return;
        }
        $source = urldecode((string) $source);
        $domain = '';
        if (str_contains($source, '@')) {
            [, $domain] = explode('@', $source, 2);
        }
        $ownerCid = $domain !== ''
                ? \pmwh3\Utils\DomainUtil::getCidOfDomain($domain)
                : 0;
        $ok = MailManager::deleteForward($source);
        if ($ok && $ownerCid > 0) {
            \pmwh3\Utils\CountingUtil::decrement($ownerCid, 'forwards');
        }
        $this->flash($ok ? 'success' : 'error', __('Email'),
                $ok ? __('Forward deleted') : __('Could not delete forward'),
                'details/forward');
    }

    // -----------------------------------------------------------------
    // Catchall actions
    // -----------------------------------------------------------------

    public function set_catchall()
    {
        if (!CustomerUtil::hasAccess('insert_email_catchall')
                && !CustomerUtil::hasAccess('create_email_catchall')
                && !CustomerUtil::hasAccess('change_email_catchall')) {
            $this->flash('error', __('Email'), __('Permission denied'));
            return;
        }
        $domain = $this->currentDomain();
        $input = new \ckvsoft\Input();
        $input->post('destinations', true);
        $dest = trim((string) ($input->fetch()['destinations'] ?? ''));
        if ($domain === '' || $domain === null || $dest === '') {
            $this->flash('error', __('Email'), __('Missing fields'),
                    'details/catchall');
            return;
        }
        // Catchall can have multiple destinations too (postfix
        // virtual_alias_maps accepts a comma-list). setCatchall
        // -> setForward('@<domain>', $dest) which already splits
        // on newline/comma.
        $ok = MailManager::setCatchall($domain, $dest);
        $this->flash($ok ? 'success' : 'error', __('Email'),
                $ok ? __('Catchall set') : __('Could not set catchall'),
                'details/catchall');
    }

    public function delete_catchall()
    {
        if (!CustomerUtil::hasAccess('delete_email_catchall')) {
            $this->flash('error', __('Email'), __('Permission denied'));
            return;
        }
        $domain = $this->currentDomain();
        if ($domain === '' || $domain === null) {
            $this->flash('error', __('Email'), __('Pick a domain first'),
                    'details/catchall');
            return;
        }
        $ok = MailManager::deleteCatchall($domain);
        $this->flash($ok ? 'success' : 'error', __('Email'),
                $ok ? __('Catchall removed') : __('Could not remove catchall'),
                'details/catchall');
    }
}
