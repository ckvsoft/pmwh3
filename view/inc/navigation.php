<?php
$customerName = !empty($this->menu['customer_name']) ? $this->menu['customer_name'] : null;
$customerGroup = !empty($this->menu['customer_group']) ? $this->menu['customer_group'] : null;
$customerDomain = !empty($this->menu['customer_current_domain']) ? $this->menu['customer_current_domain'] : null;
$menuItems = !empty($this->menu['items']) ? $this->menu['items'] : [];
?>
<?php if (!empty($customerName)) { ?><button class="openbtn" onclick="toggleNav()">☰ Menu</button><?php } ?>

<div id="top-info" class="widget pmwh3-top-info">
    <table class="widget-table">
        <tr>
            <td class="widget-key">Logged in as:</td>
            <td class="widget-value">
                <div class="pmwh3-inline-group">
                    <span class="<?= !empty($customerName) ? 'security-green' : 'security-red' ?>">
                        <?= !empty($customerName) ? htmlspecialchars((string) $customerName) : 'N/A' ?>
                    </span>

                    <?php if (!empty($this->menu['logout_icon'])): ?>
                        <button id="logout-btn" title="Logout" class="pmwh3-iconbutton">
                            <?= $this->menu['logout_icon'] ?>
                        </button>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
        <tr>
            <td class="widget-key">Group:</td>
            <td class="widget-value">
                <span class="<?= !empty($customerGroup) ? 'security-green' : 'security-red' ?>">
                    <?= !empty($customerGroup) ? htmlspecialchars((string) $customerGroup) : 'N/A' ?>
                </span>
            </td>
        </tr>
        <tr>
            <td class="widget-key">Current domain:</td>
            <td class="widget-value">
                <span id="current-domain" class="<?= !empty($customerDomain) ? 'security-green' : 'security-red' ?>">
                    <?= !empty($customerDomain) ? htmlspecialchars((string) $customerDomain) : 'N/A' ?>
                </span>
            </td>
        </tr>
    </table>
</div>

<div class="pmwh3-content">

    <ul id="sidebar" class="sidebar">
        <?php if (!empty($menuItems) && is_array($menuItems)): ?>
            <?php foreach ($menuItems as $box): ?>
                <?php if (!empty($box) && is_array($box)): ?>
                    <?php
                    $currentWidget = false;
                    foreach ($box as $item):
                        $isMain = empty($item['link']); // Hauptmenü
                        $dataId = !empty($item['active']) ? htmlspecialchars($item['active']) : '';
                        $class = !empty($item['class']) ? $item['class'] : '';
                        // Absolute links (http:// / https://) -- e.g. the
                        // Phpmyadmin entry after [PHPMYADMIN] substitution
                        // -- pass through as-is. Relative module links
                        // get the standard BASE_URI . 'pmwh3/' prefix.
                        $rawLink = (string) $item['link'];
                        if (str_starts_with($rawLink, 'http://') || str_starts_with($rawLink, 'https://')) {
                            $link = $isMain ? '' : $rawLink;
                        } else {
                            $link = $isMain ? '' : BASE_URI . 'pmwh3/' . htmlspecialchars($rawLink);
                        }
                        if ($isMain):
                            ?>
                            <li class="widget">
                                <h2><?= ($item['icon'] ?? '') ?><?= htmlspecialchars(_($item['name'])) ?></h2>
                                <ul>
                                    <?php $currentWidget = true; ?>
                                <?php else:
                                    $isExternal = !empty($item['external']);
                                    // Named target so a second click reuses
                                    // the same browser tab instead of
                                    // opening another one. We slugify the
                                    // menu name (lowercase, non-alnum -> _)
                                    // so each external entry has its own
                                    // dedicated tab.
                                    $extTarget = $isExternal
                                            ? 'pmwh3_' . preg_replace('/[^a-z0-9]+/i', '_', strtolower((string) $item['name']))
                                            : '';
                                    ?>
                                    <li>
                                        <a href="<?= str_starts_with($link, '#') ? '' : $link ?>"
                                           class="<?= $class ?>"
                                           data-domain="<?= $item['name'] ?>"
                                           data-id="<?= $link ?>"
                                           <?php if ($isExternal): ?>target="<?= htmlspecialchars($extTarget) ?>" rel="noopener"<?php endif; ?>>
                                            <?= ($item['icon'] ?? '') ?><?= htmlspecialchars(_($item['name'])) ?>
                                        </a>
                                    </li>
                                <?php
                                endif;
                            endforeach;
                            if ($currentWidget):
                                ?>
                            </ul>
                        </li>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php else: ?>
            <p>No menu items found.</p>
        <?php endif; ?>
    </ul>
