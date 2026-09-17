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
        if (!\pmwh3\Utils\InstallBootstrap::securityTokenOk()) {
            $this->installRender('pmwh3/install_token', [
                'activeBox' => 'install',
                'tokenName' => \pmwh3\Utils\InstallBootstrap::securityTokenName(),
            ]);
        }

        $this->installRender('pmwh3/install', [
            'activeBox' => 'install',
            // pre-login page: fields stay BLANK. After a failed run()
            // attempt the previously submitted values are reset from
            // that attempt's own request (session from the client),
            // never prefilled from the framework config.json --
            // pre-filling real creds would leak the DB identity into
            // an anonymous page source.
            'frameworkHost'
                    => (string) ($_SESSION['pmwh3']['install_form']['db_host'] ?? ''),
            'frameworkName' => '', 'frameworkUser' => '',
            'sysChecks' => \pmwh3\Utils\InstallBootstrap::systemChecks(),
        ]);
    }

    /**
     * POST from the install form: persist the module config and run
     * the whole bootstrap (baseline, RBAC, admin customer).
     */
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
        $input->post('db_host', true)
                ->post('db_name', true)
                ->post('db_user', true)
                ->post('db_pass', true)
                ->post('dns_same')
                ->post('dns_host')
                ->post('dns_name')
                ->post('dns_user')
                ->post('dns_pass')
                ->post('admin_password', true);
        $input->submit();

        $in = $input->fetch() ?: [];
        if (!empty($in['dns_same'])) {
            $in['dns_host'] = $in['db_host'];
            $in['dns_name'] = trim((string) ($in['dns_name'] ?? ''));
            $in['dns_user'] = $in['db_user'];
            $in['dns_pass'] = $in['db_pass'];
        }

        try {
            $result = \pmwh3\Utils\InstallBootstrap::run($in);
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

        $this->flash('success',
                htmlspecialchars(implode('<br />', array_map(
                    fn($l, $d) => "$l: $d", array_keys($result['steps']),
                    array_values($result['steps'])
                ))),
                'login');
    }

    /** Remember the LAST attempt's host for the re-render (session only). */
    private function rememberForm(array $in): void
    {
        if (!isset($_SESSION)) {
            @session_start();
        }
        $_SESSION['pmwh3']['install_form']['db_host']
                = (string) ($in['db_host'] ?? '');
    }
    /**
     * Minimal pre-login page render (no pmwh3 menu, no login state):
     * framework header/footer around the wizard view.
     */
    private function installRender($view, $data = null)
    {
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
