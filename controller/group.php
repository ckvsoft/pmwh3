<?php

// modules/pmwh3/controller/group.php

use ckvsoft\Session;
use pmwh3\Utils\CustomerUtil;
use pmwh3\Utils\GroupManager;

class Group extends \ckvsoft\mvc\BaseController
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
            ['view' => '/inc/header', 'data' => ['title' => __('Groups')]],
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
        // Avoid redirect loop when group/overview itself flashes
        // a permission-denied: empty $where (the default for
        // most callers) breaks out of the /group/ namespace to
        // general/overview instead.
        $url = $where === ''
                ? BASE_URI . 'pmwh3/general/overview'
                : BASE_URI . 'pmwh3/group/' . $where;
        \ckvsoft\Auth::sendFlashRedirect($url, $kind, $title, $msg);
    }

    public function index()
    {
        $this->overview();
    }

    public function overview()
    {
        if (!CustomerUtil::hasAccess('view_groups')) {
            $this->flash('error', __('Groups'), __('Permission denied'));
            return;
        }
        $groups = GroupManager::listAll();
        $this->render('pmwh3/group/overview', [
            'activeBox'   => 'group/overview',
            'groups'      => $groups,
            'create_perm' => CustomerUtil::hasAccess('create_group'),
            'edit_perm'   => CustomerUtil::hasAccess('edit_group'),
            'delete_perm' => CustomerUtil::hasAccess('delete_group'),
        ]);
    }

    /**
     * Show the customers in one group. Reachable via the "Members"
     * button in the overview.
     */
    public function members($gid = 0)
    {
        if (!CustomerUtil::hasAccess('view_groups')) {
            $this->flash('error', __('Groups'), __('Permission denied'));
            return;
        }
        $gid = (int) $gid;
        $row = GroupManager::getById($gid);
        if (!$row) {
            $this->flash('error', __('Groups'), __('Group not found'));
            return;
        }
        $this->render('pmwh3/group/members', [
            'activeBox' => 'group/overview',
            'group'     => $row,
            'members'   => GroupManager::listMembers($gid),
        ]);
    }

    public function new_group()
    {
        if (!CustomerUtil::hasAccess('create_group')) {
            $this->flash('error', __('Groups'), __('Permission denied'));
            return;
        }
        $this->render('pmwh3/group/edit', [
            'activeBox' => 'group/overview',
            'mode'      => 'new',
            'gid'       => 0,
            'row'       => ['name' => ''],
        ]);
    }

    public function change_group($gid = 0)
    {
        if (!CustomerUtil::hasAccess('edit_group')) {
            $this->flash('error', __('Groups'), __('Permission denied'));
            return;
        }
        $gid = (int) $gid;
        $row = GroupManager::getById($gid);
        if (!$row) {
            $this->flash('error', __('Groups'), __('Group not found'));
            return;
        }
        $this->render('pmwh3/group/edit', [
            'activeBox' => 'group/overview',
            'mode'      => 'edit',
            'gid'       => $gid,
            'row'       => $row,
        ]);
    }

    public function insert_group()
    {
        if (!CustomerUtil::hasAccess('create_group')) {
            $this->flash('error', __('Groups'), __('Permission denied'));
            return;
        }
        $input = new \ckvsoft\Input();
        $input->post('name', true);
        $in = $input->fetch();
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') {
            $this->flash('error', __('Groups'), __('Name is required'));
            return;
        }
        $newId = GroupManager::create($name);
        if ($newId === false) {
            $this->flash('error', __('Groups'), __('Could not create group (name already exists?)'));
            return;
        }
        $this->flash('success', __('Groups'), sprintf(__('Group "%s" created'), $name));
    }

    public function save_group($gid = 0)
    {
        if (!CustomerUtil::hasAccess('edit_group')) {
            $this->flash('error', __('Groups'), __('Permission denied'));
            return;
        }
        $gid  = (int) $gid;
        $input = new \ckvsoft\Input();
        $input->post('name', true);
        $in = $input->fetch();
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') {
            $this->flash('error', __('Groups'), __('Name is required'));
            return;
        }
        $ok = GroupManager::update($gid, $name);
        if (!$ok) {
            $this->flash('error', __('Groups'), __('Could not update group (name already in use?)'));
            return;
        }
        $this->flash('success', __('Groups'), sprintf(__('Group "%s" updated'), $name));
    }

    public function delete_group($gid = 0)
    {
        if (!CustomerUtil::hasAccess('delete_group')) {
            $this->flash('error', __('Groups'), __('Permission denied'));
            return;
        }
        $gid = (int) $gid;
        $row = GroupManager::getById($gid);
        if (!$row) {
            $this->flash('error', __('Groups'), __('Group not found'));
            return;
        }
        $members = GroupManager::memberCount($gid);
        if ($members > 0) {
            $this->flash('error', __('Groups'),
                    sprintf(__('Cannot delete group "%s": %d customer(s) are still in it'), $row['name'], $members));
            return;
        }
        GroupManager::delete($gid);
        $this->flash('success', __('Groups'), sprintf(__('Group "%s" deleted'), $row['name']));
    }

    // ===== Permissions grid for one customer group =====================

    /**
     * Permission-grid editor for one customer group's ACL. The cgrp
     * comes from the URL (Customer Groups overview links here). The
     * ogrp (= which thematic slice of permissions to show) comes via
     * POST so the user can switch slices without losing context.
     *
     * URL: pmwh3/group/permissions/<gid>
     */
    public function permissions($gid = 0)
    {
        if (!CustomerUtil::hasAccess('view_permissions')) {
            $this->flash('error', __('Permissions'), __('Permission denied'));
            return;
        }
        $roleId = (int) $gid;
        if ($roleId <= 0) {
            $this->flash('error', __('Permissions'), __('Group not found'));
            return;
        }
        $group = GroupManager::getById($roleId);
        if (!$group) {
            $this->flash('error', __('Permissions'), __('Group not found'));
            return;
        }

        $grouped = \pmwh3\Utils\AclManager::listPermissionsGrouped($roleId);

        $viewerCgrp = (int) (\ckvsoft\Session::getNs('pmwh3', 'role_id') ?? 0);
        $viewerCid  = (int) (\ckvsoft\Session::getNs('pmwh3', 'customer_id') ?? 0);

        $this->render('pmwh3/group/permissions', [
            'activeBox'   => 'group/overview',
            'group'       => $group,
            'grouped'     => $grouped,
            'viewerCgrp'  => $viewerCgrp,
            'viewerCid'   => $viewerCid,
            'change_perm' => CustomerUtil::hasAccess('change_permissions'),
        ]);
    }

    /**
     * POST handler for the permissions grid save submit. URL:
     *   pmwh3/group/save_permissions
     * roleId comes from the hidden field.
     */
    public function save_permissions()
    {
        if (!CustomerUtil::hasAccess('change_permissions')) {
            $this->flash('error', __('Permissions'), __('Permission denied'));
            return;
        }
        $input = new \ckvsoft\Input();
        $input->post('roleId', true);
        $input->submit();
        $data = $input->fetch();
        $roleId = (int) ($data['roleId'] ?? 0);

        // permId[<id>]=1 and known[]=<id> are array-shaped fields.
        // \ckvsoft\Input handles scalars only, so use
        // filter_input_array for the array bits.
        $payload = filter_input_array(INPUT_POST, [
            'permId' => [
                'filter' => FILTER_DEFAULT,
                'flags'  => FILTER_REQUIRE_ARRAY,
            ],
            'known' => [
                'filter' => FILTER_VALIDATE_INT,
                'flags'  => FILTER_REQUIRE_ARRAY,
            ],
        ]);
        $checked = array_map('intval', array_keys((array) ($payload['permId'] ?? [])));
        $known   = (array) ($payload['known'] ?? []);

        if ($roleId <= 0 || empty($known)) {
            $this->flash('error', __('Permissions'), __('Missing fields.'));
            return;
        }
        $viewerCgrp = (int) (\ckvsoft\Session::getNs('pmwh3', 'role_id') ?? 0);
        $result = \pmwh3\Utils\AclManager::applyGrid($roleId, $viewerCgrp, $checked, $known);

        \ckvsoft\Auth::sendFlashRedirect(
                BASE_URI . 'pmwh3/group/permissions/' . $roleId,
                'success',
                __('Permissions'),
                sprintf(
                        __('%d permission(s) updated, %d skipped.'),
                        $result['changed'],
                        $result['skipped']
                )
        );
    }
}
