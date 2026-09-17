<?php

// modules/pmwh3/controller/filtering.php

use ckvsoft\Input;
use pmwh3\Utils\CustomerUtil;
use pmwh3\Utils\FilteringManager;

/**
 * Filtering (per-scope spam thresholds) + WBList CRUD and the
 * rspamd pull endpoints.
 *
 * UI: the Email detail tabs list rows and link here; forms post back
 * into pmwh3/filtering/<action>.
 *
 * rspamd pulls (one-time server-side glue in local.d):
 *   map      = "http://.../pmwh3/filtering/map_wblist/W?key=..."
 *   settings = "http://.../pmwh3/filtering/settings_ucl?key=..."  (dynamic map)
 *   external_map backend = ".../pmwh3/filtering/settings_query"   (per message)
 *
 * Their access control: when the RSPAMD_MAP_TOKEN setting is set, all
 * exports require ?key=<token>; when empty they are open (internal
 * network assumption).
 */
class Filtering extends \ckvsoft\mvc\BaseController
{

    public function __construct()
    {
        parent::__construct();
        // NOTE: public rspamd pull endpoints (map_wblist/settings_ucl/
        // settings_query) must NOT require a login -- rspamd has no
        // session. Login is enforced per UI action below; the export
        // endpoints authenticate via RSPAMD_MAP_TOKEN (?key=...).
    }

