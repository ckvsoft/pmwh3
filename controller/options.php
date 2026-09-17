<?php

// modules/pmwh3/controller/options.php

use pmwh3\Utils\CustomerUtil;
use pmwh3\Config\SettingsSchema;
use pmwh3\Config\LazyConfig;

class Options extends \ckvsoft\mvc\BaseController
{

    /**
     * Maps section names to the matching ACL permission. Most are a
     * straight 'view_menu_options_<section>', but some pmwh3_acl_objects
     * rows use plural forms (confirmations, sessions, customers).
     */
    private const VIEW_PERM_MAP = [
        'system'       => 'view_menu_options_system',
        'web'          => 'view_menu_options_web',
        'email'        => 'view_menu_options_email',
        'ftp'          => 'view_menu_options_ftp',
        'dns'          => 'view_menu_options_dns',
        'databases'    => 'view_menu_options_databases',
        'customer'     => 'view_menu_options_customers',
        'domains'      => 'view_menu_options_domains',
        'messages'     => 'view_menu_options_messages',
        'confirmation' => 'view_menu_options_confirmations',
        'layout'       => 'view_menu_options_layout',
        'errorlog'     => 'view_menu_options_errorlog',
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
            ['view' => '/inc/header', 'data' => ['title' => __('Options')]],
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

    private function flash(string $kind, string $title, string $msg, ?string $section = null): void
    {
        // Empty/null $section used to route to pmwh3/options which
        // is the same controller -- infinite redirect loop on
        // permission-denied. Redirect out of the /options/
        // namespace to break it.
        $url = $section === null
                ? BASE_URI . 'pmwh3/general/overview'
                : BASE_URI . 'pmwh3/options/' . $section;
        \ckvsoft\Auth::sendFlashRedirect($url, $kind, $title, $msg);
    }

    public function index()
    {
        if (!CustomerUtil::hasAccess('view_menu_options')) {
            $this->flash('error', __('Options'), __('Permission denied'));
            return;
        }
        // Find the first section the user is allowed to see and redirect.
        foreach (self::VIEW_PERM_MAP as $section => $perm) {
            if (CustomerUtil::hasAccess($perm)) {
                header('Location: ' . BASE_URI . 'pmwh3/options/' . $section);
                exit;
            }
        }
        $this->flash('error', __('Options'), __('No accessible options section'));
    }

    // -----------------------------------------------------------------
    // Section view methods. Each is a one-liner delegating to _section().
    // URL pattern: pmwh3/options/<section>
    // -----------------------------------------------------------------

    public function system()       { $this->_section('system'); }
    public function web()          { $this->_section('web'); }
    public function email()        { $this->_section('email'); }
    public function ftp()          { $this->_section('ftp'); }
    public function dns()          { $this->_section('dns'); }
    public function databases()    { $this->_section('databases'); }
    public function customer()     { $this->_section('customer'); }
    public function domains()      { $this->_section('domains'); }
    public function messages()     { $this->_section('messages'); }
    public function confirmation() { $this->_section('confirmation'); }
    public function layout()       { $this->_section('layout'); }
    public function errorlog()     { $this->_section('errorlog'); }

    /** Purge errorlog entries older than ERRORLOG_RETAIN_DAYS. */
    public function errorlog_purge()
    {
        if (!CustomerUtil::hasAccess('save_options')) {
            $this->flash('error', __('Options'), __('Permission denied'));
            return;
        }
        $n = \pmwh3\Utils\ErrorHandler::purgeOld();
        $this->flash('success', __('Options'),
                sprintf(__('%d errorlog entrie(s) purged'), $n),
                'errorlog');
    }

    // -----------------------------------------------------------------
    // Section save methods. Form posts to pmwh3/options/save_<section>
    // -----------------------------------------------------------------

    public function save_system()       { $this->_save('system'); }
    public function save_web()          { $this->_save('web'); }
    public function save_email()        { $this->_save('email'); }
    public function save_ftp()          { $this->_save('ftp'); }
    public function save_dns()          { $this->_save('dns'); }
    public function save_databases()    { $this->_save('databases'); }
    public function save_customer()     { $this->_save('customer'); }
    public function save_domains()      { $this->_save('domains'); }
    public function save_messages()     { $this->_save('messages'); }
    public function save_confirmation() { $this->_save('confirmation'); }
    public function save_layout()       { $this->_save('layout'); }
    public function save_errorlog()     { $this->_save('errorlog'); }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /** Render one section's settings form. */
    private function _section(string $section): void
    {
        $perm = self::VIEW_PERM_MAP[$section] ?? null;
        if ($perm === null || !CustomerUtil::hasAccess($perm)) {
            $this->flash('error', __('Options'), __('Permission denied'));
            return;
        }

        $optionModel = $this->loadModel('option', 'pmwh3');
        $configLevel = (int) LazyConfig::get('CONFIG_LEVEL', '1');

        // Filter by visibility level. 9 = hidden / debug-only.
        $settings = $optionModel->getGroup($section);
        $settings = array_filter($settings, function ($def) use ($configLevel) {
            $level = (int) ($def['level'] ?? 1);
            if ($level >= 9) {
                return false;
            }
            return $level <= $configLevel;
        });

        $this->render('pmwh3/options/' . $section, [
            'activeBox'    => 'options',
            'section'      => $section,
            'sectionLabel' => SettingsSchema::getGroupLabels()[$section] ?? $section,
            'settings'     => $settings,
            'edit_perm'    => CustomerUtil::hasAccess('save_options'),
            'tabs'         => $this->accessibleTabs(),
        ]);
    }

    /**
     * The list of sections this user can see, as
     * [sectionKey => human label], honoring per-section view
     * permissions. Order matches VIEW_PERM_MAP -- which is the
     * order the tabs render in.
     */
    private function accessibleTabs(): array
    {
        $labels = SettingsSchema::getGroupLabels();
        $out    = [];
        foreach (self::VIEW_PERM_MAP as $key => $perm) {
            if (CustomerUtil::hasAccess($perm)) {
                $out[$key] = $labels[$key] ?? ucfirst($key);
            }
        }
        return $out;
    }

    /** Save handler for one section. */
    private function _save(string $section): void
    {
        if (!CustomerUtil::hasAccess('save_options')) {
            $this->flash('error', __('Options'), __('Permission denied'), $section);
            return;
        }

        // The Options form posts an array payload (config[key]=value).
        // Cevian's Input class only handles scalar fields via
        // filter_input(); for arrays we use filter_input_array, which
        // is the framework-neutral way that doesn't touch $_POST
        // directly. Returns null when the field is missing -> empty
        // values array -> "Nothing was saved" warning, which is
        // still strictly correct.
        $payload = filter_input_array(INPUT_POST, [
            'config' => [
                'filter' => FILTER_DEFAULT,
                'flags'  => FILTER_REQUIRE_ARRAY,
            ],
        ]);
        $values = is_array($payload['config'] ?? null) ? $payload['config'] : [];

        // Pre-fill 'N' for any checkboxes that aren't ticked (HTML
        // checkboxes don't appear in $_POST when off).
        $optionModel = $this->loadModel('option', 'pmwh3');
        foreach ($optionModel->getGroup($section) as $key => $def) {
            if (($def['type'] ?? '') === 'checkbox' && !array_key_exists($key, $values)) {
                $values[$key] = 'N';
            }
        }

        $report = $optionModel->saveBatch($values);

        if ($report['saved'] > 0 && empty($report['errors'])) {
            $this->flash('success', __('Options'),
                    sprintf(__('%d setting(s) saved'), $report['saved']),
                    $section);
        } elseif ($report['saved'] > 0) {
            $this->flash('warning', __('Options'),
                    sprintf(__('%d saved, %d skipped: %s'),
                            $report['saved'], $report['skipped'],
                            implode('; ', $report['errors'])),
                    $section);
        } else {
            $this->flash('error', __('Options'),
                    implode('; ', $report['errors']) ?: __('Nothing was saved'),
                    $section);
        }
    }
}
