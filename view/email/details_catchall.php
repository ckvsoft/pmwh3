<div class="pmwh3-content">
<?php
$domain  = $this->data['domain'] ?? '';
$rows    = $this->data['rows']   ?? [];
$perms   = $this->data['perms']  ?? [];
$current = $rows[0] ?? null;

// Stored as comma-separated; show one-per-line in the textarea
// and in the current-status block.
$currentDest = (string) ($current['destination'] ?? '');
$destList    = array_filter(array_map('trim',
        preg_split('/[\r\n,]+/', $currentDest) ?: []));
$destText    = implode("\n", $destList);
?>
<div class="widget">
    <div class="widget-header">
        <h3><?php echo sprintf(__('Catchall — %s'), htmlspecialchars($domain)); ?></h3>
        <a href="<?php echo BASE_URI; ?>pmwh3/email/overview"
           class="button small-action cancel"><?php echo __('Back'); ?></a>
    </div>
    <div class="widget-body">

        <?php if ($current && !empty($destList)): ?>
            <p><?php echo __('Currently forwarding all mail for this domain to:'); ?></p>
            <ul>
                <?php foreach ($destList as $d): ?>
                    <li><strong><?php echo htmlspecialchars($d); ?></strong></li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p><?php echo __('No catchall set for this domain.'); ?></p>
        <?php endif; ?>

        <?php if (!empty($perms['create_email_catchall']) || !empty($perms['change_email_catchall'])): ?>
            <form method="post" action="<?php echo BASE_URI; ?>pmwh3/email/set_catchall" data-confirm="<?php echo __($current ? 'Change catchall?' : 'Set catchall?'); ?>" data-confirm-type="change">
                <table class="widget-table">
                    <tr>
                        <td class="pmwh3-cell-top"><?php echo __('Forward all mail to'); ?>
                            <br><small><em><?php echo __('One email address per line.'); ?></em></small>
                        </td>
                        <td><textarea name="destinations" rows="6" cols="40" required
                                      placeholder="user1@example.com&#10;user2@example.com"><?php
                                echo htmlspecialchars($destText);
                            ?></textarea></td>
                    </tr>
                </table>
                <div class="form-actions">
                    <button type="submit" class="button small-action save">
                        <?php echo $current ? __('Update') : __('Set'); ?>
                    </button>
                </div>
            </form>
        <?php endif; ?>

        <?php if ($current && !empty($perms['delete_email_catchall'])): ?>
            <form action="<?php echo BASE_URI; ?>pmwh3/email/delete_catchall"
                  method="post"
                  data-confirm="<?php echo __('Remove catchall?'); ?>"
                  data-confirm-type="delete">
                <div class="form-actions">
                    <button type="submit" class="button small-action delete"><?php echo __('Remove catchall'); ?></button>
                </div>
            </form>
        <?php endif; ?>

    </div>
</div>
</div>
