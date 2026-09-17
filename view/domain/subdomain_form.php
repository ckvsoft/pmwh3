<?php
// $this->data: mode (create|edit), domain, row, ips, custom, webEnabled, quota

$viewData   = $this->data;
$mode       = (string) ($viewData['mode'] ?? 'create');
$domain     = (string) ($viewData['domain'] ?? '');
$row        = is_array($viewData['row'] ?? null) ? $viewData['row'] : null;
$ips        = (array) ($viewData['ips'] ?? []);
$custom     = (string) ($viewData['custom'] ?? '');
$webEnabled = !empty($viewData['webEnabled']);
$fqdn       = (string) ($row['subdomain'] ?? '');
$isIpRow    = $row !== null && filter_var((string) ($row['path'] ?? ''), FILTER_VALIDATE_IP) !== false;
$label      = $fqdn === '' ? '' : explode('.', $fqdn, 2)[0];
$certs      = (array) ($viewData['certs'] ?? []);
$sslCapable = !empty($viewData['sslCapable']);
$hasTls     = $row !== null && (string) strpos((string) ($row['data'] ?? ''), 'SSLEngine on') !== false;
preg_match('/SSLCertificateFile\s+[^\s]*\/([^\/\s]+)\.pem/', (string) ($row['data'] ?? ''), $certMatch);
$sslCurrent = $certMatch[1] ?? '';
?>
<div class="pmwh3-content">
    <div class="widget">
        <div class="widget-header">
            <h3><?php echo $mode === 'edit' ? __('Edit subdomain') : __('New subdomain'); ?></h3>
            <form action="<?php echo BASE_URI; ?>pmwh3/domain/details/<?php echo urlencode($domain); ?>/subdomains" method="post" class="inline-form">
                <button type="submit" class="button small-action cancel"><?php echo __('Back'); ?></button>
            </form>
        </div>
        <div class="widget-body">

            <?php if (!$webEnabled): ?>
                <p><em><?php echo __('No web adapter configured -- subdomain rows cannot be managed.'); ?></em></p>
            <?php endif; ?>

            <?php if ($mode === 'create'): ?>
                <form action="<?php echo BASE_URI; ?>pmwh3/domain/subdomain_insert/<?php echo urlencode($domain); ?>" method="post" data-confirm="<?php echo __('Create subdomain?'); ?>" data-confirm-type="change">
                    <table class="widget-table">
                        <tbody>
                            <tr>
                                <th align="right"><?php echo __('Label'); ?></th>
                                <td class="widget-value">
                                    <input type="text" name="sub" required
                                           pattern="[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?">
                                    <em>.<?php echo htmlspecialchars($domain); ?></em>
                                </td>
                            </tr>
                            <tr>
                                <th align="right" valign="top"><?php echo __('Type'); ?></th>
                                <td class="widget-value">
                                    <label><input type="radio" name="mode_v" value="directory" checked
                                                  onchange="pmwh3SwitchMode(this, 'directory')"> <?php echo __('Directory (local vhost)'); ?></label><br>
                                    <label><input type="radio" name="mode_v" value="ip"
                                                  onchange="pmwh3SwitchMode(this, 'ip')"> <?php echo __('IP forwarding (DNS only, remote server)'); ?></label><br>
                                    <label><input type="radio" name="mode_v" value="alias"
                                                  onchange="pmwh3SwitchMode(this, 'alias')"> <?php echo __('Alias of existing subdomain'); ?></label>
                                </td>
                            </tr>
                            <tr data-mode="alias">
                                <th align="right"><?php echo __('Alias target'); ?></th>
                                <td class="widget-value">
                                    <input type="text" name="alias_of"
                                           placeholder="<?php echo __('e.g. www.example.com'); ?>">
                                </td>
                            </tr>
                            <tr data-mode="directory,ip">
                                <th align="right"><?php echo __('Directory or IP'); ?></th>
                                <td class="widget-value">
                                    <input type="text" name="value"
                                           placeholder="<?php echo __('Directory name under the webroot (directory mode) or target IP (IP mode)'); ?>">
                                    <?php if (!empty($ips)): ?>
                                        <select onchange="if(this.value) this.form.value.value=this.value">
                                            <option value=""><?php echo __('Known server IPs'); ?></option>
                                            <?php foreach ($ips as $ip): ?>
                                                <option value="<?php echo htmlspecialchars((string) $ip); ?>"><?php echo htmlspecialchars((string) $ip); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th align="right" valign="top"><?php echo __('Custom config'); ?></th>
                                <td class="widget-value">
                                    <textarea name="custom" cols="50" rows="8"></textarea>
                                    <br><small><?php echo __('Rendered between the START/END CUSTOM markers in the vhost. Empty = the configured snippet.'); ?></small>
                                </td>
                            </tr>
                            <?php if ($sslCapable): ?>
                            <tr data-mode="directory,alias">
                                <th align="right" valign="top"><?php echo __('TLS certificate'); ?></th>
                                <td class="widget-value">
                                    <select name="ssl_cert">
                                        <option value="auto" selected><?php echo __('Automatic (best match for this name, else http-only)'); ?></option>
                                        <option value=""><?php echo __('None (http only)'); ?></option>
                                        <?php foreach ($certs as $base): ?>
                                            <option value="<?php echo htmlspecialchars((string) $base); ?>"><?php echo htmlspecialchars((string) $base); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <br><small><?php echo __("An https block (*:443) with SSLCertificate directives is rendered before the http block when a certificate applies."); ?></small>
                                </td>
                            </tr>
                            <?php endif; ?>
                            <tr>
                                <td align="right" colspan="2" class="widget-value">
                                    <button type="submit" class="button small-action create"><?php echo __('Create'); ?></button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </form>

            <?php else: ?>
                <form action="<?php echo BASE_URI; ?>pmwh3/domain/subdomain_save/<?php echo urlencode($fqdn); ?>" method="post" data-confirm="<?php echo __('Save changes?'); ?>" data-confirm-type="change">
                    <table class="widget-table">
                        <tbody>
                            <tr>
                                <th align="right"><?php echo __('Subdomain'); ?></th>
                                <td class="widget-value">
                                    <input type="text" name="sub"
                                           value="<?php echo htmlspecialchars($label); ?>">
                                    <em><?php echo htmlspecialchars('.' . (string) ($row['domain'] ?? $domain)); ?></em>
                                    <br><small><?php echo __('Empty = keep the current name. A rename moves the document root when possible.'); ?></small>
                                </td>
                            </tr>
                            <tr>
                                <th align="right"><?php echo __('Path / IP'); ?></th>
                                <td class="widget-value">
                                    <?php echo htmlspecialchars((string) ($row['path'] ?? '')); ?>
                                    <?php if ($isIpRow): ?><em>(<?php echo __('IP forwarding'); ?>)</em><?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th align="right"><?php echo __('Alias of'); ?></th>
                                <td class="widget-value"><?php echo ((string) ($row['alias_of'] ?? '')) === '' ? '—' : htmlspecialchars((string) $row['alias_of']); ?></td>
                            </tr>
                            <tr>
                                <th align="right" valign="top"><?php echo __('Custom config'); ?></th>
                                <td class="widget-value">
                                    <textarea name="custom" cols="50" rows="8" <?php echo $isIpRow ? 'disabled' : ''; ?>><?php echo htmlspecialchars($custom); ?></textarea>
                                    <br><small><?php echo __('Content between the START/END CUSTOM markers.'); ?>
                                        <?php if ($isIpRow): ?><em><?php echo __('(No vhost on IP-forwarding rows.)'); ?></em><?php endif; ?>
                                    </small>
                                </td>
                            </tr>
                            <?php if ($sslCapable && !$isIpRow): ?>
                            <tr>
                                <th align="right" valign="top"><?php echo __('TLS certificate'); ?></th>
                                <td class="widget-value">
                                    <select name="ssl_cert">
                                        <option value=""><?php echo __('None (http only)'); ?></option>
                                        <option value="auto"<?php echo $sslCurrent === '' ? ' selected' : ''; ?>><?php echo __('Automatic (best match for this name)'); ?></option>
                                        <?php if ($sslCurrent !== '' && !isset($certs[$sslCurrent])): ?>
                                            <option value="<?php echo htmlspecialchars($sslCurrent); ?>"
                                                    selected><?php echo htmlspecialchars($sslCurrent) . ' ' . __('(currently set)'); ?></option>
                                        <?php endif; ?>
                                        <?php foreach ($certs as $base): ?>
                                            <option value="<?php echo htmlspecialchars((string) $base); ?>"<?php echo (string) $base === $sslCurrent ? ' selected' : ''; ?>><?php echo htmlspecialchars((string) $base); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if ($sslCurrent !== ''): ?>
                                        <br><small><?php echo sprintf(__('Currently bound: %s'), htmlspecialchars($sslCurrent)); ?></small>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endif; ?>
                            <tr>
                                <th align="right"><?php echo __('Regenerate skeleton'); ?></th>
                                <td class="widget-value">
                                    <label><input type="checkbox" name="regenerate" value="1"> <?php echo __('Re-render the vhost skeleton (identity, paths, snippet), keeping this custom section'); ?></label>
                                </td>
                            </tr>
                            <tr>
                                <td align="right" colspan="2" class="widget-value">
                                    <button type="submit" class="button small-action edit"><?php echo __('Save'); ?></button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>
<script>
function pmwh3SwitchMode(radio, mode) {
    document.querySelectorAll('tr[data-mode]').forEach(function (tr) {
        var modes = (tr.dataset.mode || '').split(',');
        tr.style.display = modes.indexOf(mode) !== -1 ? '' : 'none';
    });
}
document.addEventListener('DOMContentLoaded', function () {
    var checked = document.querySelector('input[name="mode_v"]:checked');
    if (checked) pmwh3SwitchMode(checked, checked.value);
});
</script>
