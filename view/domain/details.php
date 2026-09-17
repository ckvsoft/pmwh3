<?php
$domain    = (string) ($this->data['domain']    ?? '');
$row       = (array)  ($this->data['row']       ?? []);
$tab       = (string) ($this->data['tab']       ?? 'overview');
$tabData   = (array)  ($this->data['tabData']   ?? []);
$edit_perm = (bool)   ($this->data['edit_perm'] ?? false);

$tabs = [
    'overview'   => __('Overview'),
    'dns'        => __('DNS'),
    'dnssec'     => __('DNSSEC'),
    'subdomains' => __('Subdomains'),
    'email'      => __('Email'),
    'apache'     => __('Apache'),
    'activity'   => __('Activity'),
];

$tabUrl = function (string $t) use ($domain): string {
    return BASE_URI . 'pmwh3/domain/details/' . urlencode($domain) . '/' . $t;
};
?>
<div class="pmwh3-content">
    <div class="widget">
        <div class="widget-header">
            <h3><?php echo sprintf(__('Domain details: %s'), htmlspecialchars($domain)); ?></h3>
            <a href="<?php echo BASE_URI; ?>pmwh3/domain/overview"
               class="button small-action cancel"><?php echo __('Back'); ?></a>
        </div>
        <div class="widget-body">

            <nav class="tab-bar">
                <?php foreach ($tabs as $key => $label): ?>
                    <a href="<?php echo $tabUrl($key); ?>"
                       class="tab-link<?php echo $tab === $key ? ' active' : ''; ?>"><?php echo $label; ?></a>
                <?php endforeach; ?>
            </nav>

            <div class="tab-content">

            <?php if ($tab === 'overview'): ?>

                <table class="widget-table">
                    <tr><td><?php echo __('Domain'); ?></td>
                        <td><strong><?php echo htmlspecialchars($domain); ?></strong></td></tr>
                    <tr><td><?php echo __('Customer'); ?></td>
                        <td><?php echo htmlspecialchars((string) ($row['customer'] ?? '')); ?>
                            <small>#<?php echo (int) ($row['cid'] ?? 0); ?></small></td></tr>
                    <tr><td><?php echo __('IP Address'); ?></td>
                        <td><?php echo htmlspecialchars((string) ($row['ip'] ?? '')); ?></td></tr>
                    <tr><td><?php echo __('Path'); ?></td>
                        <td><?php echo htmlspecialchars((string) ($row['path'] ?? '')); ?></td></tr>
                    <tr><td><?php echo __('Services'); ?></td>
                        <td><?php echo htmlspecialchars((string) ($row['services'] ?? '')); ?></td></tr>
                    <tr><td><?php echo __('Registration'); ?></td>
                        <td><?php echo htmlspecialchars((string) ($row['reg_start'] ?? '')); ?>
                            &mdash; <?php echo htmlspecialchars((string) ($row['reg_end'] ?? '')); ?></td></tr>
                    <tr><td><?php echo __('Hosting'); ?></td>
                        <td><?php echo htmlspecialchars((string) ($row['host_start'] ?? '')); ?>
                            &mdash; <?php echo htmlspecialchars((string) ($row['host_end'] ?? '')); ?></td></tr>
                </table>

            <?php elseif ($tab === 'dns'): ?>

                <?php
                $records    = (array) ($tabData['records'] ?? []);
                $soa        = (array) ($tabData['soa']     ?? []);
                $zoneExists = (bool)  ($tabData['zoneExists'] ?? false);
                $dns_edit   = (bool)  ($tabData['edit_perm']  ?? false);
                ?>

                <?php if (!$zoneExists): ?>
                    <p><em><?php echo __('No DNS zone configured for this domain.'); ?></em></p>

                <?php else: ?>

                    <?php if (!empty($soa)): ?>
                        <div class="widget-subheader"><?php echo __('SOA'); ?></div>
                        <table class="widget-table">
                            <tr><td><?php echo __('Primary NS'); ?></td>
                                <td><?php echo htmlspecialchars((string) ($soa['primary'] ?? '')); ?></td></tr>
                            <tr><td><?php echo __('Admin'); ?></td>
                                <td><?php echo htmlspecialchars((string) ($soa['admin'] ?? '')); ?></td></tr>
                            <tr><td><?php echo __('Serial'); ?></td>
                                <td><?php echo (int) ($soa['serial'] ?? 0); ?></td></tr>
                            <tr><td><?php echo __('Refresh / Retry / Expire / NegTTL'); ?></td>
                                <td><?php echo (int) ($soa['refresh'] ?? 0); ?>
                                    / <?php echo (int) ($soa['retry'] ?? 0); ?>
                                    / <?php echo (int) ($soa['expire'] ?? 0); ?>
                                    / <?php echo (int) ($soa['negttl'] ?? 0); ?></td></tr>
                        </table>
                    <?php endif; ?>

                    <div class="widget-subheader"><?php echo __('Records'); ?></div>
                    <div class="paginated" data-per-page="25">
                    <table class="widget-table dns-records-table">
                        <thead>
                            <tr>
                                <th><?php echo __('Type'); ?></th>
                                <th><?php echo __('Name'); ?></th>
                                <th><?php echo __('Content'); ?></th>
                                <th><?php echo __('TTL'); ?></th>
                                <th><?php echo __('Prio'); ?></th>
                                <?php if ($dns_edit): ?><th><?php echo __('Actions'); ?></th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($records as $rec):
                                $rid = (int) ($rec['id'] ?? 0);
                            ?>
                                <?php if ($dns_edit): ?>
                                    <tr>
                                        <form method="post"
                                              action="<?php echo BASE_URI; ?>pmwh3/domain/dns_save/<?php echo urlencode($domain); ?>/<?php echo $rid; ?>"
                                              class="inline-row"
                                              data-confirm="<?php echo __('Save this record?'); ?>"
                                              data-confirm-type="change">
                                            <td><input type="text" name="type"
                                                       value="<?php echo htmlspecialchars((string) ($rec['type'] ?? '')); ?>"></td>
                                            <td><input type="text" name="name"
                                                       value="<?php echo htmlspecialchars((string) ($rec['name'] ?? '')); ?>"></td>
                                            <td><input type="text" name="content"
                                                       value="<?php echo htmlspecialchars((string) ($rec['content'] ?? '')); ?>"></td>
                                            <td><input type="number" name="ttl"
                                                       value="<?php echo (int) ($rec['ttl'] ?? 3600); ?>"></td>
                                            <td><input type="number" name="prio"
                                                       value="<?php echo (int) ($rec['prio'] ?? 0); ?>"></td>
                                            <td>
                                                <button type="submit" class="button small-action save"><?php echo __('Save'); ?></button>
                                        </form>
                                                <form method="post"
                                                      action="<?php echo BASE_URI; ?>pmwh3/domain/dns_delete/<?php echo urlencode($domain); ?>/<?php echo $rid; ?>"
                                                      class="inline-form"
                                                      data-confirm="<?php echo __('Delete this record?'); ?>"
                                                      data-confirm-type="delete">
                                                    <button type="submit" class="button small-action delete"><?php echo __('Delete'); ?></button>
                                                </form>
                                            </td>
                                    </tr>
                                <?php else: ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars((string) ($rec['type']    ?? '')); ?></td>
                                        <td><?php echo htmlspecialchars((string) ($rec['name']    ?? '')); ?></td>
                                        <td><?php echo htmlspecialchars((string) ($rec['content'] ?? '')); ?></td>
                                        <td><?php echo (int) ($rec['ttl']  ?? 0); ?></td>
                                        <td><?php echo (int) ($rec['prio'] ?? 0); ?></td>
                                    </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div><!-- /paginated dns-records -->

                    <?php if ($dns_edit): ?>
                        <div class="widget-subheader"><?php echo __('Add record'); ?></div>
                        <form method="post"
                              action="<?php echo BASE_URI; ?>pmwh3/domain/dns_add/<?php echo urlencode($domain); ?>"
                              data-confirm="<?php echo __('Add this record?'); ?>"
                              data-confirm-type="change">
                            <table class="widget-table">
                                <tr>
                                    <td><?php echo __('Type'); ?></td>
                                    <td>
                                        <select name="type">
                                            <option>A</option>
                                            <option>AAAA</option>
                                            <option>CNAME</option>
                                            <option>MX</option>
                                            <option>TXT</option>
                                            <option>NS</option>
                                            <option>SRV</option>
                                            <option>CAA</option>
                                        </select>
                                    </td></tr>
                                <tr><td><?php echo __('Name'); ?></td>
                                    <td><input type="text" name="name" placeholder="@ for apex, or 'www' or 'sub.example.com'"></td></tr>
                                <tr><td><?php echo __('Content'); ?></td>
                                    <td><input type="text" name="content" required size="40"></td></tr>
                                <tr><td><?php echo __('TTL'); ?></td>
                                    <td><input type="number" name="ttl" value="3600"></td></tr>
                                <tr><td><?php echo __('Priority (MX/SRV)'); ?></td>
                                    <td><input type="number" name="prio" value="0"></td></tr>
                            </table>
                            <div class="form-actions">
                                <button type="submit" class="button small-action create"><?php echo __('Add record'); ?></button>
                            </div>
                        </form>
                    <?php endif; ?>

                <?php endif; ?>

            <?php elseif ($tab === 'dnssec'): ?>

                <?php
                $available   = (bool)  ($tabData['available']   ?? false);
                $status      = (array) ($tabData['status']      ?? []);
                $keys        = (array) ($tabData['keys']        ?? []);
                $metadata    = (array) ($tabData['metadata']    ?? []);
                $manage_perm = (bool)  ($tabData['manage_perm'] ?? false);
                $apiSet = (bool)  ($tabData['apiSet']    ?? false);
                ?>

                <?php if (!$available): ?>
                    <p><em><?php echo __('DNSSEC schema not available on this DNS adapter (cryptokeys / domainmetadata tables missing).'); ?></em></p>

                <?php else: ?>

                    <div class="widget-subheader"><?php echo __('Status'); ?></div>
                    <table class="widget-table">
                        <tr><td><?php echo __('Signed'); ?></td>
                            <td><?php echo !empty($status['enabled'])
                                    ? '<strong class="pmwh3-success-strong">' . __('Yes') . '</strong>'
                                    : __('No'); ?></td></tr>
                        <tr><td><?php echo __('NSEC mode'); ?></td>
                            <td><?php echo !empty($status['nsec3']) ? 'NSEC3' : 'NSEC'; ?></td></tr>
                        <tr><td><?php echo __('Pre-signed'); ?></td>
                            <td><?php echo !empty($status['presigned']) ? __('Yes') : __('No'); ?></td></tr>
                        <tr><td><?php echo __('Keys'); ?></td>
                            <td><?php echo (int) ($status['active'] ?? 0); ?>
                                / <?php echo (int) ($status['keys'] ?? 0); ?>
                                <small><?php echo __('(active / total)'); ?></small></td></tr>
                    </table>

                    <?php if ($manage_perm): ?>
                        <div class="form-actions">
                            <?php if (empty($status['enabled'])): ?>
                                <form method="post"
                                      action="<?php echo BASE_URI; ?>pmwh3/domain/dnssec_secure/<?php echo urlencode($domain); ?>"
                                      class="inline-form"
                                      data-confirm="<?php echo __('Sign this zone? Generates a default ECDSA-256 CSK.'); ?>"
                                      data-confirm-type="change">
                                    <button type="submit" class="button small-action create"
                                            <?php echo $apiSet ? '' : 'disabled title="' . __('PDNS_API_URL not set in Options') . '"'; ?>>
                                        <?php echo __('Sign zone'); ?>
                                    </button>
                                </form>
                            <?php else: ?>
                                <form method="post"
                                      action="<?php echo BASE_URI; ?>pmwh3/domain/dnssec_disable/<?php echo urlencode($domain); ?>"
                                      class="inline-form"
                                      data-confirm="<?php echo __('Disable DNSSEC and remove all keys? Resolvers go bogus until the DS at your registrar is removed.'); ?>"
                                      data-confirm-type="change">
                                    <button type="submit" class="button small-action delete"
                                            <?php echo $apiSet ? '' : 'disabled title="' . __('PDNS_API_URL not set in Options') . '"'; ?>>
                                        <?php echo __('Disable DNSSEC'); ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                            <?php if (!$apiSet): ?>
                                <small><em><?php echo __('Set PDNS_API_URL + PDNS_API_KEY in Options to enable signing.'); ?></em></small>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <div class="widget-subheader"><?php echo __('Keys'); ?></div>
                    <?php if (empty($keys)): ?>
                        <p><em><?php echo __('No keys.'); ?></em></p>
                    <?php else: ?>
                        <table class="widget-table">
                            <thead><tr>
                                <th><?php echo __('ID'); ?></th>
                                <th><?php echo __('Role'); ?></th>
                                <th><?php echo __('Algorithm'); ?></th>
                                <th><?php echo __('Active'); ?></th>
                                <th><?php echo __('Published'); ?></th>
                                <?php if ($manage_perm): ?><th><?php echo __('Actions'); ?></th><?php endif; ?>
                            </tr></thead>
                            <tbody>
                                <?php foreach ($keys as $k):
                                    $kid = (int) ($k['id'] ?? 0);
                                    $isActive = !empty($k['active']);
                                    $role     = strtoupper((string) ($k['role'] ?? ''));
                                    $isSigning = in_array($role, ['KSK', 'CSK'], true);
                                    $algo     = (string) ($k['algorithm'] ?? '');
                                    $bits     = $k['bits'] ?? null;
                                    $algoText = $algo === '' ? '' : ($algo . ($bits ? " ({$bits})" : ''));
                                    $dnskey   = (string) ($k['dnskey'] ?? '');
                                    $dsList   = (array)  ($k['ds']     ?? []);
                                ?>
                                    <tr>
                                        <td><?php echo $kid; ?></td>
                                        <td><strong><?php echo htmlspecialchars($role); ?></strong></td>
                                        <td><small><?php echo htmlspecialchars($algoText); ?></small></td>
                                        <td><?php echo $isActive ? __('Yes') : __('No'); ?></td>
                                        <td><?php echo !empty($k['published']) ? __('Yes') : __('No'); ?></td>
                                        <?php if ($manage_perm): ?>
                                            <td>
                                                <form method="post"
                                                      action="<?php echo BASE_URI; ?>pmwh3/domain/dnssec_key_toggle/<?php echo urlencode($domain); ?>/<?php echo $kid; ?>"
                                                      class="inline-form"
                                                      data-confirm="<?php echo $isActive ? __('Deactivate this key?') : __('Activate this key?'); ?>"
                                                      data-confirm-type="change">
                                                    <input type="hidden" name="active" value="<?php echo $isActive ? '0' : '1'; ?>">
                                                    <button type="submit" class="button small-action cancel">
                                                        <?php echo $isActive ? __('Deactivate') : __('Activate'); ?>
                                                    </button>
                                                </form>
                                                <form method="post"
                                                      action="<?php echo BASE_URI; ?>pmwh3/domain/dnssec_key_delete/<?php echo urlencode($domain); ?>/<?php echo $kid; ?>"
                                                      class="inline-form"
                                                      data-confirm="<?php echo __('Delete this key? If it is the only KSK and a DS is published, resolvers go bogus.'); ?>"
                                                      data-confirm-type="delete">
                                                    <button type="submit" class="button small-action delete"><?php echo __('Delete'); ?></button>
                                                </form>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                    <?php if ($isSigning && ($dnskey !== '' || !empty($dsList))): ?>
                                        <tr class="dnssec-keydetail">
                                            <td colspan="<?php echo $manage_perm ? 6 : 5; ?>">
                                                <?php if ($dnskey !== ''): ?>
                                                    <div class="dnssec-record">
                                                        <span class="dnssec-record-label"><?php echo __('DNSKEY'); ?></span>
                                                        <code class="dnssec-record-value"><?php echo htmlspecialchars($dnskey); ?></code>
                                                        <button type="button" class="button small-action edit dnssec-copy"
                                                                data-copy="<?php echo htmlspecialchars($dnskey, ENT_QUOTES); ?>">
                                                            <?php echo __('Copy'); ?>
                                                        </button>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if (!empty($dsList)): ?>
                                                    <div class="dnssec-ds-block">
                                                        <span class="dnssec-record-label"><?php echo __('DS records'); ?></span>
                                                        <small><em><?php echo __('Submit one of these to your registrar (e.g. nic.at). SHA-256 (digest type 2) is the usual choice.'); ?></em></small>
                                                        <?php foreach ($dsList as $ds):
                                                            // ds string: "<keytag> <algo> <digesttype> <digest>"
                                                            $parts = preg_split('/\s+/', trim((string) $ds));
                                                            $digestType = $parts[2] ?? '?';
                                                            $digestLabel = match ($digestType) {
                                                                '1' => 'SHA-1',
                                                                '2' => 'SHA-256',
                                                                '4' => 'SHA-384',
                                                                default => "type {$digestType}",
                                                            };
                                                        ?>
                                                            <div class="dnssec-record">
                                                                <span class="dnssec-record-label dnssec-ds-tag"><?php echo htmlspecialchars($digestLabel); ?></span>
                                                                <code class="dnssec-record-value"><?php echo htmlspecialchars((string) $ds); ?></code>
                                                                <button type="button" class="button small-action edit dnssec-copy"
                                                                        data-copy="<?php echo htmlspecialchars((string) $ds, ENT_QUOTES); ?>">
                                                                    <?php echo __('Copy'); ?>
                                                                </button>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php elseif ($isSigning && !$apiSet): ?>
                                        <tr class="dnssec-keydetail">
                                            <td colspan="<?php echo $manage_perm ? 6 : 5; ?>">
                                                <small><em><?php echo __('Configure PDNS_API_URL in Options to view DNSKEY/DS records for submission to the registrar.'); ?></em></small>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <script>
                            (function () {
                                document.querySelectorAll('.dnssec-copy').forEach(function (btn) {
                                    btn.addEventListener('click', function () {
                                        var t = btn.getAttribute('data-copy') || '';
                                        if (!t) return;
                                        if (navigator.clipboard && navigator.clipboard.writeText) {
                                            navigator.clipboard.writeText(t).then(function () {
                                                var orig = btn.textContent;
                                                btn.textContent = '<?php echo __('Copied'); ?>';
                                                setTimeout(function () { btn.textContent = orig; }, 1500);
                                            });
                                        }
                                    });
                                });
                            })();
                        </script>
                    <?php endif; ?>

                    <div class="widget-subheader"><?php echo __('Domain metadata'); ?></div>
                    <?php if (empty($metadata)): ?>
                        <p><em><?php echo __('No metadata.'); ?></em></p>
                    <?php else: ?>
                        <table class="widget-table">
                            <thead><tr>
                                <th><?php echo __('Kind'); ?></th>
                                <th><?php echo __('Content'); ?></th>
                            </tr></thead>
                            <tbody>
                                <?php foreach ($metadata as $m): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars((string) ($m['kind']    ?? '')); ?></td>
                                        <td><small><?php echo htmlspecialchars((string) ($m['content'] ?? '')); ?></small></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>

                <?php endif; ?>

            <?php elseif ($tab === 'subdomains'): ?>

                <?php
                $subs        = (array) ($tabData['subdomains'] ?? []);
                $quota       = $tabData['quota'] ?? null;
                $createPerm  = !empty($tabData['create_perm']);
                $editPerm    = !empty($tabData['edit_perm']);
                $deletePerm  = !empty($tabData['delete_perm']);
                $webEnabled  = !empty($tabData['webEnabled']);
                ?>

                <?php if (!$webEnabled): ?>
                    <p><em><?php echo __('No web adapter configured -- subdomain rows cannot be managed.'); ?></em></p>
                <?php endif; ?>

                <?php
                // The counting table's `used` value is migration-era and
                // drifts (pmwh3 only increments on its own CRUD) -- show
                // the REAL count from pmwh3_web_subdomains instead.
                $realSubs = count($subs);
                ?>
                <div class="widget-subheader"><?php echo __('Subdomains'); ?></div>
                <p>
                    <?php echo sprintf(__('%d subdomain(s) in this domain tree.'), $realSubs); ?>
                    <?php if (is_array($quota) && $quota['max'] !== -1 && $quota['max'] !== 0): ?>
                        <?php echo sprintf(__('Quota: %d per customer.'), $quota['max']); ?>
                    <?php elseif (is_array($quota) && $quota['max'] === 0): ?>
                        <em><?php echo __('(Subdomains are not part of this customer\'s package.)'); ?></em>
                    <?php else: ?>
                        <em><?php echo __('(no per-customer limit)'); ?></em>
                    <?php endif; ?>
                </p>

                <?php $wpUsed = $tabData['webspaceUsed'] ?? null;
                      $wpQuota = $tabData['webspaceQuota'] ?? null; ?>
                <div class="widget-subheader"><?php echo __('Webspace'); ?></div>
                <p>
                    <?php
                    $usedStr = $wpUsed === null
                            ? __('not measurable (backend-managed FS)')
                            : \pmwh3\Utils\SizeConverter::bytesToHumanReadable((int) $wpUsed);
                    echo sprintf(__('Used on disk (live): %s. Assigned: %s.'),
                            $usedStr,
                            is_array($wpQuota) && $wpQuota['max'] !== 0
                                    ? ($wpQuota['max'] === -1 ? __('unlimited') : sprintf(__('%d MB'), $wpQuota['max']))
                                    : __('not set'));
                    ?>
                </p>
                <p><small><em><?php echo __('Traffic figures live in the Traffic view (pmwh3_traffic, fed by the log collector).'); ?></em></small></p>

                <?php if ($createPerm && $webEnabled): ?>
                    <p>
                        <a href="<?php echo BASE_URI; ?>pmwh3/domain/subdomain_new/<?php echo urlencode($domain); ?>"
                           class="button small-action create"><?php echo __('New subdomain'); ?></a>
                    </p>
                <?php endif; ?>

                <?php if (empty($subs)): ?>
                    <p><em><?php echo __('No subdomains.'); ?></em></p>
                <?php else: ?>
                    <table class="widget-table">
                        <thead>
                            <tr><th><?php echo __('Subdomain'); ?></th>
                                <th><?php echo __('Path / IP'); ?></th>
                                <th><?php echo __('Customer'); ?></th>
                                <th><?php echo __('Alias of'); ?></th>
                                <th><?php echo __('VHost'); ?></th>
                                <th><?php echo __('Actions'); ?></th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($subs as $s):
                                $sFqdn  = (string) ($s['subdomain'] ?? '');
                                $sPath  = (string) ($s['path'] ?? '');
                                $isIp   = filter_var($sPath, FILTER_VALIDATE_IP) !== false;
                                $sAlias = (string) ($s['alias_of'] ?? '');
                            ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($sFqdn); ?></td>
                                    <td><?php echo htmlspecialchars($isIp ? $sPath . ' ' . __('(IP forwarding)') : $sPath); ?></td>
                                    <td><?php echo htmlspecialchars((string) ($s['customer'] ?? '')); ?></td>
                                    <td><?php echo $sAlias === '' ? '—' : htmlspecialchars($sAlias); ?></td>
                                    <td><?php echo ($s['data'] ?? '') !== '' ? __('yes') : __('no'); ?></td>
                                    <td>
                                        <?php if ($editPerm): ?>
                                            <a href="<?php echo BASE_URI; ?>pmwh3/domain/subdomain_edit/<?php echo urlencode($sFqdn); ?>"
                                               class="button small-action edit"><?php echo __('Change'); ?></a>
                                        <?php endif; ?>
                                        <?php if ($deletePerm): ?>
                                            <form action="<?php echo BASE_URI; ?>pmwh3/domain/subdomain_delete" method="post" class="inline-form"
                                                  data-confirm="<?php echo sprintf(__('Delete subdomain %s?'), htmlspecialchars($sFqdn)); ?>"
                                                  data-confirm-type="delete">
                                                <input type="hidden" name="subdomain" value="<?php echo htmlspecialchars($sFqdn); ?>">
                                                <button class="button small-action delete"><?php echo __('Delete'); ?></button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p><small><em><?php echo __('Subdomain changes become active with the next Apache reload.'); ?></em></small></p>
                <?php endif; ?>

            <?php elseif ($tab === 'email'): ?>

                <?php
                $mailboxes = (array) ($tabData['mailboxes'] ?? []);
                $forwards  = (array) ($tabData['forwards']  ?? []);
                ?>
                <p>
                    <a href="<?php echo BASE_URI; ?>pmwh3/email/pick/<?php echo urlencode($domain); ?>/email"
                       class="button small-action edit"><?php echo __('Manage mailboxes'); ?></a>
                    <a href="<?php echo BASE_URI; ?>pmwh3/email/pick/<?php echo urlencode($domain); ?>/forward"
                       class="button small-action edit"><?php echo __('Manage forwards'); ?></a>
                    <a href="<?php echo BASE_URI; ?>pmwh3/email/pick/<?php echo urlencode($domain); ?>/catchall"
                       class="button small-action edit"><?php echo __('Manage catchall'); ?></a>
                </p>

                <div class="widget-subheader"><?php echo __('Mailboxes'); ?></div>
                <?php
                $mailUsed = 0;
                $mailAssigned = 0;
                foreach ((array) ($tabData['mailboxes'] ?? []) as $m) {
                    $mailUsed += max(0, (int) ($m['quota_used'] ?? 0));
                    $mailAssigned += max(0, (int) ($m['quota_bytes'] ?? 0));
                }
                ?>
                <?php if (!empty($mailboxes)): ?>
                    <p>
                        <?php echo sprintf(__('Server-side usage: %1$s of %2$s assigned.'),
                            \pmwh3\Utils\SizeConverter::bytesToHumanReadable($mailUsed),
                            $mailAssigned > 0
                                    ? \pmwh3\Utils\SizeConverter::bytesToHumanReadable($mailAssigned)
                                    : __('unlimited / not set')); ?>
                    </p>
                <?php endif; ?>
                <?php if (empty($mailboxes)): ?>
                    <p><em><?php echo __('No mailboxes.'); ?></em></p>
                <?php else: ?>
                    <div class="paginated" data-per-page="15">
                    <table class="widget-table">
                        <thead><tr>
                            <th><?php echo __('Email'); ?></th>
                            <th><?php echo __('Quota'); ?></th>
                            <th><?php echo __('Used'); ?></th>
                            <th><?php echo __('Active'); ?></th>
                        </tr></thead>
                        <tbody>
                            <?php foreach ($mailboxes as $m):
                                $email = (string) ($m['email'] ?? '');
                            ?>
                                <tr>
                                    <td>
                                        <a href="<?php echo BASE_URI; ?>pmwh3/email/change_email/<?php echo urlencode($email); ?>"><?php
                                            echo htmlspecialchars($email);
                                        ?></a>
                                    </td>
                                    <td><?php echo htmlspecialchars((string) ($m['quota']  ?? '')); ?></td>
                                    <td><?php echo (max(0, (int) ($m['quota_used'] ?? 0))) > 0
                                            ? htmlspecialchars(\pmwh3\Utils\SizeConverter::bytesToHumanReadable((int) $m['quota_used']))
                                            : '—'; ?></td>
                                    <td><?php echo htmlspecialchars((string) ($m['active'] ?? '')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                <?php endif; ?>

                <div class="widget-subheader"><?php echo __('Forwards'); ?></div>
                <?php if (empty($forwards)): ?>
                    <p><em><?php echo __('No forwards.'); ?></em></p>
                <?php else: ?>
                    <div class="paginated" data-per-page="15">
                    <table class="widget-table">
                        <thead><tr>
                            <th><?php echo __('Source'); ?></th>
                            <th><?php echo __('Destination'); ?></th>
                        </tr></thead>
                        <tbody>
                            <?php foreach ($forwards as $f):
                                $src = (string) ($f['source'] ?? '');
                            ?>
                                <tr>
                                    <td>
                                        <a href="<?php echo BASE_URI; ?>pmwh3/email/edit_forward/<?php echo urlencode($src); ?>"><?php
                                            echo htmlspecialchars($src);
                                        ?></a>
                                    </td>
                                    <td><?php echo htmlspecialchars((string) ($f['destination'] ?? '')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                <?php endif; ?>

            <?php elseif ($tab === 'apache'): ?>

                <?php $rows = (array) ($tabData['apache_rows'] ?? []); ?>
                <?php if (empty($rows)): ?>
                    <p><em><?php echo __('No subdomain rows for this domain.'); ?></em></p>
                <?php else: ?>
                    <p>
                        <?php echo sprintf(__('Traffic (apache, last 30 days): %s -- statistics current to %s. Details in the Traffic view.'),
                            \pmwh3\Utils\SizeConverter::bytesToHumanReadable((int) ($tabData['traffic30'] ?? 0)),
                            htmlspecialchars($tabData['trafficNewest'] ?? '')); ?>
                    </p>
                    <p>
                        <?php echo sprintf(__('%d subdomain row(s) in this domain.'), count($rows)); ?>
                        <a href="<?php echo BASE_URI; ?>pmwh3/domain/details/<?php echo urlencode($domain); ?>/subdomains"
                           class="button small-action edit"><?php echo __('Manage subdomains'); ?></a>
                    </p>
                    <?php foreach ($rows as $idx => $rowA): ?>
                        <details<?php echo $idx === 0 ? ' open' : ''; ?>>
                            <summary><strong><?php echo htmlspecialchars((string) ($rowA['subdomain'] ?? '')); ?></strong>
                                <?php if (!empty($rowA['alias_of'])): ?>
                                    <small>&rarr; <?php echo htmlspecialchars((string) $rowA['alias_of']); ?></small>
                                <?php endif; ?>
                            </summary>
                            <table class="widget-table">
                                <?php foreach ((array) $rowA as $col => $val): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars((string) $col); ?></td>
                                        <td><pre class="message-body"><?php echo htmlspecialchars((string) $val); ?></pre></td>
                                    </tr>
                                <?php endforeach; ?>
                            </table>
                        </details>
                    <?php endforeach; ?>
                <?php endif; ?>

            <?php elseif ($tab === 'activity'): ?>

                <?php
                $live    = (array) ($tabData['live']    ?? []);
                $recent  = (array) ($tabData['recent']  ?? []);
                $history = (array) ($tabData['history'] ?? []);
                $empty   = empty($live) && empty($recent) && empty($history);
                ?>

                <?php if ($empty): ?>
                    <p><em><?php echo __('No activity recorded for this domain.'); ?></em></p>
                <?php endif; ?>

                <?php // ----- Live now (last 5 minutes) ----------------- ?>
                <?php if (!empty($live)): ?>
                    <h3><?php echo __('Live now'); ?>
                        <small class="pmwh3-muted">
                            <?php echo __('(active in the last 5 minutes)'); ?>
                        </small>
                    </h3>
                    <table class="widget-table">
                        <thead><tr>
                            <th><?php echo __('Customer'); ?></th>
                            <th><?php echo __('IP'); ?></th>
                            <th><?php echo __('Currently on'); ?></th>
                            <th><?php echo __('Logged in since'); ?></th>
                            <th><?php echo __('Last seen'); ?></th>
                        </tr></thead>
                        <tbody>
                            <?php foreach ($live as $a): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars((string) ($a['customer']      ?? '')); ?></td>
                                    <td><?php echo htmlspecialchars((string) ($a['ip']            ?? '')); ?></td>
                                    <td><small><?php echo htmlspecialchars((string) ($a['last_url'] ?? '')); ?></small></td>
                                    <td><?php echo htmlspecialchars((string) ($a['session_start'] ?? '')); ?></td>
                                    <td><?php echo htmlspecialchars((string) ($a['updated_at']    ?? '')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <?php // ----- Recent sessions (last 24h) ---------------- ?>
                <?php if (!empty($recent)): ?>
                    <h3 class="pmwh3-section-spaced"><?php echo __('Recent sessions'); ?>
                        <small class="pmwh3-muted">
                            <?php echo __('(active in the last 24 hours)'); ?>
                        </small>
                    </h3>
                    <table class="widget-table">
                        <thead><tr>
                            <th><?php echo __('Customer'); ?></th>
                            <th><?php echo __('IP'); ?></th>
                            <th><?php echo __('Last URL'); ?></th>
                            <th><?php echo __('Logged in since'); ?></th>
                            <th><?php echo __('Last seen'); ?></th>
                        </tr></thead>
                        <tbody>
                            <?php foreach ($recent as $a): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars((string) ($a['customer']      ?? '')); ?></td>
                                    <td><?php echo htmlspecialchars((string) ($a['ip']            ?? '')); ?></td>
                                    <td><small><?php echo htmlspecialchars((string) ($a['last_url'] ?? '')); ?></small></td>
                                    <td><?php echo htmlspecialchars((string) ($a['session_start'] ?? '')); ?></td>
                                    <td><?php echo htmlspecialchars((string) ($a['updated_at']    ?? '')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <?php // ----- Change history (write ops, 90 days) ------- ?>
                <?php if (!empty($history)): ?>
                    <h3 class="pmwh3-section-spaced"><?php echo __('Change history'); ?>
                        <small class="pmwh3-muted">
                            <?php echo __('(last 50 write operations)'); ?>
                        </small>
                    </h3>
                    <table class="widget-table">
                        <thead><tr>
                            <th><?php echo __('When'); ?></th>
                            <th><?php echo __('Customer'); ?></th>
                            <th><?php echo __('IP'); ?></th>
                            <th><?php echo __('Action'); ?></th>
                            <th><?php echo __('Detail'); ?></th>
                        </tr></thead>
                        <tbody>
                            <?php foreach ($history as $h): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars((string) ($h['created_at'] ?? '')); ?></td>
                                    <td><?php echo htmlspecialchars((string) ($h['customer']   ?? '')); ?></td>
                                    <td><?php echo htmlspecialchars((string) ($h['ip']         ?? '')); ?></td>
                                    <td><code><?php echo htmlspecialchars((string) ($h['action'] ?? '')); ?></code></td>
                                    <td><small><?php echo htmlspecialchars((string) ($h['detail'] ?? '')); ?></small></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

            <?php endif; ?>

            </div><!-- /tab-content -->

        </div>
    </div>
</div>
