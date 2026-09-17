<?php
$domain = (string) ($this->data['domain'] ?? '');
$row    = (array)  ($this->data['row']    ?? []);

$services = explode(',', (string) ($row['services'] ?? ''));
$has = function (string $s) use ($services): bool {
    return in_array($s, $services, true);
};
?>
<div class="pmwh3-content">
    <div class="widget">
        <div class="widget-header">
            <h3><?php echo sprintf(__('Edit domain: %s'), htmlspecialchars($domain)); ?></h3>
        </div>
        <div class="widget-body">

            <form method="post" action="<?php echo BASE_URI; ?>pmwh3/domain/save_domain/<?php echo urlencode($domain); ?>" data-confirm="<?php echo __('Save changes?'); ?>" data-confirm-type="change">
                <table class="widget-table">
                    <tr><td><?php echo __('Domain'); ?></td>
                        <td><strong><?php echo htmlspecialchars($domain); ?></strong>
                            <small><?php echo __('(rename via delete + recreate)'); ?></small></td></tr>
                    <tr><td><?php echo __('Customer'); ?></td>
                        <td><?php echo htmlspecialchars((string) ($row['customer'] ?? '')); ?>
                            <small>#<?php echo (int) ($row['cid'] ?? 0); ?></small></td></tr>
                    <tr><td><?php echo __('IP Address'); ?></td>
                        <td><input type="text" name="config[ip]"
                                   value="<?php echo htmlspecialchars((string) ($row['ip'] ?? '')); ?>"></td></tr>
                    <tr><td><?php echo __('Services'); ?></td>
                        <td>
                            <label><input type="checkbox" name="config[services_web]"  value="1" <?php echo $has('web')  ? 'checked' : ''; ?>> <?php echo __('Web'); ?></label>&nbsp;
                            <label><input type="checkbox" name="config[services_mail]" value="1" <?php echo $has('mail') ? 'checked' : ''; ?>> <?php echo __('Mail'); ?></label>&nbsp;
                            <label><input type="checkbox" name="config[services_dns]"  value="1" <?php echo $has('dns')  ? 'checked' : ''; ?>> <?php echo __('DNS'); ?></label>
                        </td></tr>
                    <tr><td><?php echo __('Registration start'); ?></td>
                        <td><input type="date" name="config[reg_start]"
                                   value="<?php echo htmlspecialchars((string) ($row['reg_start'] ?? '')); ?>"></td></tr>
                    <tr><td><?php echo __('Registration end'); ?></td>
                        <td><input type="date" name="config[reg_end]"
                                   value="<?php echo htmlspecialchars((string) ($row['reg_end'] ?? '')); ?>"></td></tr>
                    <tr><td><?php echo __('Hosting start'); ?></td>
                        <td><input type="date" name="config[host_start]"
                                   value="<?php echo htmlspecialchars((string) ($row['host_start'] ?? '')); ?>"></td></tr>
                    <tr><td><?php echo __('Hosting end'); ?></td>
                        <td><input type="date" name="config[host_end]"
                                   value="<?php echo htmlspecialchars((string) ($row['host_end'] ?? '')); ?>"></td></tr>
                </table>

                <div class="form-actions">
                    <a href="<?php echo BASE_URI; ?>pmwh3/domain/overview"
                       class="button small-action cancel"><?php echo __('Cancel'); ?></a>
                    <button type="submit" class="button small-action save"><?php echo __('Save'); ?></button>
                </div>
            </form>

        </div>
    </div>
</div>
