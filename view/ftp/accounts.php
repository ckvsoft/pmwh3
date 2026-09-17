<?php
$accounts    = (array) ($this->data['accounts']    ?? []);
$domain      = (string) ($this->data['domain']     ?? '');
$create_perm = (bool)  ($this->data['create_perm'] ?? false);
$edit_perm   = (bool)  ($this->data['edit_perm']   ?? false);
$delete_perm = (bool)  ($this->data['delete_perm'] ?? false);

/**
 * Format bytes into MB / GB human-readable, falls back to bytes
 * for small values. Used for the traffic counters.
 */
$fmt = function ($bytes): string {
    $bytes = (int) $bytes;
    if ($bytes <= 0) return '0';
    if ($bytes >= 1024 * 1024 * 1024) {
        return number_format($bytes / 1024 / 1024 / 1024, 2) . ' GB';
    }
    if ($bytes >= 1024 * 1024) {
        return number_format($bytes / 1024 / 1024, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 1) . ' KB';
    }
    return $bytes . ' B';
};
?>
<div class="pmwh3-content">
    <div class="widget">
        <div class="widget-header">
            <h3>
                <?php echo __('FTP accounts'); ?>
                <?php if ($domain !== ''): ?>
                    &mdash; <?php echo htmlspecialchars($domain); ?>
                <?php endif; ?>
            </h3>
            <span>
                <a href="<?php echo BASE_URI; ?>pmwh3/ftp/overview"
                   class="button small-action cancel"><?php echo __('Back'); ?></a>
                <?php if ($create_perm && $domain !== ''): ?>
                    <a href="<?php echo BASE_URI; ?>pmwh3/ftp/new_account"
                       class="button small-action create"><?php echo __('New account'); ?></a>
                <?php endif; ?>
            </span>
        </div>
        <div class="widget-body">

            <?php if ($domain === ''): ?>
                <p><em><?php echo __('Pick a domain first.'); ?></em></p>
            <?php elseif (empty($accounts)): ?>
                <p><em><?php echo __('No FTP accounts.'); ?></em></p>
            <?php else: ?>
                <div class="paginated" data-per-page="20">
                <table class="widget-table">
                    <thead>
                        <tr>
                            <th><?php echo __('Username'); ?></th>
                            <th><?php echo __('Home directory'); ?></th>
                            <th><?php echo __('UID/GID'); ?></th>
                            <th><?php echo __('Last access'); ?></th>
                            <th><?php echo __('Traffic in/out'); ?></th>
                            <?php if ($edit_perm || $delete_perm): ?>
                                <th><?php echo __('Actions'); ?></th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($accounts as $a):
                            $uname = (string) ($a['username'] ?? '');
                            $accessed = (string) ($a['accessed'] ?? '');
                            // "1000-01-01" is the proftpd "never used" sentinel.
                            $accessedDisplay = (str_starts_with($accessed, '1000-')
                                    || $accessed === '') ? '—' : $accessed;
                        ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($uname); ?></strong></td>
                                <td><small><?php echo htmlspecialchars((string) ($a['homedir'] ?? '')); ?></small></td>
                                <td><?php echo (int) ($a['uid'] ?? 0); ?> / <?php echo (int) ($a['gid'] ?? 0); ?></td>
                                <td><?php echo htmlspecialchars($accessedDisplay); ?>
                                    <?php if (!empty($a['count'])): ?>
                                        <small>(<?php echo (int) $a['count']; ?>×)</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <small>
                                    <?php echo htmlspecialchars($fmt($a['bytes_in']  ?? 0)); ?>
                                    /
                                    <?php echo htmlspecialchars($fmt($a['bytes_out'] ?? 0)); ?>
                                    </small>
                                </td>
                                <?php if ($edit_perm || $delete_perm): ?>
                                    <td>
                                        <?php if ($edit_perm): ?>
                                            <a href="<?php echo BASE_URI; ?>pmwh3/ftp/edit_account/<?php echo urlencode($uname); ?>"
                                               class="button small-action edit"><?php echo __('Edit'); ?></a>
                                        <?php endif; ?>
                                        <?php if ($delete_perm): ?>
                                            <a href="<?php echo BASE_URI; ?>pmwh3/ftp/delete_account/<?php echo urlencode($uname); ?>"
                                               class="button small-action delete"
                                               data-confirm="<?php echo __('Delete this FTP account?'); ?>"
                                               data-confirm-type="delete"><?php echo __('Delete'); ?></a>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>

        </div>
    </div>
</div>
