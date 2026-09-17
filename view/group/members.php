<div class="pmwh3-content">
<?php
$group   = (array) ($this->data['group']   ?? []);
$members = (array) ($this->data['members'] ?? []);
$gid     = (int)   ($group['gid']  ?? 0);
$name    = (string)($group['name'] ?? '');
?>
<div class="widget">
    <div class="widget-header">
        <h3><?php echo sprintf(__('Members of "%s"'), htmlspecialchars($name)); ?></h3>
        <a href="<?php echo BASE_URI; ?>pmwh3/group/overview"
           class="button small-action cancel"><?php echo __('Back'); ?></a>
    </div>
    <div class="widget-body">

        <?php if (empty($members)): ?>
            <p><em><?php echo __('No customers are in this group.'); ?></em></p>
        <?php else: ?>
            <div class="paginated" data-per-page="20">
<table class="widget-table">
                <thead>
                    <tr>
                        <th><?php echo __('CID'); ?></th>
                        <th><?php echo __('Customer'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($members as $m): ?>
                        <tr>
                            <td><?php echo (int) ($m['cid'] ?? 0); ?></td>
                            <td><strong><?php echo htmlspecialchars((string) ($m['customer'] ?? '')); ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
</div>
        <?php endif; ?>

    </div>
</div>
</div>
