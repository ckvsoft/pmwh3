<div class="pmwh3-content">
<?php
/** @var array $data rows, perRow, perms */
$rows  = $this->data['rows'] ?? [];
$perRow = (int) ($this->data['perRow'] ?? 4);
$perms = $this->data['perms'] ?? [];
?>
<div class="widget">
    <div class="widget-header">
        <h3><?php echo __('Applications'); ?></h3>
        <a href="<?php echo BASE_URI; ?>pmwh3/tools/overview"
           class="button small-action cancel"><?php echo __('Back'); ?></a>
        <?php if (!empty($perms['create_application'])): ?>
            <a href="<?php echo BASE_URI; ?>pmwh3/tools/new_application"
               class="button small-action create"><?php echo __('New application'); ?></a>
        <?php endif; ?>
    </div>
    <div class="widget-body">
        <?php if (empty($rows)): ?>
            <p><?php echo __('No applications defined yet.'); ?></p>
        <?php else: ?>
            <table class="widget-table">
                <thead>
                    <tr>
                        <th><?php echo __('Sort'); ?></th>
                        <th><?php echo __('Name'); ?></th>
                        <th><?php echo __('Link'); ?></th>
                        <th><?php echo __('Actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): $idE = (int) $r['id']; ?>
                        <tr>
                            <td><?php echo (int) ($r['sort'] ?? 0); ?></td>
                            <td><?php echo htmlspecialchars((string) ($r['name'] ?? '')); ?></td>
                            <td>
                                <a href="<?php echo htmlspecialchars((string) ($r['link'] ?? '')); ?>"
                                   target="_blank" rel="noopener noreferrer">
                                    <?php echo htmlspecialchars((string) ($r['link'] ?? '')); ?>
                                </a>
                            </td>
                            <td>
                                <?php if (!empty($perms['edit_application'])): ?>
                                    <a href="<?php echo BASE_URI; ?>pmwh3/tools/edit_application/<?php echo $idE; ?>"
                                       class="button small-action edit"><?php echo __('Edit'); ?></a>
                                <?php endif; ?>
                                <?php if (!empty($perms['delete_application'])): ?>
                                    <form action="<?php echo BASE_URI; ?>pmwh3/tools/delete_application/<?php echo $idE; ?>"
                                          method="post" class="inline-form"
                                          data-confirm="<?php echo __('Delete application?'); ?>"
                                          data-confirm-type="delete">
                                        <button type="submit" class="button small-action delete"><?php echo __('Delete'); ?></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p><small><?php echo sprintf(__('Shown %d per row in the customer Applications view (APPS_PER_ROW).'), (int) $perRow); ?></small></p>
        <?php endif; ?>
    </div>
</div>
</div>
