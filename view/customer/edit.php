<div class="pmwh3-content">
<?php
/**
 * @var array $data
 *   mode ('new' | 'edit'), row, limits, groups, packages,
 *   view_limits_perm, edit_limits_perm, languages
 */
$mode             = $this->data['mode']             ?? 'new';
$row              = $this->data['row']              ?? [];
$limits           = $this->data['limits']           ?? [];
$groups           = $this->data['groups']           ?? [];
$packages         = $this->data['packages']         ?? [];
$view_limits_perm = $this->data['view_limits_perm'] ?? false;
$edit_limits_perm = $this->data['edit_limits_perm'] ?? false;
$languages        = $this->data['languages']        ?? [];

$cid = (int) ($row['cid'] ?? 0);
$action = $mode === 'new'
        ? BASE_URI . 'pmwh3/customer/insert_user'
        : BASE_URI . 'pmwh3/customer/save_user/' . $cid;
?>
<div class="widget">
    <div class="widget-header">
        <h3><?php echo $mode === 'new' ? __('New customer') : __('Edit customer'); ?></h3>
    </div>
    <div class="widget-body">

        <form method="post" action="<?php echo $action; ?>" data-confirm="<?php echo __('Save changes?'); ?>" data-confirm-type="change">
            <table class="widget-table">
                <tr><td><?php echo __('Login'); ?></td>
                    <td><input type="text" name="customer" value="<?php echo htmlspecialchars((string) ($row['customer'] ?? '')); ?>" required></td></tr>
                <tr><td><?php echo __('Real name'); ?></td>
                    <td><input type="text" name="realname" value="<?php echo htmlspecialchars((string) ($row['realname'] ?? '')); ?>"></td></tr>
                <tr><td><?php echo __('Email'); ?></td>
                    <td><input type="email" name="email" value="<?php echo htmlspecialchars((string) ($row['email'] ?? '')); ?>"></td></tr>
                <tr><td><?php echo __('Group'); ?></td>
                    <td>
                        <select name="role_id">
                            <?php foreach ($groups as $gid => $gname): ?>
                                <option value="<?php echo (int) $gid; ?>"
                                        <?php echo ((int) ($row['role_id'] ?? 0) === (int) $gid) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars((string) $gname); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td></tr>
                <tr><td><?php echo __('Customer no.'); ?></td>
                    <td><input type="text" name="customer_number" value="<?php echo htmlspecialchars((string) ($row['customer_number'] ?? '')); ?>"></td></tr>

                <tr><td><?php echo __('Street'); ?></td>
                    <td><input type="text" name="street" value="<?php echo htmlspecialchars((string) ($row['street'] ?? '')); ?>"></td></tr>
                <tr><td><?php echo __('Postcode'); ?></td>
                    <td><input type="text" name="postcode" value="<?php echo htmlspecialchars((string) ($row['postcode'] ?? '')); ?>"></td></tr>
                <tr><td><?php echo __('City'); ?></td>
                    <td><input type="text" name="city" value="<?php echo htmlspecialchars((string) ($row['city'] ?? '')); ?>"></td></tr>
                <tr><td><?php echo __('Country'); ?></td>
                    <td><input type="text" name="country" value="<?php echo htmlspecialchars((string) ($row['country'] ?? '')); ?>"></td></tr>
                <tr><td><?php echo __('Telephone'); ?></td>
                    <td><input type="text" name="telephone" value="<?php echo htmlspecialchars((string) ($row['telephone'] ?? '')); ?>"></td></tr>
                <tr><td><?php echo __('Fax'); ?></td>
                    <td><input type="text" name="facsimile" value="<?php echo htmlspecialchars((string) ($row['facsimile'] ?? '')); ?>"></td></tr>

                <tr><td><?php echo __('PHP'); ?></td>
                    <td><input type="checkbox" name="php" <?php echo ($row['php'] ?? 'N') === 'Y' ? 'checked' : ''; ?>></td></tr>
                <tr><td><?php echo __('CGI'); ?></td>
                    <td><input type="checkbox" name="cgi" <?php echo ($row['cgi'] ?? 'N') === 'Y' ? 'checked' : ''; ?>></td></tr>
                <tr><td><?php echo __('Standard subdomains'); ?></td>
                    <td><input type="checkbox" name="standard_subdomain" <?php echo ($row['standard_subdomain'] ?? 'N') === 'Y' ? 'checked' : ''; ?>></td></tr>

                <tr><td><?php echo __('Language'); ?></td>
                    <td>
                        <select name="language">
                            <option value=""><?php echo __('— default —'); ?></option>
                            <?php foreach ($languages as $lk => $ln): ?>
                                <option value="<?php echo htmlspecialchars((string) $lk); ?>"
                                        <?php echo ((string) ($row['language'] ?? '') === (string) $lk) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars((string) $ln); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td></tr>

                <?php if (!empty($packages)): ?>
                    <tr><td><?php echo __('Package'); ?></td>
                        <td>
                            <select name="package" id="customer_package_select">
                                <?php foreach ($packages as $pk => $pn): ?>
                                    <option value="<?php echo htmlspecialchars((string) $pk); ?>"
                                            <?php echo ((string) ($row['package'] ?? '') === (string) $pk) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars((string) $pn); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($mode === 'edit'): ?>
                                <small><?php echo __('Changing the package re-fills the limit fields below; you can still edit them by hand afterwards.'); ?></small>
                            <?php endif; ?>
                        </td></tr>
                <?php endif; ?>

                <?php if ($mode === 'new'): ?>
                    <tr><td><?php echo __('Password'); ?></td>
                        <td><input type="password" name="password" required></td></tr>
                <?php endif; ?>

                <?php if ($view_limits_perm): ?>
                    <tr><td colspan="2"><strong><?php echo __('Limits (-1 = unlimited, 0 = disabled)'); ?></strong></td></tr>
                    <?php
                    $limitFields = [
                        'webspace'   => __('Webspace (MB)'),
                        'traffic'    => __('Traffic (MB/mo)'),
                        'domains'    => __('Domains'),
                        'subdomains' => __('Subdomains'),
                        'emails'     => __('Email accounts'),
                        'forwards'   => __('Forwards'),
                        'dbases'     => __('Databases'),
                    ];
                    $disabled = $edit_limits_perm ? '' : ' disabled';
                    foreach ($limitFields as $f => $label): ?>
                        <tr><td><?php echo htmlspecialchars($label); ?></td>
                            <td><input type="number" name="<?php echo $f; ?>"
                                       value="<?php echo (int) ($limits[$f] ?? 0); ?>"<?php echo $disabled; ?>></td></tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </table>

            <div class="form-actions">
                <a href="<?php echo BASE_URI; ?>pmwh3/customer/overview"
                   class="button small-action cancel"><?php echo __('Cancel'); ?></a>
                <button type="submit" class="button small-action save">
                    <?php echo $mode === 'new' ? __('Create') : __('Save'); ?>
                </button>
            </div>
        </form>

    </div>
