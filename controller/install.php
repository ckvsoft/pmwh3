<?php

/**
 * pmwh3 module installer (cevian-style).
 *
 * Sits at pmwh3/install and is reachable WITHOUT a login -- that's
 * the point: a fresh deployment (module copied in, placeholder
 * module.json) routes here once, collects DB credentials, plays the
 * baseline via the Updater's fresh path, bootstraps RBAC + the
 * 'admin' customer, and writes the REAL module.json.
 *
 * The AuthMiddleware-based controller gate (needsInstall -> redirect
 * to this installer) covers every other pmwh3 page, so a copied
 * module always lands on this wizard first instead of crashing on a
 * missing schema.
 */

class Install extends \ckvsoft\mvc\BaseController
{

    public function __construct()
    {
        parent::__construct();
        // NO AuthMiddleware here -- the installer must work pre-login.
    }

    public function index()
    {
        if (!\pmwh3\Utils\InstallBootstrap::needsInstall()) {
            $this->flash('success',
                    __('pmwh3 is already installed and configured.'),
                    'login');
        }

        // Step 0: security token (proof of server access, mirroring
        // the cevian core installer). The wizard opens only after the
        // token file exists.
        // Step 0 requires the return statement below to actually halt
        // the render chain; the wizard view MUST NOT render eagerly.
        if (!\pmwh3\Utils\InstallBootstrap::securityTokenOk()) {
            $this->installRender('pmwh3/install_token', [
                'activeBox' => 'install',
                'tokenName' => \pmwh3\Utils\InstallBootstrap::securityTokenName(),
            ]);
            return;
        }

        // two-phase config handled by WIZARD STEPS now (cevian
        // installer style): each step is confirmed independently:
        // 'config' (DB+DNS form, module.json write) -> 'dns' (schema
        // apply when needed) -> 'bootstrap' (admin password only).
        $step = \pmwh3\Utils\InstallBootstrap::wizardStep();

        // remember the operator's NON-SECRET form values in the
        // installer STATE file (var/pmwh3_install_state.json) -- NOT
        // the PHP session, so a fresh session (or a different browser)
        // sees the same careful prefetch.
        $form = \pmwh3\Utils\InstallBootstrap::formRemember();

        $this->installRender('pmwh3/install', [
            'activeBox' => 'install',
            'step'      => $step,
            'frameworkHost'   => (string) ($form['db_host'] ?? ''),
            'frameworkName'   => (string) ($form['db_name'] ?? ''),
            'frameworkUser'   => (string) ($form['db_user'] ?? ''),
            'frameworkDnsName' => (string) ($form['dns_name'] ?? ''),
            'frameworkDnsHost' => (string) ($form['dns_host'] ?? ''),
            'frameworkDnsUser' => (string) ($form['dns_user'] ?? ''),
            'frameworkDnsSame' => isset($form['dns_same'])
                ? trim((string) $form['dns_same']) !== ''
                : trim((string) ($form['dns_name'] ?? '')) === '',
            'dnsStatus' => \pmwh3\Utils\InstallBootstrap::dnsStatus(),
            'sysChecks' => \pmwh3\Utils\InstallBootstrap::systemChecks(),
        ]);
    }

    /**
     * POST from the install form: persist the module config and run
     * the whole bootstrap (baseline, RBAC, admin customer).
     */
    /** "Check again" from step 0 (cevian-style discrete steps). */
    public function checkToken()
    {
        if (\pmwh3\Utils\InstallBootstrap::securityTokenOk()) {
            $this->location(BASE_URI . 'pmwh3/install');
        }
        $this->flash('error',
                __('Security file still missing: ') . \pmwh3\Utils\InstallBootstrap::securityTokenName(),
                'install');
    }

