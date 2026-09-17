<?php

// modules/pmwh3/controller/tools.php

use pmwh3\Utils\CustomerUtil;

class Tools extends \ckvsoft\mvc\BaseController
{

    public function __construct()
    {
        parent::__construct();
        \pmwh3\Utils\AuthMiddleware::enforceLogin();
    }

    /**
     * Page shell -- loads the pmwh3 menu helper the same way every
     * other module controller does, then renders header + nav + view
     * + footer.
     */
    private function render($view, $data = null)
    {
        $pmwh3menuHelper = $this->loadHelper("pmwh3/pmwh3menu");
        $pmwh3menu = $pmwh3menuHelper->getMenu($data['activeBox'] ?? null);

        $this->renderPage([
            ['view' => '/inc/header', 'data' => ['title' => __('Tools')]],
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

    public function index()
    {
        $this->overview();
    }

    public function overview()
    {
        if (!CustomerUtil::hasAccess('view_menu_tools')) {
            \ckvsoft\Auth::sendFlashRedirect(
                    BASE_URI . 'pmwh3/general/overview',
                    'error',
                    __('Tools'),
                    __('Permission denied')
            );
            return;
        }
        $this->render('pmwh3/tools/overview', [
            'activeBox' => 'tools/overview',
        ]);
    }

    public function menu()
    {
        if (!CustomerUtil::hasAccess('view_tools_menu')) {
            \ckvsoft\Auth::sendFlashRedirect(
                    BASE_URI . 'pmwh3/general/overview',
                    'error',
                    __('Tools'),
                    __('Permission denied')
            );
            return;
        }
        $this->render('pmwh3/tools/menu', [
            'activeBox'   => 'tools/menu',
            'rows'        => \pmwh3\Utils\MenuManager::listAll(),
            'create_perm' => CustomerUtil::hasAccess('create_menu_row'),
            'edit_perm'   => CustomerUtil::hasAccess('edit_menu_row'),
            'delete_perm' => CustomerUtil::hasAccess('delete_menu_row'),
        ]);
    }

    /**
     * GET: render the "new menu row" form.
     * URL: pmwh3/tools/new_menu_row
     */
    public function new_menu_row()
    {
        if (!CustomerUtil::hasAccess('create_menu_row')) {
            \ckvsoft\Auth::sendFlashRedirect(
                    BASE_URI . 'pmwh3/tools/menu',
                    'error',
                    __('Menu'),
                    __('Permission denied')
            );
            return;
        }
        $this->render('pmwh3/tools/edit_menu_row', [
            'activeBox'   => 'tools/menu',
            'mode'        => 'new',
            'row'         => [
                'name' => '', 'link' => '', 'box' => 0, 'sort' => 0,
                'hide' => 'N', 'icon' => '', 'permission' => '',
            ],
            'boxes'       => \pmwh3\Utils\MenuManager::listBoxes(),
            'permissions' => \pmwh3\Utils\MenuManager::listAvailablePermissions('view_menu_'),
        ]);
    }

    /**
     * POST: insert a brand-new row.
     * URL: pmwh3/tools/insert_menu_row
     */
    public function insert_menu_row()
    {
        if (!CustomerUtil::hasAccess('create_menu_row')) {
            \ckvsoft\Auth::sendFlashRedirect(
                    BASE_URI . 'pmwh3/tools/menu',
                    'error',
                    __('Menu'),
                    __('Permission denied')
            );
            return;
        }
        $data = $this->extractMenuFields();
        $ok = \pmwh3\Utils\MenuManager::create($data);
        \ckvsoft\Auth::sendFlashRedirect(
                BASE_URI . 'pmwh3/tools/menu',
                $ok ? 'success' : 'error',
                __('Menu'),
                $ok ? __('Menu row created.')
                    : __('Could not create -- name missing or (box, sort) already taken.')
        );
    }

    /**
     * GET: render edit form. URL: pmwh3/tools/edit_menu_row/<box>/<sort>
     */
    public function edit_menu_row($box = '', $sort = '')
    {
        if (!CustomerUtil::hasAccess('edit_menu_row')) {
            \ckvsoft\Auth::sendFlashRedirect(
                    BASE_URI . 'pmwh3/tools/menu',
                    'error',
                    __('Menu'),
                    __('Permission denied')
            );
            return;
        }
        $box  = (int) $box;
        $sort = (int) $sort;
        $row = \pmwh3\Utils\MenuManager::getByKey($box, $sort);
        if (!$row) {
            \ckvsoft\Auth::sendFlashRedirect(
                    BASE_URI . 'pmwh3/tools/menu',
                    'error',
                    __('Menu'),
                    __('Menu row not found.')
            );
            return;
        }
        $this->render('pmwh3/tools/edit_menu_row', [
            'activeBox'   => 'tools/menu',
            'mode'        => 'edit',
            'row'         => $row,
            // Keep the original key in hidden fields so save_menu_row
            // knows what to update even if the user changed box/sort.
            'origBox'     => $box,
            'origSort'    => $sort,
            'boxes'       => \pmwh3\Utils\MenuManager::listBoxes(),
            'permissions' => \pmwh3\Utils\MenuManager::listAvailablePermissions('view_menu_'),
        ]);
    }

    /**
     * POST: save an edited row. The original (box, sort) come as
     * hidden fields so we know which row to update -- the form
     * itself can submit new values for box+sort which then become
     * the new key.
     *
     * URL: pmwh3/tools/save_menu_row
     */
    public function save_menu_row()
    {
        if (!CustomerUtil::hasAccess('edit_menu_row')) {
            \ckvsoft\Auth::sendFlashRedirect(
                    BASE_URI . 'pmwh3/tools/menu',
                    'error',
                    __('Menu'),
                    __('Permission denied')
            );
            return;
        }
        $input = new \ckvsoft\Input();
        $input->post('origBox', true)->post('origSort', true);
        $input->submit();
        $orig    = $input->fetch();
        $oldBox  = (int) ($orig['origBox']  ?? 0);
        $oldSort = (int) ($orig['origSort'] ?? 0);

        $data = $this->extractMenuFields();
        $ok = \pmwh3\Utils\MenuManager::update($oldBox, $oldSort, $data);
        \ckvsoft\Auth::sendFlashRedirect(
                BASE_URI . 'pmwh3/tools/menu',
                $ok ? 'success' : 'error',
                __('Menu'),
                $ok ? __('Menu row saved.')
                    : __('Could not save -- name missing or new (box, sort) collides with another row.')
        );
    }

    /**
     * POST: delete a row. URL: pmwh3/tools/delete_menu_row/<box>/<sort>
     */
    public function delete_menu_row($box = '', $sort = '')
    {
        if (!CustomerUtil::hasAccess('delete_menu_row')) {
            \ckvsoft\Auth::sendFlashRedirect(
                    BASE_URI . 'pmwh3/tools/menu',
                    'error',
                    __('Menu'),
                    __('Permission denied')
            );
            return;
        }
        $ok = \pmwh3\Utils\MenuManager::delete((int) $box, (int) $sort);
        \ckvsoft\Auth::sendFlashRedirect(
                BASE_URI . 'pmwh3/tools/menu',
                $ok ? 'success' : 'error',
                __('Menu'),
                $ok ? __('Menu row deleted.')
                    : __('Could not delete -- row not found.')
        );
    }

    /**
     * POST: flip the hide flag in-place from the overview list.
     * URL: pmwh3/tools/toggle_menu_row/<box>/<sort>
     */
    public function toggle_menu_row($box = '', $sort = '')
    {
        if (!CustomerUtil::hasAccess('edit_menu_row')) {
            \ckvsoft\Auth::sendFlashRedirect(
                    BASE_URI . 'pmwh3/tools/menu',
                    'error',
                    __('Menu'),
                    __('Permission denied')
            );
            return;
        }
        $ok = \pmwh3\Utils\MenuManager::toggleHide((int) $box, (int) $sort);
        \ckvsoft\Auth::sendFlashRedirect(
                BASE_URI . 'pmwh3/tools/menu',
                $ok ? 'success' : 'error',
                __('Menu'),
                $ok ? __('Visibility toggled.')
                    : __('Row not found.')
        );
    }

    // ---------- Helpers ----------------------------------------------

    /**
     * Read the menu-form fields out of POST. Uses the framework's
     * Input abstraction (no $_POST direct access). Returns the
     * shape MenuManager::create/update expect.
     */
    private function extractMenuFields(): array
    {
        $input = new \ckvsoft\Input();
        $input->post('name')
              ->post('link')
              ->post('box')
              ->post('sort')
              ->post('hide', true)
              ->post('icon')
              ->post('permission');
        $input->submit();
        $data = $input->fetch();

        // Checkbox arrives only if checked, so default to 'N'.
        $hide = (string) ($data['hide'] ?? '') !== '' ? 'Y' : 'N';

        return [
            'name'       => (string) ($data['name']       ?? ''),
            'link'       => (string) ($data['link']       ?? ''),
            'box'        => (int)    ($data['box']        ?? 0),
            'sort'       => (int)    ($data['sort']       ?? 0),
            'hide'       => $hide,
            'icon'       => (string) ($data['icon']       ?? ''),
            'permission' => (string) ($data['permission'] ?? ''),
        ];
    }

    /**
     * Tools > Errorlog: live errorlog viewer. The ERRORLOG_* settings
     * remain in the Options > Errorlog section; this page only shows
     * the actual log.
     */
    public function errorlog()
    {
        if (!CustomerUtil::hasAccess('view_menu_tools_errorlog')) {
            $this->flashTo('error', __('Tools'), __('Permission denied'), 'tools/overview');
            return;
        }
        $page = filter_input(INPUT_GET, 'lines', FILTER_VALIDATE_INT, [
            'options' => ['default' => 50, 'min_range' => 1],
        ]);
        $this->render('pmwh3/tools/errorlog', [
            'activeBox'   => 'tools/errorlog',
            'viewerLines' => $page,
        ]);
    }

    /** Purge errorlog entries older than ERRORLOG_RETAIN_DAYS. */
    public function errorlog_purge()
    {
        if (!CustomerUtil::hasAccess('view_menu_tools_errorlog')) {
            $this->flashTo('error', __('Tools'), __('Permission denied'), 'tools/errorlog');
            return;
        }
        $n = \pmwh3\Utils\ErrorHandler::purgeOld();
        $this->flashTo('success', __('Tools'),
                sprintf(__('%d errorlog entrie(s) purged'), $n),
                'tools/errorlog');
    }

    // ==================== Backup =====================================

    private function flashTo(string $kind, string $title, string $msg, string $where): void
    {
        \ckvsoft\Auth::sendFlashRedirect(
                BASE_URI . 'pmwh3/' . $where, $kind, $title, $msg
        );
    }

    public function backup()
    {
        if (!CustomerUtil::hasAccess('view_backup')) {
            $this->flashTo('error', __('Tools'), __('Permission denied'), 'tools/overview');
            return;
        }
        $this->render('pmwh3/tools/backup', [
            'activeBox' => 'tools/backup',
            'rows'      => \pmwh3\Utils\BackupUtil::listFiles(),
            'perms'     => [
                'create_backup' => CustomerUtil::hasAccess('create_backup'),
            ],
        ]);
    }

    public function do_backup()
    {
        if (!CustomerUtil::hasAccess('create_backup')) {
            $this->flashTo('error', __('Backup'), __('Permission denied'), 'tools/backup');
            return;
        }
        try {
            $name = \pmwh3\Utils\BackupUtil::backup();
            $this->flashTo('success', __('Backup'), sprintf(__('Backup created: %s'), $name), 'tools/backup');
        } catch (\Throwable $e) {
            $this->flashTo('error', __('Backup'), $e->getMessage(), 'tools/backup');
        }
    }

    public function download_backup($name = '')
    {
        if (!CustomerUtil::hasAccess('view_backup')) {
            exit('403');
        }
        try {
            $path = $this->backupPath((string) $name);
            header('Content-Type: application/sql');
            header('Content-Disposition: attachment; filename="' . basename($path) . '"');
            readfile($path);
        } catch (\Throwable $e) {
            $this->flashTo('error', __('Backup'), $e->getMessage(), 'tools/backup');
            return;
        }
        exit;
    }

    public function delete_backup()
    {
        if (!CustomerUtil::hasAccess('create_backup')) {
            exit('403');
        }
        $input = new \ckvsoft\Input();
        $input->post('file', true);
        $name = (string) ($input->fetch()['file'] ?? '');
        try {
            $ok = \pmwh3\Utils\BackupUtil::delete($name);
            $this->flashTo($ok ? 'success' : 'error', __('Backup'),
                    $ok ? sprintf(__('Backup deleted: %s'), $name) : __('Delete failed'),
                    'tools/backup');
        } catch (\Throwable $e) {
            $this->flashTo('error', __('Backup'), $e->getMessage(), 'tools/backup');
        }
    }

    private function backupPath(string $name): string
    {
        $name = basename(trim($name));
        if ($name === '' || !str_starts_with($name, 'pmwh3_')) {
            throw new \RuntimeException('Invalid backup file');
        }
        $dir  = \pmwh3\Utils\BackupUtil::dir();
        $real = realpath($dir . '/' . $name);
        if ($real === false || !str_starts_with($real, $dir)) {
            throw new \RuntimeException('Backup file not found');
        }
        return $real;
    }

    // ==================== News =======================================

    public function news()
    {
        if (!CustomerUtil::hasAccess('view_news')) {
            $this->flashTo('error', __('Tools'), __('Permission denied'), 'tools/overview');
            return;
        }
        $model = $this->loadModel('tools', 'pmwh3');
        $this->render('pmwh3/tools/news', [
            'activeBox' => 'tools/news',
            'rows'      => $model->listNews(),
            'perms'     => [
                'create_news' => CustomerUtil::hasAccess('create_news'),
                'edit_news'   => CustomerUtil::hasAccess('edit_news'),
                'delete_news' => CustomerUtil::hasAccess('delete_news'),
            ],
        ]);
    }

    public function new_news()
    {
        if (!CustomerUtil::hasAccess('create_news')) {
            $this->flashTo('error', __('News'), __('Permission denied'), 'tools/news');
            return;
        }
        $this->render('pmwh3/tools/edit_news', [
            'activeBox' => 'tools/news',
            'row'       => null,
        ]);
    }

    public function edit_news($id = '')
    {
        if (!CustomerUtil::hasAccess('edit_news')) {
            $this->flashTo('error', __('News'), __('Permission denied'), 'tools/news');
            return;
        }
        $row = $this->loadModel('tools', 'pmwh3')->getNewsRow((int) $id);
        if (!$row) {
            $this->flashTo('error', __('News'), __('Not found'), 'tools/news');
            return;
        }
        $this->render('pmwh3/tools/edit_news', [
            'activeBox' => 'tools/news',
            'row'       => $row,
        ]);
    }

    public function insert_news()
    {
        if (!CustomerUtil::hasAccess('create_news')) {
            $this->flashTo('error', __('News'), __('Permission denied'), 'tools/news');
            return;
        }
        $this->saveNewsRow(null);
    }

    public function save_news($id = '')
    {
        if (!CustomerUtil::hasAccess('edit_news')) {
            $this->flashTo('error', __('News'), __('Permission denied'), 'tools/news');
            return;
        }
        $this->saveNewsRow((int) $id);
    }

    private function saveNewsRow(?int $id): void
    {
        $input = new \ckvsoft\Input();
        $input->post('news', true)->post('active', 'checkbox');
        $in     = $input->fetch();
        $text   = (string) ($in['news'] ?? '');
        $active = ((string) ($in['active'] ?? '0')) === '1';
        $ok = $this->loadModel('tools', 'pmwh3')->saveNews($id, $text, $active);
        $this->flashTo($ok ? 'success' : 'error', __('News'),
                $ok ? __('Saved') : __('Text is empty?'), 'tools/news');
    }

    public function toggle_news($id = '')
    {
        if (!CustomerUtil::hasAccess('edit_news')) {
            $this->flashTo('error', __('News'), __('Permission denied'), 'tools/news');
            return;
        }
        $this->loadModel('tools', 'pmwh3')->toggleNews((int) $id);
        $this->flashTo('success', __('News'), __('Toggled'), 'tools/news');
    }

    public function delete_news($id = '')
    {
        if (!CustomerUtil::hasAccess('delete_news')) {
            $this->flashTo('error', __('News'), __('Permission denied'), 'tools/news');
            return;
        }
        $this->loadModel('tools', 'pmwh3')->deleteNews((int) $id);
        $this->flashTo('success', __('News'), __('Deleted'), 'tools/news');
    }

    // ==================== Applications ===============================

    public function applications()
    {
        if (!CustomerUtil::hasAccess('view_applications')) {
            $this->flashTo('error', __('Tools'), __('Permission denied'), 'tools/overview');
            return;
        }
        $model = $this->loadModel('tools', 'pmwh3');
        $this->render('pmwh3/tools/applications', [
            'activeBox' => 'tools/applications',
            'rows'      => $model->listApplications(),
            'perRow'    => max(1, (int) \pmwh3\Config\LazyConfig::get('APPS_PER_ROW', 4)),
            'perms'     => [
                'create_application' => CustomerUtil::hasAccess('create_application'),
                'edit_application'   => CustomerUtil::hasAccess('edit_application'),
                'delete_application' => CustomerUtil::hasAccess('delete_application'),
            ],
        ]);
    }

    public function new_application()
    {
        if (!CustomerUtil::hasAccess('create_application')) {
            $this->flashTo('error', __('Applications'), __('Permission denied'), 'tools/applications');
            return;
        }
        $this->render('pmwh3/tools/edit_application', [
            'activeBox' => 'tools/applications',
            'row'       => null,
        ]);
    }

    public function edit_application($id = '')
    {
        if (!CustomerUtil::hasAccess('edit_application')) {
            $this->flashTo('error', __('Applications'), __('Permission denied'), 'tools/applications');
            return;
        }
        $row = $this->loadModel('tools', 'pmwh3')->getApplicationRow((int) $id);
        if (!$row) {
            $this->flashTo('error', __('Applications'), __('Not found'), 'tools/applications');
            return;
        }
        $this->render('pmwh3/tools/edit_application', [
            'activeBox' => 'tools/applications',
            'row'       => $row,
        ]);
    }

    public function insert_application()
    {
        if (!CustomerUtil::hasAccess('create_application')) {
            $this->flashTo('error', __('Applications'), __('Permission denied'), 'tools/applications');
            return;
        }
        $this->saveApplicationRow(null);
    }

    public function save_application($id = '')
    {
        if (!CustomerUtil::hasAccess('edit_application')) {
            $this->flashTo('error', __('Applications'), __('Permission denied'), 'tools/applications');
            return;
        }
        $this->saveApplicationRow((int) $id);
    }

    private function saveApplicationRow(?int $id): void
    {
        $input = new \ckvsoft\Input();
        $input->post('name', true)->post('link', true)->post('sort');
        $in   = $input->fetch();
        $newId = $this->loadModel('tools', 'pmwh3')->saveApplication(
                $id,
                (string) ($in['name'] ?? ''),
                (string) ($in['link'] ?? ''),
                (int)   ($in['sort'] ?? 0)
        );
        $this->flashTo($newId > 0 ? 'success' : 'error', __('Applications'),
                $newId > 0 ? __('Application saved') : __('Name/link empty?'), 'tools/applications');
    }

    public function delete_application($id = '')
    {
        if (!CustomerUtil::hasAccess('delete_application')) {
            $this->flashTo('error', __('Applications'), __('Permission denied'), 'tools/applications');
            return;
        }
        $this->loadModel('tools', 'pmwh3')->deleteApplication((int) $id);
        $this->flashTo('success', __('Applications'), __('Deleted'), 'tools/applications');
    }
}
