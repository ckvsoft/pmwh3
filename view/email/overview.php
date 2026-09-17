<div class="pmwh3-content">
<?php
/**
 * @var array $data  domains, current
 */
$domains = $this->data['domains'] ?? [];
$current = $this->data['current'] ?? null;
?>
<div class="widget">
    <div class="widget-header">
        <h3><?php echo __('Email — Domains'); ?></h3>
    </div>
    <div class="widget-body">

        <?php if ($current): ?>
            <p><?php echo sprintf(__('Currently active: <strong>%s</strong>'),
                    htmlspecialchars($current)); ?></p>
        <?php else: ?>
            <p><?php echo __('Pick a domain to manage its email.'); ?></p>
        <?php endif; ?>

        <?php if (empty($domains)): ?>
            <p><?php echo __('No mail-enabled domains visible.'); ?></p>
        <?php else: ?>
            <div class="paginated" data-per-page="20">
<table class="widget-table">
                <thead>
                    <tr>
                        <th><?php echo __('Domain'); ?></th>
                        <th><?php echo __('Customer'); ?></th>
                        <th><?php echo __('Mailboxes'); ?></th>
                        <th><?php echo __('Forwards'); ?></th>
                        <th><?php echo __('Catchall'); ?></th>
                        <th><?php echo __('Action'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($domains as $d):
                        $isCurrent = $current === $d['domain'];
                    ?>
                        <tr <?php echo $isCurrent ? 'class="row-current"' : ''; ?>>
                            <td><strong><?php echo htmlspecialchars((string) $d['domain']); ?></strong></td>
                            <td><?php echo htmlspecialchars((string) ($d['customer'] ?? '')); ?></td>
                            <td><?php echo (int) ($d['mailbox_count'] ?? 0); ?></td>
                            <td><?php echo (int) ($d['forward_count'] ?? 0); ?></td>
                            <td><?php echo htmlspecialchars((string) ($d['catchall'] ?? '—')); ?></td>
                            <td>
                                <?php if (!$isCurrent): ?>
                                    <a href="<?php echo BASE_URI; ?>pmwh3/email/pick/<?php echo urlencode((string) $d['domain']); ?>"
                                       class="button small-action edit"><?php echo __('Pick'); ?></a>
                                <?php else: ?>
                                    <a href="<?php echo BASE_URI; ?>pmwh3/email/details/email"
                                       class="button small-action edit"><?php echo __('Manage'); ?></a>
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
