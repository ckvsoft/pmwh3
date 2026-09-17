<div class="pmwh3-content">
<?php
$domain = $this->data['domain'] ?? '';
?>
<div class="widget">
    <div class="widget-header">
        <h3><?php echo sprintf(__('New mailbox in %s'), htmlspecialchars($domain)); ?></h3>
    </div>
    <div class="widget-body">

        <form method="post" action="<?php echo BASE_URI; ?>pmwh3/email/insert_email" data-confirm="<?php echo __('Create mailbox?'); ?>" data-confirm-type="change">
            <table class="widget-table">
                <tr>
                    <td><?php echo __('Local part'); ?></td>
                    <td><input type="text" name="local" required>
                        @<?php echo htmlspecialchars($domain); ?></td>
                </tr>
                <tr>
                    <td><?php echo __('Password'); ?></td>
                    <td><input type="password" name="password" required></td>
                </tr>
                <tr>
                    <td><?php echo __('Quota (MB, 0 = unlimited)'); ?></td>
                    <td><input type="number" name="quota" value="0" min="0"></td>
                </tr>
            </table>
            <div class="form-actions">
                <a href="<?php echo BASE_URI; ?>pmwh3/email/details/email"
                   class="button small-action cancel"><?php echo __('Cancel'); ?></a>
                <button type="submit" class="button small-action save"><?php echo __('Create'); ?></button>
            </div>
        </form>

    </div>
</div>
</div>