    private function render($view, $data = null)
    {
        $pmwh3menuHelper = $this->loadHelper("pmwh3/pmwh3menu");
        $pmwh3menu = $pmwh3menuHelper->getMenu($data['activeBox'] ?? null);

        $this->renderPage([
            ['view' => '/inc/header', 'data' => ['title' => __('Filtering')]],
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
        \ckvsoft\Auth::sendFlashRedirect(
            BASE_URI . 'pmwh3/email/details/filtering', 'info', __('Filtering'), ''
        );
    }

    private function model(): Filtering_Model
    {
        return $this->loadModel('filtering', 'pmwh3');
    }

    /**
     * All editable scopes of the current domain for the scope
     * dropdown: existing MAILBOXES + ALIASES (forwards) -- rspamd
     * sees the original envelope-rcpt, so policies FOR an alias
     * resolve correctly (alias expansion happens after milter).
     */
    private function scopeOptions(string $domain): array
    {
        $mailboxes = \pmwh3\Utils\MailManager::listMailboxes($domain);
        $aliases   = \pmwh3\Utils\MailManager::listForwards($domain);
        return [
            'mailboxes' => $mailboxes,
            'aliases'   => $aliases,
        ];
    }

    private function requireDomain(): string
    {
        $domain = $this->currentDomain();
        if (!$domain) {
            $this->flash('error', __('Filtering'), __('Pick a domain first'), 'email/details/filtering');
            return '';
        }
        return (string) $domain;
    }

    private function requirePerm(string $perm, string $tab = 'details/filtering'): bool
    {
        // UI actions only (export endpoints gate on mapAuth/token)
        \pmwh3\Utils\AuthMiddleware::enforceLogin();
        if (!CustomerUtil::hasAccess($perm)) {
            $this->flash('error', __('Filtering'), __('Permission denied'), $tab);
            return false;
        }
        return true;
    }

    private function currentDomain(): ?string
    {
        return \pmwh3\Utils\CustomerUtil::currentDomain();
    }

    private function flash(string $kind, string $title, string $msg, string $where = 'email/details/filtering'): void
    {
        $url = BASE_URI . 'pmwh3/' . $where;
        \ckvsoft\Auth::sendFlashRedirect($url, $kind, $title, $msg);
    }

    // ================== Filtering (threshold policies) ===================

    public function new_filtering()
    {
        if (!$this->requirePerm('create_email_filtering')) {
            return;
        }
        $domain = $this->requireDomain();
        if ($domain === '') {
            return;
        }
        $this->render('pmwh3/email/edit_filtering', [
            'activeBox' => 'email/details/filtering',
            'domain'    => $domain,
            'row'       => null,
            'scopes'    => $this->scopeOptions($domain),
        ]);
    }

    public function edit_filtering($id = '')
    {
        if (!$this->requirePerm('edit_email_filtering')) {
            return;
        }
        $domain = $this->requireDomain();
        if ($domain === '') {
            return;
        }
        $row = $this->model()->getRow((int) $id);
        if (!$row) {
            $this->flash('error', __('Filtering'), __('Policy not found'), 'email/details/filtering');
            return;
        }
        $this->render('pmwh3/email/edit_filtering', [
            'activeBox' => 'email/details/filtering',
            'domain'    => $domain,
            'row'       => $row,
            'scopes'    => $this->scopeOptions($domain),
        ]);
    }

    public function insert_filtering()
    {
        if (!$this->requirePerm('create_email_filtering')) {
            return;
        }
        $this->saveFiltering(null);
    }

    public function save_filtering($id = '')
    {
        if (!$this->requirePerm('edit_email_filtering')) {
            return;
        }
        $this->saveFiltering((int) $id);
    }

    private function saveFiltering(?int $id): void
    {
        $input = new Input();
        $input->post('scope', true)->post('tag_threshold')->post('kill_threshold');
        $in   = $input->fetch();
        $scope = trim(strtolower(urldecode((string) ($in['scope'] ?? ''))));
        $tag   = $this->nullableFloat($in['tag_threshold'] ?? null);
        $kill  = $this->nullableFloat($in['kill_threshold'] ?? null);

        if ($scope === '' || !$this->canUseScope($scope)) {
            $this->flash('error', __('Filtering'), __('Scope not allowed'), 'email/details/filtering');
            return;
        }
        $idNew = $this->model()->save($id, 'domain', $scope, $tag, $kill);
        $this->flash($idNew > 0 ? 'success' : 'error', __('Filtering'),
                $idNew > 0 ? __('Policy saved') : __('Could not save policy'),
                'email/details/filtering');
    }

    public function delete_filtering($id = '')
    {
        if (!$this->requirePerm('delete_email_filtering')) {
            return;
        }
        $ok = $this->model()->delete((int) $id);
        $this->flash($ok ? 'success' : 'error', __('Filtering'),
                $ok ? __('Policy deleted') : __('Could not delete policy'),
                'email/details/filtering');
    }

    // ================== WBList ===========================================

    public function new_wblist()
    {
        if (!$this->requirePerm('create_email_wblist')) {
            return;
        }
        $domain = $this->requireDomain();
        if ($domain === '') {
            return;
        }
        $this->render('pmwh3/email/edit_wblist', [
            'activeBox' => 'email/details/wblist',
            'domain'    => $domain,
            'row'       => null,
            'scopes'    => $this->scopeOptions($domain),
        ]);
    }

    public function edit_wblist($id = '')
    {
        if (!$this->requirePerm('edit_email_wblist')) {
            return;
        }
        $domain = $this->requireDomain();
        if ($domain === '') {
            return;
        }
        $row = $this->model()->getWbRow((int) $id);
        if (!$row) {
            $this->flash('error', __('Filtering'), __('Entry not found'), 'email/details/wblist');
            return;
        }
        $this->render('pmwh3/email/edit_wblist', [
            'activeBox' => 'email/details/wblist',
            'domain'    => $domain,
            'row'       => $row,
            'scopes'    => $this->scopeOptions($domain),
        ]);
    }

    public function insert_wblist()
    {
        if (!$this->requirePerm('create_email_wblist')) {
            return;
        }
        $this->saveWb(null);
    }

    public function save_wblist($id = '')
    {
        if (!$this->requirePerm('edit_email_wblist')) {
            return;
        }
        $this->saveWb((int) $id);
    }

    private function saveWb(?int $id): void
    {
        $input = new Input();
        $input->post('scope', true)->post('list_type')->post('address', true)->post('comment');
        $in      = $input->fetch();
        $scope   = trim(strtolower(urldecode((string) ($in['scope'] ?? ''))));
        $type    = (($in['list_type'] ?? 'W') === 'B') ? 'B' : 'W';
        $address = trim((string) ($in['address'] ?? ''));
        $comment = trim((string) ($in['comment'] ?? ''));

        if ($address === '' || !$this->canUseScope($scope)) {
            $this->flash('error', __('Filtering'), __('Invalid entry'), 'email/details/wblist');
            return;
        }
        if (!$this->validAddress($address)) {
            $this->flash('error', __('Filtering'), __('Invalid address/domain/IP'), 'email/details/wblist');
            return;
        }

        $ok = $this->model()->saveWb($id, $scope, $type, $address, $comment);
        $this->flash($ok ? 'success' : 'error', __('Filtering'),
                $ok ? __('Entry saved') : __('Could not save entry'),
                'email/details/wblist');
    }

    public function delete_wblist($id = '')
    {
        if (!$this->requirePerm('delete_email_wblist')) {
            return;
        }
        $ok = $this->model()->deleteWb((int) $id);
        $this->flash($ok ? 'success' : 'error', __('Filtering'),
                $ok ? __('Entry deleted') : __('Could not delete entry'),
                'email/details/wblist');
    }

    // ================== rspamd export endpoints ==========================

    /**
     * multimap text map, one entry per line.
     * rspamd local.d/multimap.conf:
     *   map = "http://.../pmwh3/filtering/map_wblist/W?key=..."
     */
    public function map_wblist($list = 'W')
    {
        if ($this->isLockedOut()) {
            return $this->denyPlain();
        }
        $rows = $this->model()->listWb(strtoupper($list) === 'B' ? 'B' : 'W');
        $this->sendRevalidationHeaders();
        header('Content-Type: text/plain; charset=UTF-8');
        // An empty body makes rspamd log "cannot read empty map" on
        // every map refresh (both MX poll this continuously) -- always
        // emit at least one comment line (# lines are ignored by the
        // multimap parser).
        if (empty($rows)) {
            // A comment-only map still parses to zero entries and
            // keeps the "cannot read empty map" warning -- add one
            // never-matching placeholder address.
            echo "@pmwh3-placeholder.invalid # empty pmwh3 wblist "
                    . (strtoupper($list) === 'B' ? 'B' : 'W') . "\n";
            return;
        }
        foreach ($rows as $r) {
            echo trim((string) $r['address']), "\n";
        }
    }

    /**
     * rspamd maps reread based on Last-Modified/ETag. Dynamic content
     * changes constantly, so we inform with a fresh timestamp on every
     * request (otherwise the first (possibly EMPTY!) fetch stays cached
     * forever and later-added entries never activate in rspamd).
     */
    private function sendRevalidationHeaders(): void
    {
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
        header('ETag: "' . md5(microtime()) . '"');
        header('Cache-Control: no-cache');
    }

    /**
     * Complete per-scope policy map (UCL) for the rspamd settings
     * module dynamic map. Inheritance is resolved server-side already:
     * every scope emits its EFFECTIVE tag/kill thresholds.
     */
    public function settings_ucl()
    {
        if ($this->isLockedOut()) {
            return $this->denyPlain();
        }
        $rows = $this->model()->listAll();
        $this->sendRevalidationHeaders();
        header('Content-Type: text/plain; charset=UTF-8');

        foreach ($rows as $r) {
            $scope = (string) $r['scope'];
            $tag   = $r['tag_threshold'] !== null && $r['tag_threshold'] !== ''
                ? (float) $r['tag_threshold'] : null;
            $kill  = $r['kill_threshold'] !== null && $r['kill_threshold'] !== ''
                ? (float) $r['kill_threshold'] : null;
            if ($tag === null && $kill === null) {
                continue;
            }
            // Coherent pair so ordering never leaves 'add header'
            // above reject in rspamd's threshold sort.
            $eff = FilteringManager::externalMapSettings($tag, $kill);
            $tagEff = $eff['actions']['add header'] ?? null;
            $killEff = $eff['actions']['reject'] ?? null;
            $actions = [
                '"add header" = ' . (string) $tagEff . ';',
                'reject = ' . (string) $killEff . ';',
            ];
            $id = $scope === '@.' ? 'pmwh3_global' : 'pmwh3_' . md5($scope);
            echo $id, " {\n    id = \"{$id}\";\n";
            if ($scope !== '@.') {
                echo "    priority = medium;\n    rcpt = \"{$scope}\";\n";
            } else {
                echo "    priority = low;\n";
            }
            echo "    apply {\n        actions {", implode(' ', $actions), "}\n    }\n}\n\n";
        }
    }

    /**
     * Per-message query for a settings rule driven by external_map.
     * Selector supplies rcpt/user as GET param; pmwh3 resolves the
     * inheritance chain and returns only the threshold actions.
     */
    public function settings_query()
    {
        if ($this->isLockedOut()) {
            return $this->denyPlain();
        }
        // Selector sources: query param (method=query) OR JSON body
        // (method=body, the rspamd external_map default shape:
        // {"rcpt":"user@domain"} / {"user":"..."}).
        $input = new Input();
        $input->get('rcpt')->get('user');
        $in = $input->fetch();
        $addr = trim((string) ($in['rcpt'] ?? $in['user'] ?? ''));
        $raw = file_get_contents('php://input');
        $body = json_decode((string) $raw, true);

        // encode=json selector data arrives either as an object
        // {"rcpt": "user@…"} or as a flat pair list ["rcpt","user@…"].
        if (is_array($body)) {
            if (array_is_list($body)) {
                for ($i = 0; $i + 1 < count($body); $i += 2) {
                    $k = (string) $body[$i];
                    $v = (string) ($body[$i + 1] ?? '');
                    if ($addr === '' && in_array($k, ['rcpt', 'user'], true) && $v !== '') {
                        $addr = trim($v);
                    }
                }
            } else {
                foreach (['rcpt', 'user'] as $k) {
                    if ($addr === '' && !empty($body[$k]) && is_string($body[$k])) {
                        $addr = trim($body[$k]);
                    }
                }
            }
        }

        header('Content-Type: application/json; charset=UTF-8');
        if ($addr === '') {
            echo '{}';
            return;
        }
        $resolved = FilteringManager::resolveThresholds($addr);
        echo json_encode(
                $resolved === null
                        ? []
                        : FilteringManager::externalMapSettings(
                                $resolved['tag_threshold'],
                                $resolved['kill_threshold'],
                                $resolved['scope']
                        ),
                JSON_UNESCAPED_SLASHES
        );
    }

    // ================== helpers ==========================================

    private function nullableFloat($v): ?float
    {
        if ($v === null || trim((string) $v) === '') {
            return null;
        }
        $f = (float) $v;
        if ($f < 0 || $f > 99.9) {
            return null;
        }
        return $f;
    }

    private function canUseScope(string $scope): bool
    {
        $scope = trim(strtolower($scope));
        if ($scope === '' || $scope === '@.') {
            // '@.' is rspamd-global, NOT pmwh3-editable (anything else
            // would shadow the server defaults).
            return false;
        }
        if (CustomerUtil::hasAccess('view_all_customers')) {
            return true;
        }
        $domain = (string) ($this->currentDomain() ?? '');
        if (str_starts_with($scope, '@')) {
            return substr($scope, 1) !== '' && rtrim(substr($scope, 1), '.') === rtrim($domain, '.');
        }
        $at = strrpos($scope, '@');
        return $at !== false && $domain !== '' && substr($scope, $at + 1) === rtrim($domain, '.');
    }

    private function validAddress(string $a): bool
    {
        $a = trim($a);
        if ($a === '@.') {
            return true;
        }
        if (str_starts_with($a, '@')) {
            return (bool) preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)*$/', strtolower(substr($a, 1)));
        }
        if (str_contains($a, '@')) {
            return (bool) preg_match(
                    '/^[^@\s]+@[a-z0-9-]+(\.[a-z0-9-]+)+$/i', $a
            );
        }
        if (str_contains($a, '/')) {
            [$ip, $bits] = array_map('trim', explode('/', $a, 2));
            return filter_var($ip, FILTER_VALIDATE_IP) !== false
                    && ctype_digit($bits) && (int) $bits >= 0 && (int) $bits <= 128;
        }
        return filter_var($a, FILTER_VALIDATE_IP) !== false
                || (bool) preg_match('/^[a-zA-Z0-9-]+(\.[a-zA-Z0-9-]+)+$/', $a);
    }

    private function isLockedOut(): bool
    {
        $token = trim((string) \pmwh3\Config\LazyConfig::get('RSPAMD_MAP_TOKEN', ''));
        if ($token === '') {
            return false;
        }
        $input = new Input();
        $input->get('key');
        $key = (string) ($input->fetch()['key'] ?? '');
        return $key === '' || !hash_equals($token, $key);
    }

    private function denyPlain(): void
    {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "403 forbidden\n";
    }

    public function mapAuth(): bool
    {
        return !$this->isLockedOut();
    }
}
