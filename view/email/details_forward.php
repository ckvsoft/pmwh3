<div class="pmwh3-content">
<?php
$domain = $this->data['domain'] ?? '';
$rows   = $this->data['rows']   ?? [];
$perms  = $this->data['perms']  ?? [];
?>
<div class="widget">
    <div class="widget-header">
        <h3><?php echo sprintf(__('Forwards — %s'), htmlspecialchars($domain)); ?></h3>
        <a href="<?php echo BASE_URI; ?>pmwh3/email/overview"
           class="button small-action cancel"><?php echo __('Back'); ?></a>
        <?php if (!empty($perms['create_email_forward'])): ?>
            <a href="<?php echo BASE_URI; ?>pmwh3/email/new_forward"
               class="button small-action create"><?php echo __('New forward'); ?></a>
        <?php endif; ?>
    </div>
    <div class="widget-body">

        <?php if (empty($rows)): ?>
            <p><?php echo __('No forwards for this domain.'); ?></p>
        <?php else: ?>
            <div class="paginated" data-per-page="20">
<table class="widget-table">
                <thead>
                    <tr>
                        <th><?php echo __('Source'); ?></th>
                        <th><?php echo __('Destination'); ?></th>
                        <th><?php echo __('Actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r):
                        $source = (string) ($r['source'] ?? '');
                        $sourceEnc = urlencode($source);
                        $destRaw = (string) ($r['destination'] ?? '');
                        $destList = array_filter(array_map('trim',
                                preg_split('/[\r\n,]+/', $destRaw) ?: []));
                    ?>
                        <tr>
                            <td><?php echo htmlspecialchars($source); ?></td>
                            <td>
                                <?php foreach ($destList as $d): ?>
                                    <div><?php echo htmlspecialchars($d); ?></div>
                                <?php endforeach; ?>
                            </td>
                            <td>
                                <?php if (!empty($perms['edit_email_forward']) || !empty($perms['change_email_forward'])): ?>
                                    <a href="<?php echo BASE_URI; ?>pmwh3/email/edit_forward/<?php echo $sourceEnc; ?>"
                                       class="button small-action edit"><?php echo __('Edit'); ?></a>
                                <?php endif; ?>
                                <?php if (!empty($perms['delete_email_forward'])): ?>
                                    <form action="<?php echo BASE_URI; ?>pmwh3/email/delete_forward/<?php echo $sourceEnc; ?>"
                                          method="post" class="inline-form"
                                          data-confirm="<?php echo __('Delete forward?'); ?>"
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
