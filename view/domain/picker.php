<?php
$domains     = (array)  ($this->data['domains']     ?? []);
$targetTab   = (string) ($this->data['targetTab']   ?? 'overview');
$targetRoute = (string) ($this->data['targetRoute'] ?? 'domain/details');
?>
<div class="pmwh3-content">
    <div class="widget">
        <div class="widget-header">
            <h3><?php echo __('Pick a domain'); ?></h3>
        </div>
        <div class="widget-body">

            <p><?php echo __('Pick a domain to continue. The selection is remembered in the session and applies to all detail views.'); ?></p>

            <?php if (empty($domains)): ?>
                <p><em><?php echo __('No domains visible to you.'); ?></em></p>
            <?php else: ?>
                <table class="widget-table">
                    <thead>
                        <tr>
                            <th><?php echo __('Domain'); ?></th>
                            <th><?php echo __('Customer'); ?></th>
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
                                <td>
                                    <a href="<?php echo BASE_URI . 'pmwh3/' . $targetRoute . '/' . urlencode($dn) . '/' . urlencode($targetTab); ?>"
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
