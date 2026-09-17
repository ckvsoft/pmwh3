<div class="pmwh3-content">
<?php
$email = (string) ($this->data['email'] ?? '');
?>
<div class="widget">
    <div class="widget-header">
        <h3><?php echo sprintf(__('Change password for %s'), htmlspecialchars($email)); ?></h3>
    </div>
    <div class="widget-body">

        <form method="post" action="<?php echo BASE_URI; ?>pmwh3/email/save_mailbox_password/<?php echo urlencode($email); ?>" data-confirm="<?php echo __('Change password?'); ?>" data-confirm-type="change">
            <table class="widget-table">
                <tr>
                    <td><?php echo __('New password'); ?></td>
                    <td><input type="password" name="password" required></td>
                </tr>
            </table>
            <div class="form-actions">
                <a href="<?php echo BASE_URI; ?>pmwh3/email/details/email"
                   class="button small-action cancel"><?php echo __('Cancel'); ?></a>
                <button type="submit" class="button small-action save"><?php echo __('Change'); ?></button>
            </div>
        </form>

    </div>
</div>
</div>
