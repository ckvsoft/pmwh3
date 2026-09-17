<?php

// modules/pmwh3/controller/domain.php

use ckvsoft\mvc\BaseController;
use ckvsoft\Session;
use pmwh3\Config\LazyConfig;
use pmwh3\Utils\ActivityLog;
use pmwh3\Utils\CustomerUtil;
use pmwh3\Utils\DnsManager;
use pmwh3\Utils\DomainManager;
use pmwh3\Utils\DomainUtil;
use pmwh3\Utils\FsManager;
use pmwh3\Utils\WebManager;

class Domain extends BaseController
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
            ['view' => '/inc/header', 'data' => ['title' => __('Domain management')]],
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

    private function flash(string $kind, string $title, string $msg, string $where = 'overview'): void
    {
        $url = $where === ''
                ? BASE_URI . 'pmwh3/general/overview'
                : BASE_URI . 'pmwh3/domain/' . $where;
        \ckvsoft\Auth::sendFlashRedirect($url, $kind, $title, $msg);
    }

    /**
     * Render the "no domain selected" picker. Used by detail-style
     * views (DNS, DNSSEC, Apache, Activity, ...) when neither the
     * URL nor the session has a current-domain set.
     *
     * The picker links each domain to /domain/details/<domain>/<tab>
     * so that one click both sets the session and lands on the
     * intended tab.
     */
    private function renderDomainPicker(string $back, string $tab = 'overview'): void
    {
        $cid = (int) Session::getNs('pmwh3', 'customer_id');
        $domains = DomainManager::listAllVisible($cid);

        $this->render('pmwh3/domain/picker', [
            'activeBox'   => 'domain/overview',
            'domains'     => $domains,
            'targetTab'   => $tab,
            'targetRoute' => $back,
        ]);
    }

    public function index()
    {
        $this->overview();
    }

    /**
     * Top-level overview / subdomain view / alias view dispatch.
     */
    public function overview()
    {
        $cid = (int) Session::getNs('pmwh3', 'customer_id');
        $domainModel = $this->loadModel('domain', 'pmwh3');

        $input = new \ckvsoft\Input();
        $input->post('domain')->post('alias');
        $data = $input->fetch();

        $viewData = [
            'activeBox'        => 'domain/overview',
            'isSubdomainView'  => false,
            'isAliasView'      => false,
            'CREATE_NEW_URL'   => 'new_domain',
            'CHANGE_URL'       => 'change_domain',
            'DELETE_URL'       => 'delete_domain',
            'DETAILS_URL'      => 'details',
            'no_domains_or_create' => false,
            'domains'          => [],
            'currentDomain'    => null,
            'currentAlias'     => null,
            'create_perm'      => CustomerUtil::hasAccess('create_domain'),
            'edit_perm'        => CustomerUtil::hasAccess('edit_domain'),
            'delete_perm'      => CustomerUtil::hasAccess('delete_domain'),
            'advanced_perm'    => CustomerUtil::hasAccess('view_domain_advanced'),
            'canViewSubdomain' => CustomerUtil::hasAccess('view_subdomain'),
            'canViewAlias'     => CustomerUtil::hasAccess('view_subdomain_alias'),
        ];

        if (!empty($data['domain'])) {
            $domainName = (string) $data['domain'];
            $viewData['isSubdomainView'] = true;
            $viewData['currentDomain']   = $domainName;
            $subs = $domainModel->getSubdomainsByDomain($domainName, "N");
            foreach ($subs as $sub) {
                $parentIp = DomainUtil::getIpByDomain(
                        $domainModel->getToplevelDomain($domainName),
                        $sub['subdomain']
                );
                $viewData['domains'][] = [
                    'subdomain'     => $sub['subdomain'],
                    'customer'      => $sub['customer'],
                    'alias'         => DomainUtil::getAliasCountBySubDomain($sub['subdomain']),
                    'sub_subdomain' => DomainUtil::getSubdomainCountByDomain($sub['subdomain'], "N", "Y"),
                    'ip'            => $parentIp,
                ];
            }
            $viewData['CREATE_NEW_URL'] = "subdomain_new/{$domainName}";
            $viewData['CHANGE_URL']     = "subdomain_edit";
            $viewData['DELETE_URL']     = "subdomain_delete";
            $viewData['create_perm']    = CustomerUtil::hasAccess('create_subdomain');
            $viewData['edit_perm']      = CustomerUtil::hasAccess('edit_subdomain');
            $viewData['delete_perm']    = CustomerUtil::hasAccess('delete_subdomain');
            $view = 'pmwh3/domain/overview_subdomains';
        } elseif (!empty($data['alias'])) {
            $aliasName = (string) $data['alias'];
            $viewData['isAliasView'] = true;
            $viewData['currentAlias'] = $aliasName;
            $aliases = $domainModel->getAliasesOfSubdomain($aliasName);
            foreach ($aliases as $al) {
                $parentIp = DomainUtil::getIpByDomain(
                        $domainModel->getToplevelDomain($aliasName),
                        $al['alias'] ?? ($al['subdomain'] ?? '')
                );
                $viewData['domains'][] = [
                    'alias'    => $al['alias']    ?? ($al['subdomain'] ?? ''),
                    'customer' => $al['customer'] ?? '',
                    'ip'       => $parentIp,
                ];
            }
            $viewData['CREATE_NEW_URL'] = "new_subdomain_alias/{$aliasName}";
            $viewData['CHANGE_URL']     = "change_subdomain_alias";
            $viewData['DELETE_URL']     = "delete_subdomain_alias";
            $viewData['create_perm']    = CustomerUtil::hasAccess('create_subdomain_alias');
            $viewData['edit_perm']      = CustomerUtil::hasAccess('edit_subdomain_alias');
            $viewData['delete_perm']    = CustomerUtil::hasAccess('delete_subdomain_alias');
            $view = 'pmwh3/domain/overview_aliases';
        } else {
            $viewData['domains'] = $domainModel->getDomains($cid);
            if (!$viewData['create_perm'] && empty($viewData['domains'])) {
                $viewData['no_domains_or_create'] = true;
                $view = 'pmwh3/domain/overview';
            } else {
                $view = 'pmwh3/domain/overview_domains';
            }
        }

        if ($viewData['isSubdomainView'] || $viewData['isAliasView']) {
            $linkDomain = $viewData['currentDomain'] ?: $viewData['currentAlias'];
            $viewData['oneLevelUpDomain'] = $domainModel->getToplevelDomain($linkDomain);
        }

        $this->render($view, $viewData);
    }

    // ===== Create domain =================================================

    public function new_domain()
    {
        if (!CustomerUtil::hasAccess('create_domain')) {
            $this->flash('error', __('Domains'), __('Permission denied'));
            return;
        }

        $input = new \ckvsoft\Input();
        $input->post('how');
        $in = $input->fetch();
        $how = (string) ($in['how'] ?? '');
        $dates = DomainManager::getDomainDates();
        $cid = (int) Session::getNs('pmwh3', 'customer_id');

        $ipString = (string) LazyConfig::get('IP_ADDRESS', '');
        $ipList = [];
        if ($ipString !== '') {
            foreach (preg_split('/\r\n|\r|\n|,/', $ipString) ?: [] as $ip) {
                $ip = trim($ip);
                if ($ip !== '') {
                    $ipList[$ip] = $ip;
                }
            }
        }

        $viewData = [
            'activeBox'  => 'domain/overview',
            'mode'       => $how === 'single' || $how === 'bulk' ? $how : 'select',
            'customers'  => CustomerUtil::getCustomersList($cid),
            'ips'        => $ipList,
            'reg_start'  => $dates['reg_start'],
            'reg_end'    => $dates['reg_end'],
            'host_start' => $dates['host_start'],
            'host_end'   => $dates['host_end'],
        ];
        $this->render('pmwh3/domain/new_domain', $viewData);
    }

    public function insert_domain()
    {
        if (!CustomerUtil::hasAccess('create_domain')) {
            $this->flash('error', __('Domains'), __('Permission denied'));
            return;
        }

        // Form posts an array payload config[domain], config[cid], etc.
        // The Input class only filters scalars; use filter_input_array
        // for the array-shaped POST.
        $payload = filter_input_array(INPUT_POST, [
            'config' => [
                'filter' => FILTER_DEFAULT,
                'flags'  => FILTER_REQUIRE_ARRAY,
            ],
        ]);
        $cfg = is_array($payload['config'] ?? null) ? $payload['config'] : [];
        $domain = strtolower(trim((string) ($cfg['domain'] ?? '')));
        $cid    = (int)    ($cfg['cid']      ?? 0);
        $ip     = (string) ($cfg['ip']       ?? '');

        if ($domain === '' || $cid <= 0) {
            $this->flash('error', __('Domains'), __('Domain name and customer are required'), 'new_domain');
            return;
        }
        if (!preg_match('/^[a-z0-9.\-]+\.[a-z0-9\-]+$/i', $domain)) {
            $this->flash('error', __('Domains'), __('Invalid domain name'), 'new_domain');
            return;
        }
        if (DomainManager::getByName($domain) !== null) {
            $this->flash('error', __('Domains'),
                    sprintf(__('Domain "%s" already exists'), $domain), 'new_domain');
            return;
        }

        // Quota: the customer who'll own this domain must have a free slot
        if (!\pmwh3\Utils\CountingUtil::isAllowed($cid, 'domains')) {
            $q = \pmwh3\Utils\CountingUtil::getQuota($cid, 'domains');
            $msg = $q['max'] === 0
                    ? __('Domains are not included in this customer\'s package.')
                    : sprintf(__('Domain quota exhausted (%d/%d used).'),
                              $q['used'] + $q['granted'], $q['max']);
            $this->flash('error', __('Domains'), $msg, 'new_domain');
            return;
        }

        $services = [];
        if (!empty($cfg['services_web']))  { $services[] = 'web';  }
        if (!empty($cfg['services_mail'])) { $services[] = 'mail'; }
        if (!empty($cfg['services_dns']))  { $services[] = 'dns';  }
        if (empty($services)) {
            $services = ['web', 'mail', 'dns'];
        }

        $ok = DomainManager::create([
            'domain'     => $domain,
            'cid'        => $cid,
            'ip'         => $ip,
            'services'   => implode(',', $services),
            'reg_start'  => $cfg['reg_start']  ?? null,
            'reg_end'    => $cfg['reg_end']    ?? null,
            'host_start' => $cfg['host_start'] ?? null,
            'host_end'   => $cfg['host_end']   ?? null,
        ]);

        if (!$ok) {
            $this->flash('error', __('Domains'), __('Failed to create domain'), 'new_domain');
            return;
        }
        \pmwh3\Utils\CountingUtil::increment($cid, 'domains');
        ActivityLog::write('domain', 'create', $domain,
                'services=' . implode(',', $services) . '; ip=' . $ip);
        $this->flash('success', __('Domains'),
                sprintf(__('Domain "%s" created'), $domain));
    }

    // ===== Edit / save / delete ==========================================

    public function change_domain($domain = '')
    {
        if (!CustomerUtil::hasAccess('edit_domain')) {
            $this->flash('error', __('Domains'), __('Permission denied'));
            return;
        }
        $row = DomainManager::getByName($domain);
        if (!$row) {
            $this->flash('error', __('Domains'), __('Domain not found'));
            return;
        }
        $this->render('pmwh3/domain/edit', [
            'activeBox' => 'domain/overview',
            'domain'    => $domain,
            'row'       => $row,
        ]);
    }

    public function save_domain($domain = '')
    {
        if (!CustomerUtil::hasAccess('edit_domain')) {
            $this->flash('error', __('Domains'), __('Permission denied'));
            return;
        }
        $row = DomainManager::getByName($domain);
        if (!$row) {
            $this->flash('error', __('Domains'), __('Domain not found'));
            return;
        }
        $payload = filter_input_array(INPUT_POST, [
            'config' => [
                'filter' => FILTER_DEFAULT,
                'flags'  => FILTER_REQUIRE_ARRAY,
            ],
        ]);
        $cfg = is_array($payload['config'] ?? null) ? $payload['config'] : [];

        $services = [];
        foreach (['web','mail','dns'] as $s) {
            if (!empty($cfg["services_{$s}"])) { $services[] = $s; }
        }
        $fields = [
            'ip'         => (string) ($cfg['ip']         ?? $row['ip']),
            'services'   => implode(',', $services) ?: $row['services'],
            'reg_start'  => (string) ($cfg['reg_start']  ?? $row['reg_start']),
            'reg_end'    => (string) ($cfg['reg_end']    ?? $row['reg_end']),
            'host_start' => (string) ($cfg['host_start'] ?? $row['host_start']),
            'host_end'   => (string) ($cfg['host_end']   ?? $row['host_end']),
        ];

        DomainManager::update($domain, $fields);

        // Build a short human-readable diff for the audit log.
        $changes = [];
        foreach ($fields as $k => $v) {
            $old = (string) ($row[$k] ?? '');
            $new = (string) $v;
            if ($old !== $new) {
                $changes[] = "{$k}={$new}";
            }
        }
        if (!empty($changes)) {
            ActivityLog::write('domain', 'update', $domain,
                    substr(implode('; ', $changes), 0, 255));
        }

        $this->flash('success', __('Domains'),
                sprintf(__('Domain "%s" updated'), $domain));
    }

    public function delete_domain($domain = '')
    {
        if (!CustomerUtil::hasAccess('delete_domain')) {
            $this->flash('error', __('Domains'), __('Permission denied'));
            return;
        }
        $row = DomainManager::getByName($domain);
        if (!$row) {
            $this->flash('error', __('Domains'), __('Domain not found'));
            return;
        }
        DomainManager::delete($domain, true);
        // Quota tally: free up a domain slot on its owner
        $ownerCid = (int) ($row['cid'] ?? 0);
        if ($ownerCid > 0) {
            \pmwh3\Utils\CountingUtil::decrement($ownerCid, 'domains');
        }
        ActivityLog::write('domain', 'delete', $domain,
                'customer=' . (string) ($row['customer'] ?? ''));
        $this->flash('success', __('Domains'),
                sprintf(__('Domain "%s" deleted'), $domain));
    }

    // ===== Admin / Pro details view ======================================

    /**
     * Admin/Pro tabbed details view. URL: pmwh3/domain/details/<domain>[/<tab>]
     * Permissions:
     *   view_domain_advanced  -- read-only access to the tabs
     *   edit_domain_advanced  -- inline edit on DNS records etc.
     */
    public function details($domain = '', $tab = 'overview')
    {
        if (!CustomerUtil::hasAccess('view_domain_advanced')) {
            $this->flash('error', __('Domains'), __('Permission denied'));
            return;
        }
        // No domain in the URL? Use the current-domain session key
        // (set by Pick / Change-domain in the top widget). If
        // there's still none, render a picker instead of erroring.
        if ($domain === '') {
            $domain = (string) (CustomerUtil::currentDomain() ?? '');
            if ($domain === '') {
                $this->renderDomainPicker('domain/details', $tab);
                return;
            }
        }

        $row = DomainManager::getByName($domain);
        if (!$row) {
            $this->flash('error', __('Domains'), __('Domain not found'));
            return;
        }

        // Mirror the picked domain into the session so the widget
        // and other detail views see the same selection.
        CustomerUtil::setCurrentDomain($domain);
        $tab = in_array($tab, ['overview','dns','dnssec','subdomains','email','apache','activity'], true)
                ? $tab : 'overview';

        $tabData = [];
        switch ($tab) {
            case 'dns':
                $tabData = [
                    'records'    => DnsManager::listRecords($domain),
                    'soa'        => DnsManager::getSoa($domain),
                    'zoneExists' => DnsManager::zoneExists($domain),
                    'edit_perm'  => CustomerUtil::hasAccess('edit_domain_advanced'),
                ];
                break;
            case 'dnssec':
                $tabData = [
                    'available'   => DnsManager::dnssecAvailable(),
                    'status'      => DnsManager::getDnssecStatus($domain),
                    'keys'        => DnsManager::listKeys($domain),
                    'metadata'    => DnsManager::listMetadata($domain),
                    'manage_perm' => CustomerUtil::hasAccess('manage_dnssec'),
                    'apiSet'      => trim((string) LazyConfig::get('PDNS_API_URL', '')) !== '',
                ];
                break;
            case 'subdomains':
                $cid = (int) Session::getNs('pmwh3', 'customer_id');
                $webspacePath = FsManager::resolveWebroot(
                        (string) ($row['customer'] ?? ''), $domain);
                $tabData = [
                    'subdomains'  => $this->loadModel('domain', 'pmwh3')->getSubdomainsByDomain($domain, 'Y'),
                    'quota'       => \pmwh3\Utils\SubdomainManager::quota((int) ($row['cid'] ?? $cid)),
                    'webspaceUsed'=> FsManager::dirSize($webspacePath),
                    'webspaceQuota'=> \pmwh3\Utils\CountingUtil::getQuota(
                            (int) ($row['cid'] ?? $cid), 'webspace'),
                    'create_perm' => CustomerUtil::hasAccess('create_subdomain'),
                    'edit_perm'   => CustomerUtil::hasAccess('edit_subdomain'),
                    'delete_perm' => CustomerUtil::hasAccess('delete_subdomain'),
                    'webEnabled'  => WebManager::isEnabled(),
                    'webName'     => WebManager::getName(),
                ];
                break;
            case 'email':
                $tabData = [
                    'mailboxes' => \pmwh3\Utils\MailManager::listMailboxes($domain),
                    'forwards'  => \pmwh3\Utils\MailManager::listForwards($domain),
                ];
                break;
            case 'apache':
                $domainModel = $this->loadModel('domain', 'pmwh3');
                // real traffic figures live in pmwh3_traffic (fed by the
                // log collector); the legacy vhost-table.traffic
                // column is never written.
                $trafficRow = \ckvsoft\mvc\Config::moduleDb()->selectOne(
                        "SELECT SUM(apache) AS t30, MAX(timestamp) AS newest
                           FROM pmwh3_traffic
                          WHERE domain = :d
                            AND timestamp >= NOW() - INTERVAL 30 DAY",
                        ['d' => $domain]
                );
                $tabData = [
                    'apache_rows'  => $domainModel->getApacheRowsForDomain($domain),
                    'traffic30'    => (int) ($trafficRow['t30'] ?? 0),
                    'trafficNewest'=> (string) ($trafficRow['newest'] ?? ''),
                ];
                break;
            case 'activity':
                $domainModel = $this->loadModel('domain', 'pmwh3');
                $tabData = [
                    'live'    => $domainModel->getLiveSessionsForDomain($domain),
                    'recent'  => $domainModel->getRecentSessionsForDomain($domain),
                    'history' => $domainModel->getChangeHistoryForDomain($domain),
                ];
                break;
        }

        $this->render('pmwh3/domain/details', [
            'activeBox' => 'domain/overview',
            'domain'    => $domain,
            'row'       => $row,
            'tab'       => $tab,
            'tabData'   => $tabData,
            'edit_perm' => CustomerUtil::hasAccess('edit_domain_advanced'),
        ]);
    }

    // ===== DNS record CRUD (admin advanced) ==============================

    public function dns_add($domain = '')
    {
        if (!CustomerUtil::hasAccess('edit_domain_advanced')) {
            $this->flash('error', __('Domains'), __('Permission denied'),
                    'details/' . $domain . '/dns');
            return;
        }
        $input = new \ckvsoft\Input();
        $input->post('name')->post('type')->post('content')->post('ttl')->post('prio');
        $in = $input->fetch();
        $name    = trim((string) ($in['name']    ?? ''));
        $type    = strtoupper(trim((string) ($in['type'] ?? 'A')));
        $content = trim((string) ($in['content'] ?? ''));
        $ttl     = (int)         ($in['ttl']     ?? 3600);
        $prio    = (int)         ($in['prio']    ?? 0);

        if ($content === '') {
            $this->flash('error', __('Domains'), __('Record content required'),
                    'details/' . $domain . '/dns');
            return;
        }
        if ($name === '' || $name === '@') {
            $name = $domain;
        } elseif (!str_ends_with($name, $domain)) {
            $name = $name . '.' . $domain;
        }

        $newId = DnsManager::addRecord($domain, $name, $type, $content, $ttl, $prio);
        if ($newId === 0) {
            $this->flash('error', __('Domains'), __('Failed to add DNS record'),
                    'details/' . $domain . '/dns');
            return;
        }
        ActivityLog::write('domain', 'dns_add', $domain,
                "{$type} {$name} -> " . substr($content, 0, 150));
        $this->flash('success', __('Domains'),
                sprintf(__('DNS record %s %s added'), $type, $name),
                'details/' . $domain . '/dns');
    }

    public function dns_save($domain = '', $recordId = 0)
    {
        if (!CustomerUtil::hasAccess('edit_domain_advanced')) {
            $this->flash('error', __('Domains'), __('Permission denied'),
                    'details/' . $domain . '/dns');
            return;
        }
        $recordId = (int) $recordId;
        $input = new \ckvsoft\Input();
        $input->post('name')->post('type')->post('content')->post('ttl')->post('prio');
        $in = $input->fetch();
        $fields = [];
        foreach (['name','type','content','ttl','prio'] as $f) {
            if (isset($in[$f])) {
                $fields[$f] = $in[$f];
            }
        }
        if (isset($fields['type'])) {
            $fields['type'] = strtoupper((string) $fields['type']);
        }
        DnsManager::updateRecord($recordId, $fields);
        $detailParts = [];
        foreach ($fields as $k => $v) {
            $detailParts[] = "{$k}={$v}";
        }
        ActivityLog::write('domain', 'dns_update', $domain,
                "#{$recordId} " . substr(implode(' ', $detailParts), 0, 240));
        $this->flash('success', __('Domains'), __('DNS record updated'),
                'details/' . $domain . '/dns');
    }

    public function dns_delete($domain = '', $recordId = 0)
    {
        if (!CustomerUtil::hasAccess('edit_domain_advanced')) {
            $this->flash('error', __('Domains'), __('Permission denied'),
                    'details/' . $domain . '/dns');
            return;
        }
        $recordId = (int) $recordId;
        DnsManager::deleteRecord($recordId);
        ActivityLog::write('domain', 'dns_delete', $domain, "#{$recordId}");
        $this->flash('success', __('Domains'), __('DNS record deleted'),
                'details/' . $domain . '/dns');
    }

    // ===== DNSSEC actions ================================================

    public function dnssec_secure($domain = '')
    {
        if (!CustomerUtil::hasAccess('manage_dnssec')) {
            $this->flash('error', __('Domains'), __('Permission denied'),
                    'details/' . $domain . '/dnssec');
            return;
        }
        $result = DnsManager::secureZone((string) $domain);
        \pmwh3\Utils\ErrorHandler::trace("[Domain.dnssec_secure] domain='{$domain}' result="
                . json_encode($result));
        if (!empty($result['ok'])) {
            ActivityLog::write('domain', 'dnssec_secure', $domain, 'zone secured');
            $this->flash('success', __('Domains'),
                    __('Zone secured. Submit the DS record to your registrar.'),
                    'details/' . $domain . '/dnssec');
        } else {
            $msg = (string) ($result['error']  ?? __('Failed'));
            $out = (string) ($result['output'] ?? '');
            if ($out !== '') {
                $msg .= ' -- ' . $out;
            }
            $this->flash('error', __('Domains'), $msg,
                    'details/' . $domain . '/dnssec');
        }
    }

    public function dnssec_disable($domain = '')
    {
        if (!CustomerUtil::hasAccess('manage_dnssec')) {
            $this->flash('error', __('Domains'), __('Permission denied'),
                    'details/' . $domain . '/dnssec');
            return;
        }
        $result = DnsManager::disableDnssec((string) $domain);
        if (!empty($result['ok'])) {
            ActivityLog::write('domain', 'dnssec_disable', $domain, 'dnssec disabled');
            $this->flash('success', __('Domains'),
                    __('DNSSEC disabled. Remove the DS record at your registrar.'),
                    'details/' . $domain . '/dnssec');
        } else {
            $msg = (string) ($result['error']  ?? __('Failed'));
            $out = (string) ($result['output'] ?? '');
            if ($out !== '') {
                $msg .= ' -- ' . $out;
            }
            $this->flash('error', __('Domains'), $msg,
                    'details/' . $domain . '/dnssec');
        }
    }

    public function dnssec_key_toggle($domain = '', $keyId = 0)
    {
        if (!CustomerUtil::hasAccess('manage_dnssec')) {
            $this->flash('error', __('Domains'), __('Permission denied'),
                    'details/' . $domain . '/dnssec');
            return;
        }
        $keyId = (int) $keyId;
        $input = new \ckvsoft\Input();
        $input->post('active');
        $in = $input->fetch();
        $active = isset($in['active']) && $in['active'] === '1';
        DnsManager::setKeyActive($keyId, $active);
        ActivityLog::write('domain', 'dnssec_key_toggle', $domain,
                "key #{$keyId} " . ($active ? 'activated' : 'deactivated'));
        $this->flash('success', __('Domains'),
                $active ? __('Key activated') : __('Key deactivated'),
                'details/' . $domain . '/dnssec');
    }

    public function dnssec_key_delete($domain = '', $keyId = 0)
    {
        if (!CustomerUtil::hasAccess('manage_dnssec')) {
            $this->flash('error', __('Domains'), __('Permission denied'),
                    'details/' . $domain . '/dnssec');
            return;
        }
        $keyId = (int) $keyId;
        DnsManager::deleteKey($keyId);
        ActivityLog::write('domain', 'dnssec_key_delete', $domain, "key #{$keyId}");
        $this->flash('success', __('Domains'), __('Key deleted'),
                'details/' . $domain . '/dnssec');
    }

    // ===== Subdomain CRUD (web adapter) ==================================

    private function subdomainOwnerCid(string $domain): int
    {
        $row = DomainManager::getByName($domain);
        return (int) ($row['cid'] ?? 0);
    }

    public function subdomain_new($domain = '')
    {
        $cid = (int) Session::getNs('pmwh3', 'customer_id');
        if (!CustomerUtil::hasAccess('create_subdomain')) {
            $this->flash('error', __('Domains'), __('Permission denied'));
            return;
        }
        if ($domain === '' || DomainManager::getByName($domain) === null) {
            $this->renderDomainPicker('pmwh3/domain/subdomain_new');
            return;
        }
        $ipString = (string) LazyConfig::get('IP_ADDRESS', '');
        $ipList = [];
        foreach (preg_split('/\r\n|\r|\n|,/', $ipString) ?: [] as $ip) {
            $ip = trim($ip);
            if ($ip !== '') {
                $ipList[$ip] = $ip;
            }
        }
        $this->render('pmwh3/domain/subdomain_form', [
            'activeBox' => 'domain/overview',
            'mode'      => 'create',
            'domain'    => $domain,
            'row'       => null,
            'ips'       => $ipList,
            'webEnabled'=> WebManager::isEnabled(),
            'certs'     => WebManager::listCerts(),
            'sslCapable'=> WebManager::supports('ssl'),
            'quota'     => \pmwh3\Utils\SubdomainManager::quota(
                    $this->subdomainOwnerCid($domain) ?: $cid),
        ]);
    }

    public function subdomain_insert($domain = '')
    {
        if (!CustomerUtil::hasAccess('create_subdomain')) {
            $this->flash('error', __('Domains'), __('Permission denied'));
            return;
        }
        $input = new \ckvsoft\Input();
        $input->post('sub')->post('mode_v')->post('value')->post('alias_of')->post('custom')->post('ssl_cert');
        $in = $input->fetch();
        $mode = (string) ($in['mode_v'] ?? 'directory');
        $value = match ($mode) {
            'ip'    => (string) ($in['value'] ?? ''),
            'alias' => (string) ($in['alias_of'] ?? ''),
            default => (string) ($in['value'] ?? ''),
        };
        $sslCert = (string) ($in['ssl_cert'] ?? 'auto');
        // whitelist against the available basename list
        $known = WebManager::listCerts();
        if ($sslCert !== '' && $sslCert !== 'auto') {
            $sslCert = isset($known[$sslCert]) ? $sslCert : '';
        }
        $result = \pmwh3\Utils\SubdomainManager::create([
            'sub'       => (string) ($in['sub'] ?? ''),
            'domain'    => $domain,
            'mode'      => $mode,
            'value'     => $value,
            'cid'       => $this->subdomainOwnerCid($domain),
            'custom'    => (string) ($in['custom'] ?? ''),
            'ssl_cert'  => $sslCert,
        ]);
        if (!$result['ok']) {
            $this->flash('error', __('Subdomains'), (string) $result['error'],
                    "subdomain_new/{$domain}");
            return;
        }
        ActivityLog::write('domain', 'subdomain_create', (string) $result['fqdn'],
                "mode={$mode}");
        $this->flash('success', __('Subdomains'),
                sprintf(__('Subdomain "%s" created'), (string) $result['fqdn']),
                "details/{$domain}/subdomains");
    }

    private function findSubdomainRow(string $fqdn, string $backTab): ?array
    {
        $row = \pmwh3\Utils\WebManager::getRow($fqdn);
        if (!$row) {
            $domain = \pmwh3\Utils\DomainUtil::getToplevelDomain($fqdn);
            $this->flash('error', __('Subdomains'), __('Subdomain not found'),
                    "details/{$domain}/{$backTab}");
        }
        return $row;
    }

    public function subdomain_edit($fqdn = '')
    {
        if (!CustomerUtil::hasAccess('edit_subdomain')) {
            $this->flash('error', __('Domains'), __('Permission denied'));
            return;
        }
        $fqdn = strtolower(trim($fqdn));
        if ($fqdn === '') {
            $input = new \ckvsoft\Input();
            $input->post('subdomain');
            $in = $input->fetch();
            $fqdn = strtolower(trim((string) ($in['subdomain'] ?? '')));
        }
        $row = $this->findSubdomainRow($fqdn, 'subdomains');
        if ($row === null) {
            return;
        }
        $domain = (string) $row['domain'];
        $this->render('pmwh3/domain/subdomain_form', [
            'activeBox' => 'domain/overview',
            'mode'      => 'edit',
            'domain'    => $domain,
            'row'       => $row,
            'custom'    => \pmwh3\Utils\WebManager::extractCustom((string) ($row['data'] ?? '')),
            'webEnabled'=> WebManager::isEnabled(),
            'certs'     => WebManager::listCerts(),
            'sslCapable'=> WebManager::supports('ssl'),
        ]);
    }

    public function subdomain_save($fqdn = '')
    {
        if (!CustomerUtil::hasAccess('edit_subdomain')) {
            $this->flash('error', __('Domains'), __('Permission denied'));
            return;
        }
        $row = $this->findSubdomainRow(strtolower(trim($fqdn)), 'subdomains');
        if ($row === null) {
            return;
        }
        $input = new \ckvsoft\Input();
        $input->post('sub')->post('custom')->post('ssl_cert')->post('regenerate');
        $in = $input->fetch();
        $fields = [];
        if (isset($in['sub']) && trim((string) $in['sub']) !== '') {
            $fields['sub'] = (string) $in['sub'];
        }
        if (isset($in['custom'])) {
            $fields['custom'] = (string) $in['custom'];
        }
        if (isset($in['ssl_cert'])) {
            $sslCert = (string) $in['ssl_cert'];
            $known = WebManager::listCerts();
            if ($sslCert !== '' && $sslCert !== 'auto') {
                $sslCert = isset($known[$sslCert]) ? $sslCert : null;
            }
            if ($sslCert !== null) {
                $fields['ssl_cert'] = $sslCert;
            } else {
                $this->flash('error', __('Subdomains'), __('Unknown certificate'), "details/{$row['domain']}/subdomains");
                return;
            }
        }
        $fields['regenerate'] = (bool) ($in['regenerate'] ?? false);
        $result = \pmwh3\Utils\SubdomainManager::update(
                strtolower(trim($fqdn)), $fields,
                $this->subdomainOwnerCid((string) $row['domain']));
        $domain = (string) $row['domain'];
        if (!$result['ok']) {
            $this->flash('error', __('Subdomains'), (string) $result['error'],
                    "details/{$domain}/subdomains");
            return;
        }
        $this->flash('success', __('Subdomains'),
                sprintf(__('Subdomain "%s" updated'), (string) $result['fqdn']),
                "details/{$domain}/subdomains");
    }

    public function subdomain_delete($fqdn = '')
    {
        if (!CustomerUtil::hasAccess('delete_subdomain')) {
            $this->flash('error', __('Domains'), __('Permission denied'));
            return;
        }
        $fqdn = strtolower(trim($fqdn));
        if ($fqdn === '') {
            $input = new \ckvsoft\Input();
            $input->post('subdomain');
            $in = $input->fetch();
            $fqdn = strtolower(trim((string) ($in['subdomain'] ?? '')));
        }
        $row = $this->findSubdomainRow($fqdn, 'subdomains');
        if ($row === null) {
            return;
        }
        $domain = (string) $row['domain'];
        $result = \pmwh3\Utils\SubdomainManager::delete($fqdn,
                $this->subdomainOwnerCid($domain));
        if (!$result['ok']) {
            $this->flash('error', __('Subdomains'), (string) $result['error'],
                    "details/{$domain}/subdomains");
            return;
        }
        $this->flash('success', __('Subdomains'),
                sprintf(__('Subdomain "%s" deleted'), $fqdn),
                "details/{$domain}/subdomains");
    }
}