    public function run()
    {
        if (!\pmwh3\Utils\InstallBootstrap::needsInstall()) {
            $this->location(BASE_URI . 'pmwh3/login');
        }
        // re-verify the server-access token at POST time (a form the
        // precheck page produced requires the token as well)
        if (!\pmwh3\Utils\InstallBootstrap::securityTokenOk()) {
            $this->flash('error', 'Security token file is missing.', 'install');
        }

        $input = new \ckvsoft\Input();
        $input->post('phase')
                ->post('db_host')
                ->post('db_name')
                ->post('db_user')
                ->post('db_pass')
                ->post('db_admin_user')
                ->post('db_admin_pass')
                ->post('dns_same')
                ->post('dns_host')
                ->post('dns_name')
                ->post('dns_user')
                ->post('dns_pass')
                ->post('dns_schema_choice')
                ->post('dns_schema_text')
                ->post('admin_password');
        $input->submit();

        $in = $input->fetch() ?: [];
        // Input::fetch() array_filters empty values away -- hidden
        // fields (Submit) vanish entirely. Refill them straight from
        // $_POST so rememberForm() can't wipe fields with ''. NO
        // unit editor dump concern: the stray keys set no defaults.
        foreach (['phase', 'db_host', 'db_name', 'db_user', 'db_pass',
                  'db_admin_user', 'db_admin_pass',
                  'dns_same', 'dns_host', 'dns_name', 'dns_user', 'dns_pass',
                  'dns_schema_choice', 'dns_schema_text',
                  'admin_password'] as $k) {
            if (!array_key_exists($k, $in) && array_key_exists($k, $_POST)) {
                $in[$k] = (string) $_POST[$k];
            }
        }
        if (empty($in['phase'])) {
            $in['phase'] = ((\pmwh3\Utils\InstallBootstrap::installBlocker() !== ''
                    && !str_contains(\pmwh3\Utils\InstallBootstrap::installBlocker(), 'placeholder')) ? '2' : '1');
        }
        if ($in['phase'] === '1') {
            // field-level defaults for the separate-DNS path: host
            // falls back to the module DB host when left blank
            if (trim((string) ($in['dns_host'] ?? '')) === '') {
                $in['dns_host'] = $in['db_host'] ?? '';
            }
        }

        try {
            if (($in['phase'] ?? '') === 'dns') {
                $result = \pmwh3\Utils\InstallBootstrap::runDnsSchema($in);
            } elseif ($in['phase'] === '2') {
                $result = \pmwh3\Utils\InstallBootstrap::runPhase2($in);
            } else {
                $result = \pmwh3\Utils\InstallBootstrap::runPhase1($in);
            }
        } catch (\Throwable $e) {
            $result = ['ok' => false, 'steps' => ['installer' => 'FAIL: ' . $e->getMessage()]];
        }

        if (!$result['ok']) {
            $fails = [];
            foreach ($result['steps'] as $label => $detail) {
                if (str_starts_with((string) $detail, 'FAIL')) {
                    // sanitize exception output: try to keep cwd-relative
                    $d = htmlspecialchars((string) $detail);
                    $d = str_replace([rtrim(getcwd(), '/') . '/', $GLOBALS['__SERVER_PATH__'] ?? ''], './', $d);
                    $fails[] = $label . ': ' . $d;
                }
            }
            $this->rememberForm($in);
            $this->flash('error', implode('<br />', $fails), 'install');
        }

        if ($in['phase'] === '1') {
            $this->rememberForm($in);
            // config persisted -> NEW request for the bootstrap phase
            // (module DB caches are per-request)
            $this->flash('success',
                    htmlspecialchars(implode(': ', array_merge(...[['step 1/2'],
                        array_map(fn($l, $d) => "$l: $d",
                            array_keys($result['steps']),
                            array_values($result['steps']))
                    ]))),
                    'install');
        }

        if ($in['phase'] === 'dns') {
            $this->flash('success',
                    htmlspecialchars(implode('<br />', array_map(
                        fn($l, $d) => "$l: $d", array_keys($result['steps']),
                        array_values($result['steps'])
                    ))),
                    'install');
        }

        $this->flash('success',
                htmlspecialchars(implode('<br />', array_map(
                    fn($l, $d) => "$l: $d", array_keys($result['steps']),
                    array_values($result['steps'])
                ))),
                ($in['phase'] === 'dns') ? 'install' : 'login');
    }

    /** Remember the LAST attempt's form values (state-file based,
     *  NON-secret values only: host/name/user/dns_name). */
    private function rememberForm(array $in): void
    {
        \pmwh3\Utils\InstallBootstrap::formRemember($in);
    }
    /**
     * Minimal pre-login page render (no pmwh3 menu, no login state):
     * framework header/footer around the wizard view.
     */
    private function installRender($view, $data = null)
    {
        // THE SITE's look: pmwh3 app chrome (header/footer/pmwh3.css)
        // -- the operator's own design, same as the login page; no
        // pmwh3 menu (the module isn't usable pre-install).
        $this->renderPage([
            ['view' => '/inc/header', 'data' => ['title' => __('Install pmwh3')]],
            ['view' => $view, 'data' => ['data' => $data]],
            ['view' => '/inc/footer'],
                ],
                "<style>" . $this->loadHelper("css", ['method' => 'getCss', 'args' => ['inc/css/pmwh3.css']]) . "</style>"
        );
    }

    /** Pre-login flash: Auth-based flash redirect (framework Auth helper). */
    private function flash(string $kind, string $msg, string $target): void
    {
        \ckvsoft\Auth::sendFlashRedirect(
                BASE_URI . 'pmwh3/' . $target,
                $kind,
                __('Install'),
                $msg
        );
    }
}
