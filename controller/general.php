<?php

class General extends ckvsoft\mvc\BaseController
{

    public function __construct()
    {
        parent::__construct();
        // \ckvsoft\Auth::isNotLogged('admin');
        \pmwh3\Utils\AuthMiddleware::enforceLogin();
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
        $this->general();
    }

    public function password()
    {
        $image = new \ckvsoft\Image(__DIR__ . '/../view/images/icons/' . 'choice-yes.png');
        $choice_yes = $image->toBase64();
        $data = ['choice_yes' => $choice_yes];

        $this->render('pmwh3/general/password', $data);
    }

    public function savepassword()
    {
        $input = new \ckvsoft\Input();

        try {
            $input->post('old_user_password', true)
                    ->post('new_password', true)
                    ->post('repeat_password', true);

            $input->submit();

            if ($input->fetchErrors()) {
                \ckvsoft\Output::error($input->fetchErrors());
                return;
            }

            $data = $input->fetch();
            $cid  = (int) (\ckvsoft\Session::getNs('pmwh3', 'customer_id') ?? 0);

            if ($cid <= 0) {
                \ckvsoft\Output::error(['session' => __('Not logged in.')]);
                return;
            }

            // 1. New password must match its repeat.
            if ($data['new_password'] !== $data['repeat_password']) {
                \ckvsoft\Output::error(['repeat_password' => __('New passwords do not match.')]);
                return;
            }

            // 2. Old password must be correct.
            if (!\pmwh3\Utils\CustomerManager::verifyPassword($cid, (string) $data['old_user_password'])) {
                \ckvsoft\Output::error(['old_user_password' => __('Old password is wrong.')]);
                return;
            }
            // 3. New password must meet length requirement.
            if (!\pmwh3\Utils\PasswordUtil::isValidLength((string) $data['new_password'])) {
                \ckvsoft\Output::error(['new_password' => sprintf(__('Password must be at least %d characters.'), \pmwh3\Utils\PasswordUtil::minLength())]);
                return;
            }

            // 4. Update.
            $ok = \pmwh3\Utils\CustomerManager::changePassword($cid, (string) $data['new_password']);
            if (!$ok) {
                \ckvsoft\Output::error(['new_password' => __('Could not update password.')]);
                return;
            }

            \ckvsoft\Output::success(['message' => __('Password changed.')]);
        } catch (\ckvsoft\CkvException $e) {
            \ckvsoft\Output::error($input->fetchErrors());
            exit();
        }
    }

    public function overview()
    {
        $this->general();
    }

    public function sessions()
    {
        $this->general('sessions');
    }

    /**
     * Kick a session: delete its row from pmwh3_activity. Admin
     * can kick anyone; non-admin can only kick their own (other
     * browser / device).
     *
     * Note: this only removes the activity row -- the user's
     * actual login (Session::set) lives in their own browser's
     * cookie store and isn't reachable from here. Kick effectively
     * means "they disappear from the active-sessions list and
     * their next page load will create a fresh activity row".
     */
    public function kick_session($id = 0)
    {
        $id = (int) $id;
        if ($id <= 0) {
            $this->location(BASE_URI . 'pmwh3/general/sessions');
        }

        $cid = (int) (\ckvsoft\Session::getNs('pmwh3', 'customer_id') ?? 0);
        \pmwh3\Utils\ActivityTracker::removeSession($id, $cid);

        $this->location(BASE_URI . 'pmwh3/general/sessions');
    }

    public function general($method = 'overview')
    {
        $model = $this->loadModel('general'); // lokal für die Methode        
        // Delegiere alles ans Model
        if (method_exists($model, $method)) {
            $data = $model->$method();
            $data['activeBox'] = "general/$method"; // z.B. Menühighlight
            $this->render('pmwh3/general/' . $method, $data);
        } else {
            // echo "Unknown general method: $method";
            throw new \ckvsoft\CkvException("Unknown general method: $method");
        }
    }

    public function traffic()
    {
        $model = $this->loadModel('traffic');

        $customerName = \ckvsoft\Session::getNs('pmwh3', 'customer_name');
        $customerId = \ckvsoft\Session::getNs('pmwh3', 'customer_id');

        // Aktueller Monat als Default
        $selectedMonth = filter_input(INPUT_POST, 'date', FILTER_SANITIZE_SPECIAL_CHARS) ?? date('Y-m');

        // Alle verfügbaren Monate aus der DB
        $months = $model->getTrafficDates();

        $sum = $model->getSummaryOfId($customerId, $selectedMonth);
        // Kunden-Traffic
        $customers = $model->getTrafficByCustomerId($customerId, $selectedMonth);
        // Domains-Traffic
        $domains = $model->getTrafficByDomainId($customerId, $selectedMonth);

        // Daten ans View übergeben
        $data = [
            'dates' => $months,
            'selected' => $selectedMonth,
            'sum' => $sum,
            'customer' => $customers,
            'domains' => $domains
        ];

        $this->render('pmwh3/general/traffic', $data);
    }

    public function traffic_details($domain, $date = '')
    {
        $model = $this->loadModel('traffic');

        $val = $model->getTrafficDetails($domain, $date);

        $data = [
            'val' => $val,
            'domain' => $domain
        ];

        $this->render('pmwh3/general/traffic_details', $data);
    }
}
