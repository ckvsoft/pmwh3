<?php
$domains   = (array)  ($this->data['domains']   ?? []);
$targetTab = (string) ($this->data['targetTab'] ?? 'email');
?>
<div class="pmwh3-content">
    <div class="widget">
        <div class="widget-header">
            <h3><?php echo __('Pick a domain'); ?></h3>
        </div>
        <div class="widget-body">

            <p><?php echo __('Pick a domain to manage its email. The selection is remembered for the rest of the session.'); ?></p>

            <?php if (empty($domains)): ?>
                <p><em><?php echo __('No mail-enabled domains visible to you.'); ?></em></p>
            <?php else: ?>
                <table class="widget-table">
                    <thead>
                        <tr>
                            <th><?php echo __('Domain'); ?></th>
                            <th><?php echo __('Customer'); ?></th>
                            <th><?php echo __('Mailboxes'); ?></th>
                            <th><?php echo __('Action'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($domains as $d):
                            $dn = (string) ($d['domain'] ?? '');
                        ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($dn); ?></strong></td>
                                <td><?php echo htmlspecialchars((string) ($d['customer'] ?? '')); ?></td>
                                <td><?php echo (int) ($d['mailbox_count'] ?? 0); ?></td>
                                <td>
                                    <a href="<?php echo BASE_URI; ?>pmwh3/email/pick/<?php echo urlencode($dn); ?>/<?php echo urlencode($targetTab); ?>"
                                       class="button small-action edit"><?php echo __('Pick'); ?></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

        </div>
    </div>
</div>
