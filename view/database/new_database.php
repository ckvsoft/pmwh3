<?php
$customer = (string) ($this->data['customer'] ?? '');
?>
<div class="pmwh3-content">
    <div class="widget">
        <div class="widget-header">
            <h3><?php echo __('Create database'); ?></h3>
            <a href="<?php echo BASE_URI; ?>pmwh3/database/overview"
               class="button small-action cancel"><?php echo __('Back'); ?></a>
        </div>
        <div class="widget-body">
            <form method="post" action="<?php echo BASE_URI; ?>pmwh3/database/insert_database" data-confirm="<?php echo __('Create database?'); ?>" data-confirm-type="change">
                <table class="widget-table">
                    <tr>
                        <td><?php echo __('Database name'); ?></td>
                        <td>
                            <?php if ($customer !== ''): ?>
                                <code><?php echo htmlspecialchars($customer); ?>_</code>
                            <?php endif; ?>
                            <input name="config[suffix]" required>
                            <small><?php echo __('Letters, digits, underscore only. Will be prefixed with your customer name (configurable).'); ?></small>
                        </td>
                    </tr>
                </table>
                <div style="margin-top:1em;">
                    <button type="submit" class="button"><?php echo __('Create'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
