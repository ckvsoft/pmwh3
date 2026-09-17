<?php
/**
 * Menu rows listing. Editing CRUD lives in edit_menu_row.php +
 * controller actions new_menu_row / insert_menu_row /
 * edit_menu_row / save_menu_row / delete_menu_row /
 * toggle_menu_row.
 *
 * Data:
 *   rows         : pmwh3_menu rows ordered by box,sort
 *   create_perm  : may add a new row
 *   edit_perm    : may edit or toggle visibility
 *   delete_perm  : may delete a row
 */
$rows        = (array) ($this->data['rows']        ?? []);
$create_perm = (bool)  ($this->data['create_perm'] ?? false);
$edit_perm   = (bool)  ($this->data['edit_perm']   ?? false);
$delete_perm = (bool)  ($this->data['delete_perm'] ?? false);
?>
<div class="pmwh3-content">
    <div class="widget">
        <div class="widget-header">
            <h3><?php echo __('Menu rows'); ?></h3>
            <?php if ($create_perm): ?>
                <a href="<?php echo BASE_URI; ?>pmwh3/tools/new_menu_row"
                   class="button small-action create"><?php echo __('New menu row'); ?></a>
            <?php endif; ?>
        </div>
        <div class="widget-body">

            <?php if (empty($rows)): ?>
                <p><em><?php echo __('No menu rows.'); ?></em></p>
            <?php else: ?>
                <table class="widget-table">
                    <thead>
                        <tr>
                            <th><?php echo __('Box'); ?></th>
                            <th><?php echo __('Sort'); ?></th>
                            <th><?php echo __('Name'); ?></th>
                            <th><?php echo __('Link'); ?></th>
                            <th><?php echo __('Permission'); ?></th>
                            <th><?php echo __('Visible'); ?></th>
                            <th><?php echo __('Actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $prevBox = null;
                        foreach ($rows as $r):
                            $box  = (int) $r['box'];
                            $sort = (int) $r['sort'];
                            $hide = (string) $r['hide'];
                            $visible = $hide !== 'Y';
                            // Visual separator between boxes -- a wider
                            // gap row so the user can tell boxes apart.
                            if ($prevBox !== null && $prevBox !== $box):
                        ?>
                            <tr class="pmwh3-menu-box-sep"><td colspan="7">&nbsp;</td></tr>
                        <?php
                            endif;
                            $prevBox = $box;
                        ?>
                            <tr>
                                <td><?php echo $box; ?></td>
                                <td><?php echo $sort; ?></td>
                                <td><strong><?php echo htmlspecialchars((string) $r['name']); ?></strong></td>
                                <td><code><?php echo htmlspecialchars((string) $r['link']); ?></code></td>
                                <td><code><?php echo htmlspecialchars((string) $r['permission']); ?></code></td>
                                <td>
                                    <?php if ($visible): ?>
                                        <span class="pmwh3-yes">&#10003;</span>
                                    <?php else: ?>
                                        <span class="pmwh3-no">&#10007;</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($edit_perm): ?>
                                        <a href="<?php echo BASE_URI; ?>pmwh3/tools/edit_menu_row/<?php echo $box; ?>/<?php echo $sort; ?>"
                                           class="button small-action edit"><?php echo __('Edit'); ?></a>
                                        <form method="post"
                                              action="<?php echo BASE_URI; ?>pmwh3/tools/toggle_menu_row/<?php echo $box; ?>/<?php echo $sort; ?>"
                                              class="inline-form"
                                              data-confirm="<?php echo $visible ? __('Hide this menu row?') : __('Show this menu row?'); ?>"
                                              data-confirm-type="change">
                                            <button type="submit" class="button small-action yellow">
                                                <?php echo $visible ? __('Hide') : __('Show'); ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($delete_perm): ?>
                                    <form method="post"
                                          action="<?php echo BASE_URI; ?>pmwh3/tools/delete_menu_row/<?php echo $box; ?>/<?php echo $sort; ?>"
                                          class="inline-form"
                                          data-confirm="<?php echo sprintf(__('Delete menu row "%s"?'), htmlspecialchars((string) $r['name'])); ?>"
                                          data-confirm-type="delete">
                                            <button type="submit" class="button small-action delete"><?php echo __('Delete'); ?></button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

        </div>
    </div>
</div>
