<div class="pmwh3-content">
<?php
/**
 * WBList tab: recipient whitelist/blacklist rows (amavis W/B
 * semantics) — the actual multimap output is served by
 * pmwh3/filtering/map_wblist/{W|B}.
 *
 * @var array $data  domain, view, rowsWhite, rowsBlack, wRowsGlobal, perms
 */
$domain    = $this->data['domain'] ?? '';
$perms     = $this->data['perms']  ?? [];
$sections  = [
    'W' => [
        'title' => __('Whitelist'),
        'rows'  => $this->data['rowsWhite'] ?? [],
    ],
    'B' => [
        'title' => __('Blacklist'),
        'rows'  => $this->data['rowsBlack'] ?? [],
    ],
];
?>
<div class="widget">
    <div class="widget-header">
        <h3><?php echo sprintf(__('Whitelist / Blacklist — %s'), htmlspecialchars($domain)); ?></h3>
        <a href="<?php echo BASE_URI; ?>pmwh3/email/overview"
           class="button small-action cancel"><?php echo __('Back'); ?></a>
        <?php if (!empty($perms['create_email_wblist'])): ?>
            <a href="<?php echo BASE_URI; ?>pmwh3/filtering/new_wblist"
               class="button small-action create"><?php echo __('New entry'); ?></a>
        <?php endif; ?>
    </div>
    <div class="widget-body">

        <?php foreach ($sections as $key => $sec): ?>
            <h4><?php echo htmlspecialchars($sec['title']); ?></h4>
            <?php if (empty($sec['rows'])): ?>
                <p><small><?php echo __('No entries.'); ?></small></p>
            <?php else: ?>
                <div class="paginated" data-per-page="20">
                <table class="widget-table">
                    <thead>
                        <tr>
                            <th><?php echo __('Scope'); ?></th>
                            <th><?php echo __('Address'); ?></th>
                            <th><?php echo __('Comment'); ?></th>
                            <th><?php echo __('Actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($sec['rows'] as $r):
                        $idE = (int) $r['id'];
                        $scopeLabel = ($r['scope'] ?? '') === '@.' ? __('global') : (string) $r['scope'];
                    ?>
                        <tr>
                            <td><?php echo htmlspecialchars($scopeLabel); ?></td>
                            <td><?php echo htmlspecialchars((string) $r['address']); ?></td>
                            <td><?php echo htmlspecialchars((string) ($r['comment'] ?? '')); ?></td>
                            <td>
                                <?php if (!empty($perms['edit_email_wblist'])): ?>
                                    <a href="<?php echo BASE_URI; ?>pmwh3/filtering/edit_wblist/<?php echo $idE; ?>"
                                       class="button small-action edit"><?php echo __('Edit'); ?></a>
                                <?php endif; ?>
                                <?php if (!empty($perms['delete_email_wblist'])): ?>
                                <form action="<?php echo BASE_URI; ?>pmwh3/filtering/delete_wblist/<?php echo $idE; ?>"
                                      method="post" class="inline-form"
                                      data-confirm="<?php echo __('Delete entry?'); ?>"
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
        <?php endforeach; ?>
    </div>
</div>
</div>
