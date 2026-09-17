<div class="pmwh3-content">
<?php
$row  = $this->data['row'] ?? [];
$cid  = (int) ($row['cid'] ?? 0);
?>
<div class="widget">
    <div class="widget-header">
        <h3><?php echo __('Change password'); ?></h3>
    </div>
    <div class="widget-body">

        <p><?php echo sprintf(__('For customer: %s'), htmlspecialchars((string) ($row['customer'] ?? ''))); ?></p>

        <form method="post" action="<?php echo BASE_URI; ?>pmwh3/customer/save_password/<?php echo $cid; ?>" data-confirm="<?php echo __('Change password?'); ?>" data-confirm-type="change">
            <table class="widget-table">
                <tr><td><?php echo __('New password'); ?></td>
                    <td><input type="password" name="password" required></td></tr>
                <tr><td><?php echo __('Repeat'); ?></td>
                    <td><input type="password" name="repeat" required></td></tr>
            </table>
            <div class="form-actions">
                <a href="<?php echo BASE_URI; ?>pmwh3/customer/overview"
                   class="button small-action cancel"><?php echo __('Cancel'); ?></a>
                <button type="submit" class="button small-action save"><?php echo __('Change'); ?></button>
            </div>
        </form>

    </div>
</div>
</div>
