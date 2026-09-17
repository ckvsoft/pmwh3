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
            $this->flash('success', __('Install'),
                    __('pmwh3 is already installed and configured.'));
            $this->location(BASE_URI . 'pmwh3/general/overview');
        }

        $this->render('pmwh3/install', [
            'activeBox' => 'install',
            'frameworkHost' => $this->frameworkDbHint('host'),
            'frameworkName' => $this->frameworkDbHint('name'),
            'frameworkUser' => $this->frameworkDbHint('user'),
        ]);
    }

    /**
     * POST from the install form: persist the module config and run
     * the whole bootstrap (baseline, RBAC, admin customer).
     */
    public function run()
    {
        if (!\pmwh3\Utils\InstallBootstrap::needsInstall()) {
            $this->location(BASE_URI . 'pmwh3/general/overview');
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
            foreach ($result['steps'] as $label => $detail) {
                if (str_starts_with((string) $detail, 'FAIL')) {
                    $this->flash('error', __('Install'), htmlspecialchars("{$label}: {$detail}"), 'install');
                }
            }
            $this->location(BASE_URI . 'pmwh3/install');
        }

        foreach ($result['steps'] as $label => $detail) {
            $this->flash('success', __('Install'), "{$label}: {$detail}", 'login');
        }
        $this->location(BASE_URI . 'pmwh3/login');
    }

    /** Prefill hint from the framework's own config.json (same server). */
    private function frameworkDbHint(string $field): string
    {
        $path = rtrim(getcwd(), '/') . '/config/config.json';
        if (is_file($path)) {
            $json = json_decode((string) file_get_contents($path), true);
            return (string) ($json['database'][$field] ?? '');
        }
        return '';
    }
}