</div>
</div>

<?php
/**
 * Settings-driven JS: when a package is selected, copy its limit
 * values into the matching number inputs. The map is rendered as
 * JSON from $packages_full so we don't need an AJAX round-trip.
 */
$packagesFull = $this->data['packages_full'] ?? [];
if (!empty($packagesFull)):
    $limitKeys = ['webspace', 'traffic', 'domains', 'subdomains',
                  'emails', 'forwards', 'dbases'];
    // Reduce to just the columns we care about, keep them as ints.
    $jsMap = [];
    foreach ($packagesFull as $name => $row) {
        $entry = [];
        foreach ($limitKeys as $k) {
            $entry[$k] = (int) ($row[$k] ?? 0);
        }
        $entry['php'] = (string) ($row['php'] ?? 'N');
        $entry['cgi'] = (string) ($row['cgi'] ?? 'N');
        $jsMap[$name] = $entry;
    }
    $jsKeys = json_encode($limitKeys);
    $jsData = json_encode($jsMap);
?>
<script>
(function () {
    var sel = document.getElementById('customer_package_select');
    if (!sel) return;
    var packages = <?php echo $jsData; ?>;
    var limitKeys = <?php echo $jsKeys; ?>;

    function applyPackage(name) {
        var pkg = packages[name];
        if (!pkg) return;  // empty / "no package" entry
        limitKeys.forEach(function (k) {
            var el = document.querySelector('input[name="' + k + '"]');
            if (el) el.value = pkg[k];
        });
        ['php', 'cgi'].forEach(function (k) {
            var el = document.querySelector('input[name="' + k + '"]');
            if (el) el.checked = pkg[k] === 'Y';
        });
    }

    sel.addEventListener('change', function () { applyPackage(sel.value); });
})();
</script>
<?php endif; ?>
