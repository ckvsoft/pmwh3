<div class="pmwh3-content">
<?php
$mode    = (string) ($this->data['mode']    ?? 'new');
$name    = (string) ($this->data['name']    ?? '');
$creator = (int)    ($this->data['creator'] ?? 0);
$row     = (array)  ($this->data['row']     ?? []);

$action = $mode === 'new'
        ? BASE_URI . 'pmwh3/package/insert_package'
        : BASE_URI . 'pmwh3/package/save_package/' . urlencode($name) . '/' . $creator;
?>
<div class="widget">
    <div class="widget-header">
        <h3><?php echo $mode === 'new' ? __('New package') : __('Edit package'); ?></h3>
    </div>
    <div class="widget-body">

        <form method="post" action="<?php echo $action; ?>" data-confirm="<?php echo __('Save changes?'); ?>" data-confirm-type="change">

            <table class="widget-table">
                <tr><td><?php echo __('Name'); ?></td>
                    <td>
                        <?php if ($mode === 'new'): ?>
                            <input type="text" name="package_name" value="" required>
                        <?php else: ?>
                            <strong><?php echo htmlspecialchars($name); ?></strong>
                            <small><?php echo __('(rename = delete + create)'); ?></small>
                        <?php endif; ?>
                    </td></tr>

                <?php
                $intFields = [
                    'webspace'   => __('Webspace (MB)'),
                    'traffic'    => __('Traffic (MB/mo)'),
                    'domains'    => __('Domains'),
                    'subdomains' => __('Subdomains'),
                    'emails'     => __('Email accounts'),
                    'forwards'   => __('Forwards'),
                    'dbases'     => __('Databases'),
                ];
                foreach ($intFields as $f => $label): ?>
                    <tr><td><?php echo htmlspecialchars($label); ?></td>
                        <td><input type="number" name="<?php echo $f; ?>"
                                   value="<?php echo (int) ($row[$f] ?? 0); ?>"></td></tr>
                <?php endforeach; ?>

                <tr><td><?php echo __('PHP'); ?></td>
                    <td><input type="checkbox" name="php" <?php echo ($row['php'] ?? 'N') === 'Y' ? 'checked' : ''; ?>></td></tr>
                <tr><td><?php echo __('CGI'); ?></td>
                    <td><input type="checkbox" name="cgi" <?php echo ($row['cgi'] ?? 'N') === 'Y' ? 'checked' : ''; ?>></td></tr>
            </table>

            <p><small><?php echo __('-1 means unlimited, 0 means disabled.'); ?></small></p>

            <div class="form-actions">
                <a href="<?php echo BASE_URI; ?>pmwh3/package/overview"
                   class="button small-action cancel"><?php echo __('Cancel'); ?></a>
                <button type="submit" class="button small-action save">
                    <?php echo $mode === 'new' ? __('Create') : __('Save'); ?>
                </button>
            </div>
        </form>

    </div>
</div>
</div>
