<?php

class Pmwh3Menu_Helper extends \ckvsoft\mvc\Helper
{

    public function __construct($baseController)
    {
        parent::__construct($baseController);

    }

    public function getMenu($activeBox = null)
    {
        $customerName = \ckvsoft\Session::getNs('pmwh3', 'customer_name') ?: '';
        $customerGroup = \ckvsoft\Session::getNs('pmwh3', 'customer_group') ?: '';
        $customerCurrentDomain = \ckvsoft\Session::getNs('pmwh3', 'customer_current_domain') ?: '';

        $logoutIcon = $this->renderMenuIcon('menu_logout.png', 'Logout');

        $menu = [
            'customer_name' => $customerName,
            'customer_group' => $customerGroup,
            'customer_current_domain' => $customerCurrentDomain,
            'logout_icon' => $logoutIcon,
            'items' => []
        ];

        // Domain-Box zuerst
        $menu['items'][] = $this->getDomainBox($activeBox);

        // Alle Menüs in einer Abfrage laden
        $rows = $this->db->select("SELECT * FROM pmwh3.pmwh3_menu ORDER BY box, sort");

        $currentBox = null;
        $boxItems = [];
        $hasMainAccess = false;
        $pendingHeader = null;  // box-header row waiting for at
                                // least one visible child before
                                // it earns a place in $boxItems

        foreach ($rows as $r) {
            $isMain = $r['sort'] == 0;

            // Neue Box erkannt → alte Box evtl. speichern
            if ($currentBox !== $r['box']) {
                if ($currentBox !== null && $hasMainAccess) {
                    $menu['items'][] = $boxItems;
                }

                $currentBox = $r['box'];
                $boxItems = [];
                $hasMainAccess = false;
                $pendingHeader = null;
            }

            // Hard-hide flag: hide='Y' means the row is hidden
            // regardless of permission. Used by the Menu CRUD UI
            // to soft-hide rows without dropping them. If this
            // is a box-header row (sort=0) and it's hidden, the
            // whole box drops out because we never collect any
            // children for it.
            if (((string) ($r['hide'] ?? 'N')) === 'Y') {
                if ($isMain) {
                    // Force the box to be empty for this user
                    // by skipping every subsequent row of the
                    // same box -- $pendingHeader stays null and
                    // $hasMainAccess never flips to true.
                    $pendingHeader = '__HIDDEN__';
                }
                continue;
            }
            // The whole box was muted by its header's hide flag.
            // Skip every row inside it.
            if ($pendingHeader === '__HIDDEN__') {
                continue;
            }

            // Box-header rows (sort=0) are presentational labels --
            // we don't permission-check them, we just buffer them
            // and emit them later if any child of the same box ends
            // up visible. This way a customer who is allowed to see
            // any single item in a box automatically gets the box's
            // header without needing a separate "view_menu_<box>"
            // permission. Setting the box-header row's hide='Y' is
            // the way to suppress the whole box deliberately.
            if ($isMain) {
                $pendingHeader = [
                    'name' => __($r['name']),
                    'link' => '',
                    'external' => false,
                    'box' => $r['box'],
                    'sort' => $r['sort'],
                    'icon' => $this->renderMenuIcon((string) ($r['icon'] ?? ''), (string) $r['name']),
                    'css_class' => 'menu-item',
                    'hidden' => false,
                    'active' => $r['box'] == $activeBox
                ];
                continue;
            }

            // Zugriff prüfen (only for child rows now)
            if (\pmwh3\Utils\CustomerUtil::hasAccess($r['permission'])) {
                // Settings-driven menu visibility (see visibilityRules
                // below). Hides rows whose option is currently off.
                if ($this->isHiddenBySettings($r)) {
                    continue;
                }

                $rawLink = (string) ($r['link'] ?? '');
                $isExternal = false;
                // Substitute the [PHPMYADMIN] placeholder (used by the
                // databases Phpmyadmin menu row) with the configured
                // URL. The setting must be a full URL with scheme
                // (http:// or https://) -- anything else drops the
                // row entirely instead of rendering a broken link.
                //
                // Legacy DB rows store the link as "http://[PHPMYADMIN]"
                // -- the fixed scheme is part of the value. Strip any
                // such scheme prefix before substituting so we don't
                // end up with "http://https://host" when the setting
                // brings its own scheme.
                if (str_contains($rawLink, '[PHPMYADMIN]')) {
                    $pma = trim((string) \pmwh3\Config\LazyConfig::get('PHPMYADMIN_URL', ''));
                    if ($pma === ''
                            || !preg_match('#^https?://[^\s]+#i', $pma)) {
                        continue;
                    }
                    // Drop any leading "http://" / "https://" baked into
                    // the DB row before the placeholder.
                    $rawLink = preg_replace('#^https?://#i', '', $rawLink);
                    $rawLink = str_replace('[PHPMYADMIN]', $pma, $rawLink);
                    $isExternal = true;
                }
                // Track external links so the view can open them in
                // a new tab (target=_blank) instead of treating them
                // as in-module navigation.
                if (str_starts_with($rawLink, 'http://') || str_starts_with($rawLink, 'https://')) {
                    $isExternal = true;
                }

                // First visible child: promote the pending header
                // into $boxItems and flip $hasMainAccess so the
                // box gets emitted at end-of-box.
                if ($pendingHeader !== null && is_array($pendingHeader)) {
                    $boxItems[] = $pendingHeader;
                    $pendingHeader = '__USED__';
                    $hasMainAccess = true;
                }

                $boxItems[] = [
                    'name' => __($r['name']),
                    'link' => ltrim($rawLink),
                    'external' => $isExternal,
                    'box' => $r['box'],
                    'sort' => $r['sort'],
                    'icon' => $this->renderMenuIcon((string) ($r['icon'] ?? ''), (string) $r['name']),
                    'css_class' => 'submenu-item',
                    'hidden' => ($r['box'] != $activeBox),
                    'active' => $r['box'] == $activeBox
                ];
            }
        }

        // letzte Box hinzufügen, falls Hauptmenü erlaubt
        if ($hasMainAccess) {
            $menu['items'][] = $boxItems;
        }

        return $menu;
    }

