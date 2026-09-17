<?php
$domains     = (array) ($this->data['domains']     ?? []);
$create_perm = (bool)  ($this->data['create_perm'] ?? false);
?>
<div class="pmwh3-content">
    <div class="widget">
        <div class="widget-header">
            <h3><?php echo __('FTP'); ?></h3>
        </div>
        <div class="widget-body">

            <?php if (empty($domains)): ?>
                <p><em><?php echo __('No domains assigned.'); ?></em></p>
            <?php else: ?>
                <table class="widget-table">
                    <thead>
                        <tr>
                            <th><?php echo __('Domain'); ?></th>
                            <th><?php echo __('Accounts'); ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($domains as $d): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($d['domain']); ?></td>
                                <td><?php echo (int) $d['accounts']; ?></td>
                                <td>
                                    <a class="button small-action edit"
                                       href="<?php echo BASE_URI; ?>pmwh3/ftp/pick/<?php echo urlencode($d['domain']); ?>">
                                        <?php echo __('Show accounts'); ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

        </div>
    </div>
</div>
