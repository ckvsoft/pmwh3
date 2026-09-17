<?php

// modules/pmwh3/controller/customer.php

use pmwh3\Utils\CustomerUtil;
use pmwh3\Utils\CustomerManager;

class Customer extends \ckvsoft\mvc\BaseController
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
            ['view' => '/inc/header', 'data' => ['title' => __('Customers')]],
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

    private function flash(string $kind, string $title, string $msg, string $where = ''): void
    {
        // Empty $where used to route to pmwh3/customer/overview
        // which is the same action that just denied access --
        // infinite redirect loop. Redirect out of the /customer/
        // namespace to break it.
        $url = $where === ''
                ? BASE_URI . 'pmwh3/general/overview'
                : BASE_URI . 'pmwh3/customer/' . $where;
        \ckvsoft\Auth::sendFlashRedirect($url, $kind, $title, $msg);
    }

    public function index()
    {
        $this->overview();
    }

    public function overview()
    {
        if (!CustomerUtil::hasAccess('view_customers')
                && !CustomerUtil::hasAccess('view_own_account')) {
            $this->flash('error', __('Customers'), __('Permission denied'));
            return;
        }

        $sessionCid = (int) (\ckvsoft\Session::getNs('pmwh3', 'customer_id') ?? 0);

        $model = $this->loadModel('customer', 'pmwh3');

        // ultimate_admin (cid=1) sees ALL customers, not just the
        // hierarchy descended from themselves. The hasAccess() bypass
        // only covers permission checks; the hierarchy traversal in
        // CustomerUtil::getCustomerHierarchy is unaware of cid=1, so
        // we shortcut here.
        if ($sessionCid === 1) {
            $rows = $model->getAll();
        } elseif (CustomerUtil::hasAccess('view_all_customers')) {
            $rows = $model->getList($sessionCid, true);
        } elseif (CustomerUtil::hasAccess('view_customers')) {
            $rows = $model->getList($sessionCid, false);
        } else {
            $rows = [];
        }

        // If hierarchy lookup returned nothing (e.g. ADMIN_LEVEL=0 with
        // no sub-customers, or pmwh3_customers genuinely empty for this
        // user), at least show the logged-in user themselves so the
        // page isn't an unhelpful "no customers".
        if (empty($rows) && $sessionCid > 0) {
            $self = $model->getById($sessionCid);
            if ($self) {
                $self['level'] = 0;
                $rows = [$self];
            }
        }

        $this->render('pmwh3/customer/overview', [
            'activeBox'           => 'customer/overview',
            'customers'           => $rows,
            'create_perm'         => CustomerUtil::hasAccess('create_customer'),
            'edit_perm'           => CustomerUtil::hasAccess('edit_customer'),
            'delete_perm'         => CustomerUtil::hasAccess('delete_customer'),
            'edit_password_perm'  => CustomerUtil::hasAccess('edit_customer_password'),
            'view_limits_perm'    => CustomerUtil::hasAccess('view_customer_limits'),
            'view_creator_perm'   => CustomerUtil::hasAccess('view_customer_creator'),
        ]);
    }

    public function new_user()
    {
        if (!CustomerUtil::hasAccess('create_customer')) {
            $this->flash('error', __('Customers'), __('Permission denied'));
            return;
        }

        $model = $this->loadModel('customer', 'pmwh3');
        $sessionCid = (int) (\ckvsoft\Session::getNs('pmwh3', 'customer_id') ?? 0);

        $this->render('pmwh3/customer/edit', [
            'activeBox'        => 'customer/overview',
            'mode'             => 'new',
            'row'              => $this->emptyCustomerRow(),
            'limits'           => $this->emptyLimitsRow(),
            'groups'           => $model->getGroups(),
            'packages'         => $model->getPackagesForSelect($sessionCid),
            'packages_full'    => $model->getAllPackages($sessionCid),
            'view_limits_perm' => CustomerUtil::hasAccess('view_customer_limits'),
            'edit_limits_perm' => CustomerUtil::hasAccess('view_customer_limits'),
            'languages'        => \pmwh3\Config\OptionProviders::languages(),
        ]);
    }

    public function insert_user()
    {
        if (!CustomerUtil::hasAccess('create_customer')) {
            $this->flash('error', __('Customers'), __('Permission denied'));
            return;
        }
        $sessionCid = (int) (\ckvsoft\Session::getNs('pmwh3', 'customer_id') ?? 0);

        $fields = $this->extractCustomerFields();
        $limits = $this->extractLimitFields();
        $passInput = new \ckvsoft\Input();
        $passInput->post('password');
        $password = (string) ($passInput->fetch()['password'] ?? '');

        $cid = CustomerManager::insert($fields, $limits, $password, $sessionCid);
        if ($cid > 0) {
            $this->flash('success', __('Customers'),
                    sprintf(__('Customer #%d created'), $cid));
        } else {
            $this->flash('error', __('Customers'), __('Could not create customer'),
                    'new_user');
        }
    }

    public function change_user($cid = 0)
    {
        $cid = (int) $cid;
        if (!CustomerUtil::hasAccess('edit_customer')) {
            $this->flash('error', __('Customers'), __('Permission denied'));
            return;
        }

        $model = $this->loadModel('customer', 'pmwh3');
        $row = $model->getById($cid);
        if (!$row) {
            $this->flash('error', __('Customers'), __('Customer not found'));
            return;
        }

        $sessionCid = (int) (\ckvsoft\Session::getNs('pmwh3', 'customer_id') ?? 0);

        $this->render('pmwh3/customer/edit', [
            'activeBox'        => 'customer/overview',
            'mode'             => 'edit',
            'row'              => $row,
            'limits'           => $model->getLimits($cid),
            'groups'           => $model->getGroups(),
            'packages'         => $model->getPackagesForSelect($sessionCid),
            'packages_full'    => $model->getAllPackages($sessionCid),
            'view_limits_perm' => CustomerUtil::hasAccess('view_customer_limits'),
            'edit_limits_perm' => CustomerUtil::hasAccess('view_customer_limits'),
            'languages'        => \pmwh3\Config\OptionProviders::languages(),
        ]);
    }

    public function save_user($cid = 0)
    {
        $cid = (int) $cid;
        if (!CustomerUtil::hasAccess('edit_customer')) {
            $this->flash('error', __('Customers'), __('Permission denied'));
            return;
        }

        $fields = $this->extractCustomerFields();
        $limits = CustomerUtil::hasAccess('view_customer_limits')
                ? $this->extractLimitFields()
                : null;

        $ok = CustomerManager::update($cid, $fields, $limits);
        if ($ok) {
            $this->flash('success', __('Customers'),
                    sprintf(__('Customer #%d updated'), $cid));
        } else {
            $this->flash('error', __('Customers'), __('Could not save customer'),
                    'change_user/' . $cid);
        }
    }

    public function delete_user($cid = 0)
    {
        $cid = (int) $cid;
        if (!CustomerUtil::hasAccess('delete_customer')) {
            $this->flash('error', __('Customers'), __('Permission denied'));
            return;
        }

        $sessionCid = (int) (\ckvsoft\Session::getNs('pmwh3', 'customer_id') ?? 0);
        if ($cid === $sessionCid) {
            $this->flash('error', __('Customers'),
                    __('You cannot delete your own account'));
            return;
        }
        if ($cid === 1) {
            $this->flash('error', __('Customers'),
                    __('The ultimate admin account (cid=1) cannot be deleted'));
            return;
        }

        $ok = CustomerManager::delete($cid);
        $this->flash($ok ? 'success' : 'error', __('Customers'),
                $ok ? sprintf(__('Customer #%d deleted'), $cid)
                    : __('Could not delete customer'));
    }

    public function change_password($cid = 0)
    {
        $cid = (int) $cid;
        if (!CustomerUtil::hasAccess('edit_customer_password')) {
            $this->flash('error', __('Customers'), __('Permission denied'));
            return;
        }
        $model = $this->loadModel('customer', 'pmwh3');
        $row = $model->getById($cid);
        if (!$row) {
            $this->flash('error', __('Customers'), __('Customer not found'));
            return;
        }

        $this->render('pmwh3/customer/password', [
            'activeBox' => 'customer/overview',
            'row'       => $row,
        ]);
    }

    public function save_password($cid = 0)
    {
        $cid = (int) $cid;
        if (!CustomerUtil::hasAccess('edit_customer_password')) {
            $this->flash('error', __('Customers'), __('Permission denied'));
            return;
        }
        $input = new \ckvsoft\Input();
        $input->post('password', true)->post('repeat', true);
        $in = $input->fetch();
        $password = (string) ($in['password'] ?? '');
        $repeat   = (string) ($in['repeat']   ?? '');
        if ($password === '' || $password !== $repeat) {
            $this->flash('error', __('Customers'),
                    __('Passwords do not match or are empty'),
                    'change_password/' . $cid);
            return;
        }
        $ok = CustomerManager::changePassword($cid, $password);
        $this->flash($ok ? 'success' : 'error', __('Customers'),
                $ok ? __('Password changed') : __('Could not change password'));
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    private function emptyCustomerRow(): array
    {
        return [
            'cid' => 0, 'customer' => '',
            'role_id' => \pmwh3\Utils\GroupManager::getDefaultRoleId(),
            'email' => '',
            'realname' => '', 'customer_number' => '', 'street' => '',
            'postcode' => '', 'city' => '', 'country' => '',
            'telephone' => '', 'facsimile' => '',
            'php' => 'N', 'cgi' => 'N', 'language' => '',
            'package' => '', 'creator' => 0, 'standard_subdomain' => 'N',
        ];
    }

    private function emptyLimitsRow(): array
    {
        return [
            'webspace' => 0, 'traffic' => 0, 'domains' => 0,
            'subdomains' => 0, 'emails' => 0, 'forwards' => 0,
            'dbases' => 0,
        ];
    }

    private function extractCustomerFields(): array
    {
        $allow = ['customer', 'role_id', 'email', 'realname', 'customer_number',
            'street', 'postcode', 'city', 'country', 'telephone', 'facsimile',
            'php', 'cgi', 'language', 'package', 'standard_subdomain'];
        $checkboxes = ['php', 'cgi', 'standard_subdomain'];

        $input = new \ckvsoft\Input();
        foreach ($allow as $f) {
            in_array($f, $checkboxes, true)
                    ? $input->post($f, true)
                    : $input->post($f);
        }
        $in = $input->fetch();

        $out = [];
        foreach ($allow as $f) {
            if (in_array($f, $checkboxes, true)) {
                $out[$f] = !empty($in[$f]) ? 'Y' : 'N';
            } elseif (isset($in[$f])) {
                $out[$f] = (string) $in[$f];
            }
        }
        return $out;
    }

    private function extractLimitFields(): array
    {
        $allow = ['webspace', 'traffic', 'domains', 'subdomains',
            'emails', 'forwards', 'dbases'];
        $input = new \ckvsoft\Input();
        foreach ($allow as $f) { $input->post($f); }
        $in = $input->fetch();

        $out = [];
        foreach ($allow as $f) {
            $out[$f] = (int) ($in[$f] ?? 0);
        }
        return $out;
    }
}
