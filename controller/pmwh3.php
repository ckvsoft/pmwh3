<?php

class Pmwh3 extends ckvsoft\mvc\BaseController
{

    public function __construct()
    {
        parent::__construct();
        // \ckvsoft\Auth::isNotLogged('admin');
        pmwh3\Utils\AuthMiddleware::enforceLogin();
    }

    private function render($view, $data = null)
    {
        // Menü laden
        $pmwh3menuHelper = $this->loadHelper("pmwh3/pmwh3menu");
        $pmwh3menu = $pmwh3menuHelper->getMenu($data['activeBox'] ?? null);

        $this->renderPage([
            ['view' => '/inc/header', 'data' => ['title' => 'Events']],
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
        // Module root has no content of its own -- send the user to
        // the General overview, which has a real landing page and
        // sets the correct active-box state for the sidebar.
        $this->location(BASE_URI . 'pmwh3/general/overview');
    }

    /**
     * Logout
     */
    public function logout()
    {
        // Session leeren
        \ckvsoft\Session::clearNs('pmwh3');

        $input = new \ckvsoft\Input();

        // Prüfen, ob der Request über JS/FETCH kommt
        // z.B. wir setzen beim JS-Fetch `input.post('ajax', true)`
        $isAjax = $input->post('ajax')->fetch() ? true : false;

        if ($isAjax) {
            // JS erwartet JSON
            \ckvsoft\Output::success();
        } else {
            // normaler Link im Browser
            header('Location: ' . BASE_URI . 'pmwh3/login');
            exit;
        }
    }

    public function setDomain()
    {
        $input = new \ckvsoft\Input();
        try {
            $input->post('domain', true);
            $input->submit();

            $data = $input->fetch();
            $domain = $data['domain'] ?? null;

            if ($domain == null) {
                ckvsoft\Output::error(["No domain found"]);
            } else {
                if ($domain == __('None')) {
                    ckvsoft\Session::removeNs('pmwh3', 'customer_current_domain');
                } else {
                    ckvsoft\Session::setNs('pmwh3', [
                        'customer_current_domain' => $domain
                    ]);
                }

                ckvsoft\Output::success(['domain' => $domain, 'info' => __('Domain changed'), 'message' => __('Domain changed to: ')]);
            }
        } catch (\ckvsoft\CkvException $e) {
            \ckvsoft\Output::error($input->fetchErrors());
            throw ckvsoft\CkvException("Error: $e");
        }
    }
}
