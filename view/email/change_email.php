<div class="pmwh3-content">
<?php
$row  = $this->data['row'] ?? [];
$email = (string) ($row['email'] ?? '');
?>
<div class="widget">
    <div class="widget-header">
        <h3><?php echo sprintf(__('Edit mailbox %s'), htmlspecialchars($email)); ?></h3>
    </div>
    <div class="widget-body">

        <form method="post" action="<?php echo BASE_URI; ?>pmwh3/email/save_email/<?php echo urlencode($email); ?>" data-confirm="<?php echo __('Save changes?'); ?>" data-confirm-type="change">
            <table class="widget-table">
                <tr>
                    <td><?php echo __('Quota (MB, 0 = unlimited)'); ?></td>
                    <td><input type="number" name="quota" value="<?php echo (int) ($row['quota'] ?? 0); ?>" min="0"></td>
                </tr>
            </table>
            <div class="form-actions">
                <a href="<?php echo BASE_URI; ?>pmwh3/email/details/email"
                   class="button small-action cancel"><?php echo __('Cancel'); ?></a>
                <button type="submit" class="button small-action save"><?php echo __('Save'); ?></button>
            </div>
        </form>

    </div>
</div>
</div>