    /**
     * Render one menu icon. Resolves the file under the active
     * ICON_THEME folder; falls back to the icons/ root for legacy
     * paths (e.g. menu_logout.png). Returns an <img> tag with the
     * icon embedded as base64 -- avoids extra HTTP requests, same
     * pattern as the existing logoutIcon code.
     *
     * Empty $iconName returns '' so the helper doesn't have to
     * worry about menu rows whose icon column is blank.
     */
    private function renderMenuIcon(string $iconName, string $alt = ''): string
    {
        if ($iconName === '') {
            return '';
        }
        $iconsDir = __DIR__ . '/../view/images/icons';
        $theme    = (string) \pmwh3\Config\LazyConfig::get('ICON_THEME', '16x16-CC');

        // Resolve path: theme subfolder first, then flat icons/.
        $candidates = [
            $iconsDir . '/' . $theme . '/' . $iconName,
            $iconsDir . '/' . $iconName,
        ];
        $path = null;
        foreach ($candidates as $c) {
            if (is_file($c)) {
                $path = $c;
                break;
            }
        }
        if ($path === null) {
            return '';
        }
        try {
            $image = new \ckvsoft\Image($path);
            $safeAlt = htmlspecialchars($alt, ENT_QUOTES, 'UTF-8');
            return '<img src="' . $image->toBase64()
                    . '" alt="' . $safeAlt
                    . '" class="pmwh3-menu-icon">';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /*
      public function getMenu($activeBox = null)
      {
      $customerName = \ckvsoft\Session::getNs('pmwh3', 'customer_name') ?: '';
      $customerGroup = \ckvsoft\Session::getNs('pmwh3', 'customer_group') ?: '';
      $customerCurrentDomain = \ckvsoft\Session::getNs('pmwh3', 'customer_current_domain') ?: '';

      $logoutIcon = '';
      if ($customerName !== '') {
      $image = new \ckvsoft\Image(__DIR__ . '/../view/images/icons/menu_logout.png');
      $logoutIcon = '<img src="' . $image->toBase64() . '" alt="Logout" style="width:16px;height:16px; vertical-align:middle;">';
      }

      $menu = [
      'customer_name' => $customerName,
      'customer_group' => $customerGroup,
      'customer_current_domain' => $customerCurrentDomain,
      'logout_icon' => $logoutIcon,
      'items' => [] // Hier kommt später das Menü
      ];

      // Domain-Box zuerst
      $menu['items'][] = $this->getDomainBox($activeBox);

      $headers = $this->db->select("SELECT * FROM pmwh3_menu WHERE sort = 0 ORDER BY box, sort");

      foreach ($headers as $r) {
      $boxItems = $this->db->select("SELECT * FROM pmwh3_menu WHERE box = :box ORDER BY sort", ["box" => $r['box']]);

      $box = [];
      foreach ($boxItems as $r2) {
      if (\pmwh3\Utils\CustomerUtil::hasAccess($r2['permission'])) {
      $name = __($r2['name']);
      $isMain = $r2['sort'] == 0;

      $box[] = [
      'name' => $name,
      'link' => $isMain ? '' : ltrim($r2['link']),
      'box' => $r2['box'],
      'sort' => $r2['sort'],
      'css_class' => $isMain ? 'menu-item' : 'submenu-item',
      'hidden' => (!$isMain && $r2['box'] != $activeBox),
      'active' => $r2['box'] == $activeBox
      ];
      }
      }

      $menu['items'][] = $box;
      }

      return $menu;
      }
     * 
     */

    /**
     * Map of pmwh3 setting key -> required value, with predicates that
     * test pmwh3_menu rows for a match. A row is hidden if it matches
     * the predicate AND the setting's current value is NOT the
     * required one.
     *
     * Add an entry here when a new option should toggle menu rows on
     * or off. Keep the predicate broad (match permission AND link
     * substrings) so renaming a permission key or a link doesn't
     * silently disable the gate.
     *
     * @return list<array{
     *     setting:string,
     *     required:string,
     *     match: callable(array): bool
     * }>
     */
    private function visibilityRules(): array
    {
        return [
            [
                // Hide the message-system menu rows when USE_MESSAGE_SYSTEM is off.
                'setting'  => 'USE_MESSAGE_SYSTEM',
                'required' => 'Y',
                'match'    => function (array $r): bool {
                    $perm = (string) ($r['permission'] ?? '');
                    $link = (string) ($r['link']       ?? '');
                    return $perm === 'view_menu_general_message'
                            || $perm === 'view_menu_general_messages'
                            || str_contains($link, 'general/message')
                            || str_contains($link, 'general/messages');
                },
            ],
            [
                // Hide news menu rows when SHOW_NEWS is off.
                'setting'  => 'SHOW_NEWS',
                'required' => 'Y',
                'match'    => function (array $r): bool {
                    $perm = (string) ($r['permission'] ?? '');
                    $link = (string) ($r['link']       ?? '');
                    return $perm === 'view_menu_tools_news'
                            || str_contains($link, 'tools/news')
                            || str_contains($link, '/news');
                },
            ],
            [
                // Hide DB-advanced menu rows when DB_ADVANCED is off.
                'setting'  => 'DB_ADVANCED',
                'required' => 'Y',
                'match'    => function (array $r): bool {
                    $perm = (string) ($r['permission'] ?? '');
                    $link = (string) ($r['link']       ?? '');
                    return str_contains($perm, 'advanced')
                            || str_contains($link, 'databases/advanced');
                },
            ],
        ];
    }

    /**
     * Returns true when this menu row is hidden by some option being
     * disabled. Walks visibilityRules() and short-circuits on first
     * match-and-disabled.
     */
    private function isHiddenBySettings(array $row): bool
    {
        foreach ($this->visibilityRules() as $rule) {
            if (!$rule['match']($row)) {
                continue;
            }
            $current = \pmwh3\Config\LazyConfig::get($rule['setting'], $rule['required']);
            if ((string) $current !== (string) $rule['required']) {
                return true;
            }
        }
        return false;
    }

    public function getDomainBox(?string $activeBox = null): array
    {
        $customerId = \ckvsoft\Session::getNs('pmwh3', 'customer_id');
        if ($customerId === false)
            return [];

        $domainsRaw = \pmwh3\Utils\CustomerUtil::getDomainsByCustomerId($customerId);
        // $domainsRaw = [['idn' => 'ckvsoft.at', 'domain' => 'ckvsoft.at'], ...]
        // 3️⃣ Hauptmenüeintrag für Domain-Wechsler
        $domainBox = [
            [
                'name' => 'Change Domain',
                'link' => '', // Hauptmenü
                'box' => 'domain',
                'sort' => 0,
                'css_class' => 'menu-item',
                'hidden' => false,
                'active' => ($activeBox === 'domain')
            ]
        ];

        $sort = 1;
        $domainBox[] = [
            'name' => __('None'),
            'link' => '#' . $sort++,
            'box' => 'domain',
            'sort' => $sort,
            'css_class' => 'submenu-item',
            'hidden' => false,
            'active' => false,
            'class' => 'set-domain'
        ];

        foreach ($domainsRaw as $d) {
            $domainBox[] = [
                'name' => $d['domain'],
                'link' => '#' . $sort++,
                'box' => 'domain',
                'sort' => $sort,
                'css_class' => 'submenu-item',
                'hidden' => false,
                'active' => false,
                'class' => 'set-domain'
            ];
        }

        return $domainBox;
    }
}
