<div class="pmwh3-content">
<?php
/**
 * Cevian's view::render() turns the controller's `'data' => $data`
 * into $this->data. So $this->data['customers'] is the array we
 * passed in, not $data['customers'].
 */
$customers          = $this->data['customers']          ?? [];
$create_perm        = $this->data['create_perm']        ?? false;
$edit_perm          = $this->data['edit_perm']          ?? false;
$delete_perm        = $this->data['delete_perm']        ?? false;
$edit_password_perm = $this->data['edit_password_perm'] ?? false;
$view_creator_perm  = $this->data['view_creator_perm']  ?? false;
?>
<div class="widget">
    <div class="widget-header">
        <h3><?php echo __('Customers'); ?></h3>
        <?php if ($create_perm): ?>
            <a href="<?php echo BASE_URI; ?>pmwh3/customer/new_user"
               class="button small-action create"><?php echo __('New customer'); ?></a>
        <?php endif; ?>
    </div>
    <div class="widget-body">

        <?php if (empty($customers)): ?>
            <p><?php echo __('No customers visible.'); ?></p>
        <?php else: ?>
            <div class="paginated" data-per-page="20">
<table class="widget-table">
                <thead>
                    <tr>
                        <th><?php echo __('Customer'); ?></th>
                        <th><?php echo __('Real name'); ?></th>
                        <th><?php echo __('Email'); ?></th>
                        <?php if ($view_creator_perm): ?>
                            <th><?php echo __('Creator'); ?></th>
                        <?php endif; ?>
                        <th><?php echo __('Actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($customers as $c):
                        $level   = (int) ($c['level'] ?? 0);
                        $indent  = str_repeat('&nbsp;&nbsp;&nbsp;', $level);
                        $cid     = (int) ($c['cid'] ?? 0);
                    ?>
                        <tr>
                            <td><?php echo $indent . htmlspecialchars((string) ($c['customer'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string) ($c['realname'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string) ($c['email'] ?? '')); ?></td>
                            <?php if ($view_creator_perm):
                                $creatorId   = (int) ($c['creator'] ?? 0);
                                $creatorName = (string) ($c['creator_name'] ?? '');
                                if ($creatorId === 0) {
                                    $creatorDisplay = '<em>' . __('system') . '</em>';
                                } elseif ($creatorName !== '') {
                                    $creatorDisplay = htmlspecialchars($creatorName);
                                } else {
                                    $creatorDisplay = '#' . $creatorId;
                                }
                            ?>
                                <td><?php echo $creatorDisplay; ?></td>
                            <?php endif; ?>
                            <td>
                                <?php if ($edit_perm): ?>
                                    <a href="<?php echo BASE_URI; ?>pmwh3/customer/change_user/<?php echo $cid; ?>"
                                       class="button small-action edit"><?php echo __('Edit'); ?></a>
                                <?php endif; ?>
                                <?php if ($edit_password_perm): ?>
                                    <a href="<?php echo BASE_URI; ?>pmwh3/customer/change_password/<?php echo $cid; ?>"
                                       class="button small-action yellow"><?php echo __('Password'); ?></a>
                                <?php endif; ?>
                                <?php if ($delete_perm && $cid !== 1): ?>
                                    <form action="<?php echo BASE_URI; ?>pmwh3/customer/delete_user/<?php echo $cid; ?>"
                                          method="post" class="inline-form"
                                          data-confirm="<?php echo __('Delete customer?'); ?>"
                                          data-confirm-type="delete">
                                        <button type="submit" class="button small-action delete"><?php echo __('Delete'); ?></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
</div>
        <?php endif; ?>

    </div>
</div>
</div>
