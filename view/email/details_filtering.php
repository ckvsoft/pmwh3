<div class="pmwh3-content">
<?php
/**
 * Filtering tab: per-scope tag/kill thresholds with inheritance
 * (NULL = erben). Rows are the pmwh3_filtering table entries.
 *
 * @var array $data  domain, view, rows, perms, chainLabel
 */
$domain = $this->data['domain'] ?? '';
$rows   = $this->data['rows']   ?? [];
$perms  = $this->data['perms']  ?? [];
?>
<div class="widget">
    <div class="widget-header">
        <h3><?php echo sprintf(__('Filtering — %s'), htmlspecialchars($domain)); ?></h3>
        <a href="<?php echo BASE_URI; ?>pmwh3/email/overview"
           class="button small-action cancel"><?php echo __('Back'); ?></a>
        <?php if (!empty($perms['create_email_filtering'])): ?>
            <a href="<?php echo BASE_URI; ?>pmwh3/filtering/new_filtering"
               class="button small-action create"><?php echo __('New policy'); ?></a>
        <?php endif; ?>
    </div>
    <div class="widget-body">

        <p><small><?php echo __('Empty value = inherit from the next coarser scope (@Mailbox -> @Domain -> global).'); ?></small></p>

        <?php if (empty($rows)): ?>
            <p><?php echo __('No policies defined yet.'); ?></p>
        <?php else: ?>
            <div class="paginated" data-per-page="20">
            <table class="widget-table">
                <thead>
                    <tr>
                        <th><?php echo __('Scope'); ?></th>
                        <th><?php echo __('Spam tag score'); ?></th>
                        <th><?php echo __('Reject score'); ?></th>
                        <th><?php echo __('Updated'); ?></th>
                        <th><?php echo __('Actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r):
                    $scope = (string) $r['scope'];
                    $idE   = (int) $r['id'];
                    $tag   = $r['tag_threshold'];
                    $kill  = $r['kill_threshold'];
                    $tagLabel = ($tag === null || $tag === '') ? __('(inherit)') : (string) $tag;
                    $killLabel= ($kill === null || $kill === '') ? __('(inherit)') : (string) $kill;
                ?>
                    <tr>
                        <td><?php echo htmlspecialchars($scope === '@.' ? __('global') : $scope); ?></td>
                        <td><?php echo htmlspecialchars($tagLabel); ?></td>
                        <td><?php echo htmlspecialchars($killLabel); ?></td>
                        <td><?php echo htmlspecialchars((string) ($r['updated_at'] ?? '')); ?></td>
                        <td>
                            <?php if ($scope !== '@.' && !empty($perms['edit_email_filtering'])): ?>
                                <a href="<?php echo BASE_URI; ?>pmwh3/filtering/edit_filtering/<?php echo $idE; ?>"
                                   class="button small-action edit"><?php echo __('Edit'); ?></a>
                            <?php endif; ?>
                            <?php if ($scope !== '@.' && !empty($perms['delete_email_filtering'])): ?>
                                <form action="<?php echo BASE_URI; ?>pmwh3/filtering/delete_filtering/<?php echo $idE; ?>"
                                      method="post" class="inline-form"
                                      data-confirm="<?php echo __('Delete policy?'); ?>"
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
