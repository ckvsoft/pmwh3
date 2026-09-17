<div class="pmwh3-content">
<?php
/** @var array $data rows, perms */
$rows  = $this->data['rows'] ?? [];
$perms = $this->data['perms'] ?? [];
?>
<div class="widget">
    <div class="widget-header">
        <h3><?php echo __('News'); ?></h3>
        <a href="<?php echo BASE_URI; ?>pmwh3/tools/overview"
           class="button small-action cancel"><?php echo __('Back'); ?></a>
        <?php if (!empty($perms['create_news'])): ?>
            <a href="<?php echo BASE_URI; ?>pmwh3/tools/new_news"
               class="button small-action create"><?php echo __('New news'); ?></a>
        <?php endif; ?>
    </div>
    <div class="widget-body">
        <?php if (empty($rows)): ?>
            <p><?php echo __('No news items yet.'); ?></p>
        <?php else: ?>
            <div class="paginated" data-per-page="20">
            <table class="widget-table news-table">
                <thead>
                    <tr>
                        <th><?php echo __('Date'); ?></th>
                        <th><?php echo __('Text'); ?></th>
                        <th><?php echo __('Author'); ?></th>
                        <th><?php echo __('Active'); ?></th>
                        <th><?php echo __('Actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): $idE = (int) $r['id']; ?>
                        <tr>
                            <td><?php echo htmlspecialchars((string) $r['datetime']); ?></td>
                            <td><?php echo nl2br(htmlspecialchars(mb_substr((string) ($r['news'] ?? ''), 0, 200))); ?></td>
                            <td><?php echo htmlspecialchars((string) ($r['author'] ?? '')); ?></td>
                            <td><?php echo (($r['active'] ?? 'N') === 'Y') ? __('yes') : __('no'); ?></td>
                            <td>
                                <?php if (!empty($perms['edit_news'])): ?>
                                    <a href="<?php echo BASE_URI; ?>pmwh3/tools/edit_news/<?php echo $idE; ?>"
                                       class="button small-action edit"><?php echo __('Edit'); ?></a>
                                    <form action="<?php echo BASE_URI; ?>pmwh3/tools/toggle_news/<?php echo $idE; ?>"
                                          method="post" class="inline-form"
                                          data-confirm="<?php echo ((($r['active'] ?? 'N') === 'Y') ? __('Deactivate this news?') : __('Activate this news?')); ?>"
                                          data-confirm-type="change">
                                        <button type="submit" class="button small-action blue">
                                            <?php echo (($r['active'] ?? 'N') === 'Y') ? __('Deactivate') : __('Activate'); ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                                <?php if (!empty($perms['delete_news'])): ?>
                                    <form action="<?php echo BASE_URI; ?>pmwh3/tools/delete_news/<?php echo $idE; ?>"
                                          method="post" class="inline-form"
                                          data-confirm="<?php echo __('Delete news?'); ?>"
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
