<?php

// modules/pmwh3/controller/package.php

use ckvsoft\Session;
use pmwh3\Utils\CustomerUtil;
use pmwh3\Utils\PackageManager;

class Package extends \ckvsoft\mvc\BaseController
{

    public function __construct()
    {
        parent::__construct();
        \pmwh3\Utils\AuthMiddleware::enforceLogin();
    }

    /**
     * Same render+layout pipeline as Customer/Email/Domain so the
     * pmwh3.css and pmwh3.js bundles get loaded -- without them the
     * .button.small-action overrides don't apply and the buttons
     * fall back to the framework's giant default .button rule.
     */
    private function render($view, $data = null)
    {
        $pmwh3menuHelper = $this->loadHelper("pmwh3/pmwh3menu");
        $pmwh3menu = $pmwh3menuHelper->getMenu($data['activeBox'] ?? null);

        $this->renderPage([
            ['view' => '/inc/header', 'data' => ['title' => __('Packages')]],
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
        $url = $where === ''
                ? BASE_URI . 'pmwh3/general/overview'
                : BASE_URI . 'pmwh3/package/' . $where;
        \ckvsoft\Auth::sendFlashRedirect($url, $kind, $title, $msg);
    }

    public function index()
    {
        $this->overview();
    }

    public function overview()
    {
        if (!CustomerUtil::hasAccess('view_packages')) {
            $this->flash('error', __('Packages'), __('Permission denied'));
            return;
        }
        $sessionCid = (int) (Session::getNs('pmwh3', 'customer_id') ?? 0);
        $packages   = PackageManager::listVisible($sessionCid);

        $this->render('pmwh3/package/overview', [
            'activeBox'   => 'package/overview',
            'packages'    => $packages,
            'session_cid' => $sessionCid,
            'create_perm' => CustomerUtil::hasAccess('create_package'),
            'edit_perm'   => CustomerUtil::hasAccess('edit_package'),
            'delete_perm' => CustomerUtil::hasAccess('delete_package'),
        ]);
    }

    public function new_package()
    {
        if (!CustomerUtil::hasAccess('create_package')) {
            $this->flash('error', __('Packages'), __('Permission denied'));
            return;
        }
        $this->render('pmwh3/package/edit', [
            'activeBox' => 'package/overview',
            'mode'      => 'new',
            'name'      => '',
            'creator'   => (int) (Session::getNs('pmwh3', 'customer_id') ?? 0),
            'row'       => $this->emptyRow(),
        ]);
    }

    public function change_package($name = '', $creator = 0)
    {
        if (!CustomerUtil::hasAccess('edit_package')) {
            $this->flash('error', __('Packages'), __('Permission denied'));
            return;
        }
        $name    = (string) urldecode($name);
        $creator = (int) $creator;

        if (!$this->canTouch($name, $creator)) {
            $this->flash('error', __('Packages'), __('Cannot edit this package'));
            return;
        }

        $row = PackageManager::getByName($name, $creator);
        if (!$row) {
            $this->flash('error', __('Packages'), __('Package not found'));
            return;
        }

        $this->render('pmwh3/package/edit', [
            'activeBox' => 'package/overview',
            'mode'      => 'edit',
            'name'      => $name,
            'creator'   => $creator,
            'row'       => $row,
        ]);
    }

    public function insert_package()
    {
        if (!CustomerUtil::hasAccess('create_package')) {
            $this->flash('error', __('Packages'), __('Permission denied'));
            return;
        }
        $sessionCid = (int) (Session::getNs('pmwh3', 'customer_id') ?? 0);
        $input = new \ckvsoft\Input();
        $input->post('package_name', true);
        $in = $input->fetch();
        $name = trim((string) ($in['package_name'] ?? ''));

        if ($name === '') {
            $this->flash('error', __('Packages'), __('Name is required'), 'new_package');
            return;
        }

        $ok = PackageManager::create($name, $sessionCid, $this->extractFields());
        if ($ok) {
            $this->flash('success', __('Packages'), sprintf(__('Package "%s" created'), $name));
        } else {
            $this->flash('error', __('Packages'), __('Could not create package (name already exists?)'));
        }
    }

    public function save_package($name = '', $creator = 0)
    {
        if (!CustomerUtil::hasAccess('edit_package')) {
            $this->flash('error', __('Packages'), __('Permission denied'));
            return;
        }
        $name    = (string) urldecode($name);
        $creator = (int) $creator;

        if (!$this->canTouch($name, $creator)) {
            $this->flash('error', __('Packages'), __('Cannot edit this package'));
            return;
        }

        $ok = PackageManager::update($name, $creator, $this->extractFields());
        if ($ok) {
            $this->flash('success', __('Packages'), sprintf(__('Package "%s" updated'), $name));
        } else {
            $this->flash('error', __('Packages'), __('Could not update package'));
        }
    }

    public function delete_package($name = '', $creator = 0)
    {
        if (!CustomerUtil::hasAccess('delete_package')) {
            $this->flash('error', __('Packages'), __('Permission denied'));
            return;
        }
        $name    = (string) urldecode($name);
        $creator = (int) $creator;

        if (!$this->canTouch($name, $creator)) {
            $this->flash('error', __('Packages'), __('Cannot delete this package'));
            return;
        }

        PackageManager::delete($name, $creator);
        $this->flash('success', __('Packages'), sprintf(__('Package "%s" deleted'), $name));
    }

    /**
     * Authorization: a non-admin can only edit/delete packages they
     * created themselves. System packages (creator=0) and other
     * resellers' packages are read-only for them. Admin (cid=1) can
     * touch anything.
     */
    private function canTouch(string $name, int $creator): bool
    {
        $sessionCid = (int) (Session::getNs('pmwh3', 'customer_id') ?? 0);
        if ($sessionCid === 1) {
            return true;
        }
        return $creator === $sessionCid;
    }

    private function extractFields(): array
    {
        $intFields = ['webspace', 'traffic', 'domains', 'subdomains',
                      'emails', 'forwards', 'dbases'];
        $checkboxes = ['php', 'cgi'];

        $input = new \ckvsoft\Input();
        foreach ($intFields as $f) { $input->post($f); }
        foreach ($checkboxes as $cb) { $input->post($cb, true); /* checkbox mode */ }
        $in = $input->fetch();

        $out = [];
        foreach ($intFields as $f) {
            $out[$f] = (int) ($in[$f] ?? 0);
        }
        foreach ($checkboxes as $cb) {
            $out[$cb] = !empty($in[$cb]) ? 'Y' : 'N';
        }
        return $out;
    }

    private function emptyRow(): array
    {
        return [
            'webspace' => 0, 'traffic' => 0, 'domains' => 0,
            'subdomains' => 0, 'emails' => 0, 'forwards' => 0,
            'dbases' => 0, 'php' => 'N', 'cgi' => 'N',
        ];
    }
}