</div>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.set-domain').forEach(el => {
            el.addEventListener('click', e => {
                e.preventDefault();
                const domain = el.textContent.trim();
                if (!domain)
                    return;

                fetch('<?= BASE_URI ?>pmwh3/setDomain', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    credentials: 'same-origin',
                    body: 'domain=' + encodeURIComponent(domain)
                })
                        .then(r => r.json())
                        .then(data => {
                            if (data.success) {
                                const span = document.getElementById('current-domain');
                                let newDomain = data.data.domain;
                                const isValid = typeof newDomain === "string" && newDomain.includes(".");
                                if (!isValid)
                                    newDomain = "N/A";

                                displayMessage('success', data.data.info, data.data.message + newDomain);

                                if (span) {
                                    span.textContent = newDomain;
                                    span.className = isValid ? 'security-green' : 'security-red';
                                }
                            }
                        })
                        .catch(err => console.error(err));
            });
        });
    });
    document.getElementById('logout-btn')?.addEventListener('click', () => {
        fetch('<?= BASE_URI ?>pmwh3/logout', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            credentials: 'same-origin',
            body: 'ajax=1'
        })
                .then(r => r.json())
                .then(data => {
                    if (data.success)
                        window.location.href = '<?= BASE_URI ?>pmwh3/login';
                });
    });

    /*
     * Persist sidebar scroll position across page loads.
     *
     * Without this, every navigation click resets the sidebar to
     * scrollTop=0 -- which is painful when the entry the user just
     * clicked was further down the menu.
     *
     * Strategy: write scrollTop to localStorage on every scroll
     * (debounced) and just before page unload (catches programmatic
     * navigation). Restore on DOMContentLoaded.
     */
    (function () {
        var KEY = 'pmwh3.sidebarScroll';
        var sidebar = document.getElementById('sidebar');
        if (!sidebar) return;

        try {
            var saved = parseInt(localStorage.getItem(KEY) || '0', 10);
            if (saved > 0) sidebar.scrollTop = saved;
        } catch (e) { /* localStorage may be blocked */ }

        var saveT = null;
        sidebar.addEventListener('scroll', function () {
            if (saveT) clearTimeout(saveT);
            saveT = setTimeout(function () {
                try { localStorage.setItem(KEY, String(sidebar.scrollTop)); }
                catch (e) {}
            }, 100);
        });
        window.addEventListener('beforeunload', function () {
            try { localStorage.setItem(KEY, String(sidebar.scrollTop)); }
            catch (e) {}
        });
    })();
    /*
     * Record the current URL for the re-login flow.
     *
     * After a logout/login cycle the login form's data-redirect
     * reads this back and sends the user to the page they last
     * visited (see view/login.php).
     *
     * This intentionally does NOT touch sidebar .active state --
     * the framework's own click-driven highlight (last clicked
     * sidebar entry stays highlighted) is what users expect.
     */
    (function () {
        try {
            localStorage.setItem('pmwh3.lastUrl', window.location.pathname);
        } catch (e) { /* localStorage may be blocked */ }
    })();

</script>
