<div class="pmwh3-content">
<?php
/**
 * @var array $data  domain, view, rows, perms
 */
$domain = $this->data['domain'] ?? '';
$rows   = $this->data['rows']   ?? [];
$perms  = $this->data['perms']  ?? [];
?>
<div class="widget">
    <div class="widget-header">
        <h3><?php echo sprintf(__('Mailboxes — %s'), htmlspecialchars($domain)); ?></h3>
        <a href="<?php echo BASE_URI; ?>pmwh3/email/overview"
           class="button small-action cancel"><?php echo __('Back'); ?></a>
        <?php if (!empty($perms['create_email_email'])): ?>
            <a href="<?php echo BASE_URI; ?>pmwh3/email/new_email"
               class="button small-action create"><?php echo __('New mailbox'); ?></a>
        <?php endif; ?>
    </div>
    <div class="widget-body">

        <?php if (empty($rows)): ?>
            <p><?php echo __('No mailboxes for this domain.'); ?></p>
        <?php else: ?>
            <div class="paginated" data-per-page="20">
<table class="widget-table">
                <thead>
                    <tr>
                        <th><?php echo __('Email'); ?></th>
                        <th><?php echo __('Quota'); ?></th>
                        <th><?php echo __('Actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r):
                        $email = (string) ($r['email'] ?? '');
                        $emailEnc = urlencode($email);
                        $assigned = (int) ($r['quota_bytes'] ?? 0);
                        $used     = (int) ($r['used_bytes'] ?? 0);
                        $msgs     = (int) ($r['used_messages'] ?? 0);
                        $SC       = \pmwh3\Utils\SizeConverter::class;

                        // Prefer LIVE quota from the doveadm HTTP API -- this
                        // is what mail clients see (dovecot count driver).
                        // Falls back to the dict-quota usage columns (used_bytes/used_messages).
                        $live = \pmwh3\Utils\MailManager::liveQuota($email);
                        if ($live !== null) {
                            $quotaCell = sprintf(
                                    '%s %s %s (%d%%) · %d %s',
                                    $SC::bytesToHumanReadable($live['storage_kb'] * 1024),
                                    __('of'),
                                    $SC::bytesToHumanReadable($live['storage_limit_kb'] * 1024),
                                    (int) $live['percent'],
                                    (int) $live['messages'],
                                    __('msgs'));
                        } elseif ($assigned > 0) {
                            $quotaCell = $SC::bytesToHumanReadable($assigned);
                            if ($used > 0) {
                                $quotaCell = sprintf('%s <em>%s %s</em> (%d%%)',
                                        $SC::bytesToHumanReadable($used),
                                        __('of'),
                                        $quotaCell,
                                        min(999, (int) round($used * 100 / $assigned)));
                                if ($msgs > 0) {
                                    $quotaCell .= ' · ' . sprintf(__('%d msgs'), $msgs);
                                }
                            }
                        } else {
                            $quotaCell = __('unlimited / not set');
                        }
                    ?>
                        <tr>
                            <td><?php echo htmlspecialchars($email); ?></td>
                            <td><?php echo $quotaCell; ?></td>
                            <td>
                                <?php if (!empty($perms['edit_email_email'])): ?>
                                    <a href="<?php echo BASE_URI; ?>pmwh3/email/change_email/<?php echo $emailEnc; ?>"
                                       class="button small-action edit"><?php echo __('Edit'); ?></a>
                                <?php endif; ?>
                                <?php if (!empty($perms['change_email_password'])): ?>
                                    <a href="<?php echo BASE_URI; ?>pmwh3/email/change_mailbox_password/<?php echo $emailEnc; ?>"
                                       class="button small-action yellow"><?php echo __('Password'); ?></a>
                                <?php endif; ?>
                                <?php if (!empty($perms['delete_email_email'])): ?>
                                    <form action="<?php echo BASE_URI; ?>pmwh3/email/delete_email/<?php echo $emailEnc; ?>"
                                          method="post" class="inline-form"
                                          data-confirm="<?php echo __('Delete mailbox?'); ?>"
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
