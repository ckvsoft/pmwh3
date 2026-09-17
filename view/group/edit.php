<div class="pmwh3-content">
<?php
$mode = (string) ($this->data['mode'] ?? 'new');
$gid  = (int)    ($this->data['gid']  ?? 0);
$row  = (array)  ($this->data['row']  ?? []);

$action = $mode === 'new'
        ? BASE_URI . 'pmwh3/group/insert_group'
        : BASE_URI . 'pmwh3/group/save_group/' . $gid;
?>
<div class="widget">
    <div class="widget-header">
        <h3><?php echo $mode === 'new' ? __('New group') : __('Edit group'); ?></h3>
    </div>
    <div class="widget-body">

        <form method="post" action="<?php echo $action; ?>" data-confirm="<?php echo __('Save changes?'); ?>" data-confirm-type="change">

            <table class="widget-table">
                <?php if ($mode === 'edit'): ?>
                    <tr><td><?php echo __('Group ID'); ?></td>
                        <td><?php echo $gid; ?></td></tr>
                <?php endif; ?>
                <tr><td><?php echo __('Name'); ?></td>
                    <td><input type="text" name="name"
                               value="<?php echo htmlspecialchars((string) ($row['name'] ?? '')); ?>"
                               required></td></tr>
            </table>

            <div class="form-actions">
                <a href="<?php echo BASE_URI; ?>pmwh3/group/overview"
                   class="button small-action cancel"><?php echo __('Cancel'); ?></a>
                <button type="submit" class="button small-action save">
                    <?php echo $mode === 'new' ? __('Create') : __('Save'); ?>
                </button>
            </div>
        </form>

    </div>
</div>
</div>
