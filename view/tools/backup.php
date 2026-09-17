<div class="pmwh3-content">
<?php
/** @var array $data rows, perms */
$rows  = $this->data['rows'] ?? [];
$perms = $this->data['perms'] ?? [];
?>
<div class="widget">
    <div class="widget-header">
        <h3><?php echo __('Database backup'); ?></h3>
        <a href="<?php echo BASE_URI; ?>pmwh3/tools/overview"
           class="button small-action cancel"><?php echo __('Back'); ?></a>
        <?php if (!empty($perms['create_backup'])): ?>
            <form action="<?php echo BASE_URI; ?>pmwh3/tools/do_backup"
                  method="post" class="inline-form"
                  data-confirm="<?php echo __('Create a backup now?'); ?>"
                  data-confirm-type="change">
                <button type="submit" class="button small-action create"><?php echo __('Backup now'); ?></button>
            </form>
        <?php endif; ?>
    </div>
    <div class="widget-body">

        <?php if (empty($rows)): ?>
            <p><?php echo __('No backups yet.'); ?></p>
        <?php else: ?>
            <div class="paginated" data-per-page="20">
            <table class="widget-table">
                <thead>
                    <tr>
                        <th><?php echo __('Filename'); ?></th>
                        <th><?php echo __('Date'); ?></th>
                        <th><?php echo __('Size'); ?></th>
                        <th><?php echo __('Actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): $n = (string) $r['name']; ?>
                        <tr>
                            <td><?php echo htmlspecialchars($n); ?></td>
                            <td><?php echo htmlspecialchars((string) $r['date']); ?></td>
                            <td><?php echo (int) $r['size']; ?> KB</td>
                            <td>
                                <a href="<?php echo BASE_URI; ?>pmwh3/tools/download_backup/<?php echo urlencode($n); ?>"
                                   class="button small-action yellow"><?php echo __('Download'); ?></a>
                                <?php if (!empty($perms['create_backup'])): ?>
                                    <form action="<?php echo BASE_URI; ?>pmwh3/tools/delete_backup"
                                          method="post" class="inline-form"
                                          data-confirm="<?php echo __('Delete backup?'); ?>"
                                          data-confirm-type="delete">
                                        <input type="hidden" name="file" value="<?php echo htmlspecialchars($n); ?>">
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
        <p><small><?php echo __('Backups land in the configured BACKUP_DIR (shadow directory, outside the source tree).'); ?></small></p>
    </div>
</div>
</div>
