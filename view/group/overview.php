<div class="pmwh3-content">
<?php
$groups      = (array) ($this->data['groups']      ?? []);
$create_perm = (bool)  ($this->data['create_perm'] ?? false);
$edit_perm   = (bool)  ($this->data['edit_perm']   ?? false);
$delete_perm = (bool)  ($this->data['delete_perm'] ?? false);
?>
<div class="widget">
    <div class="widget-header">
        <h3><?php echo __('Customer Groups'); ?></h3>
        <?php if ($create_perm): ?>
            <a href="<?php echo BASE_URI; ?>pmwh3/group/new_group"
               class="button small-action create"><?php echo __('New group'); ?></a>
        <?php endif; ?>
    </div>
    <div class="widget-body">

        <?php if (empty($groups)): ?>
            <p><em><?php echo __('No groups defined.'); ?></em></p>
        <?php else: ?>
            <div class="paginated" data-per-page="20">
<table class="widget-table">
                <thead>
                    <tr>
                        <th><?php echo __('Group ID'); ?></th>
                        <th><?php echo __('Name'); ?></th>
                        <th><?php echo __('Members'); ?></th>
                        <th><?php echo __('Actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($groups as $g):
                        $gid     = (int) ($g['gid'] ?? 0);
                        $name    = (string) ($g['name'] ?? '');
                        $count   = (int) ($g['member_count'] ?? 0);
                    ?>
                        <tr>
                            <td><?php echo $gid; ?></td>
                            <td><strong><?php echo htmlspecialchars($name); ?></strong></td>
                            <td><?php echo $count; ?>
                                <?php if ($count > 0): ?>
                                    <a href="<?php echo BASE_URI; ?>pmwh3/group/members/<?php echo $gid; ?>"
                                       class="button small-action blue"><?php echo __('Show members'); ?></a>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($edit_perm): ?>
                                    <a href="<?php echo BASE_URI; ?>pmwh3/group/change_group/<?php echo $gid; ?>"
                                       class="button small-action edit"><?php echo __('Edit'); ?></a>
                                <?php endif; ?>
                                <a href="<?php echo BASE_URI; ?>pmwh3/group/permissions/<?php echo $gid; ?>"
                                   class="button small-action yellow"><?php echo __('Permissions'); ?></a>
                                <?php if ($delete_perm && $count === 0): ?>
                                    <form method="post"
                                          action="<?php echo BASE_URI; ?>pmwh3/group/delete_group/<?php echo $gid; ?>"
                                          class="inline-form"
                                          data-confirm="<?php echo __('Delete this group?'); ?>"
                                          data-confirm-type="delete">
                                        <button type="submit" class="button small-action delete"><?php echo __('Delete'); ?></button>
                                    </form>
                                <?php elseif ($delete_perm && $count > 0): ?>
                                    <small><?php echo __('(has members)'); ?></small>
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
