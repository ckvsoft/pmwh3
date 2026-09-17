<?php
$mode       = (string) ($this->data['mode']       ?? 'select');
$customers  = (array)  ($this->data['customers']  ?? []);
$ips        = (array)  ($this->data['ips']        ?? []);
$reg_start  = (string) ($this->data['reg_start']  ?? '');
$reg_end    = (string) ($this->data['reg_end']    ?? '');
$host_start = (string) ($this->data['host_start'] ?? '');
$host_end   = (string) ($this->data['host_end']   ?? '');
?>
<div class="pmwh3-content">
    <div class="widget">
        <div class="widget-header">
            <h3><?php echo __('New domain'); ?></h3>
            <?php if ($mode === 'select'): ?>
                <a href="<?php echo BASE_URI; ?>pmwh3/domain/overview"
                   class="button small-action cancel"><?php echo __('Back'); ?></a>
            <?php endif; ?>
        </div>
        <div class="widget-body">

            <?php if ($mode === 'select'): ?>

                <p><?php echo __('Choose how you want to add domains:'); ?></p>
                <form method="post" action="<?php echo BASE_URI; ?>pmwh3/domain/new_domain"
                      class="inline-form">
                    <input type="hidden" name="how" value="single">
                    <button type="submit" class="button small-action create"><?php echo __('Single'); ?></button>
                </form>
                <form method="post" action="<?php echo BASE_URI; ?>pmwh3/domain/new_domain"
                      class="inline-form">
                    <input type="hidden" name="how" value="bulk">
                    <button type="submit" class="button small-action cancel"><?php echo __("Bulk import"); ?></button>
                </form>

            <?php elseif ($mode === 'single'): ?>

                <form method="post" action="<?php echo BASE_URI; ?>pmwh3/domain/insert_domain" data-confirm="<?php echo __('Create domain?'); ?>" data-confirm-type="change">
                    <input type="hidden" name="config[how]" value="single">
                    <table class="widget-table">
                        <tr><td><?php echo __('Customer'); ?></td>
                            <td>
                                <select name="config[cid]" required>
                                    <?php foreach ($customers as $cid => $name): ?>
                                        <option value="<?php echo (int) $cid; ?>"><?php echo htmlspecialchars((string) $name); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td></tr>
                        <tr><td><?php echo __('Domain'); ?></td>
                            <td><input type="text" name="config[domain]" required
                                       placeholder="example.com"></td></tr>
                        <tr><td><?php echo __('IP Address'); ?></td>
                            <td>
                                <select name="config[ip]">
                                    <option value=""><?php echo __('(none)'); ?></option>
                                    <?php foreach ($ips as $ip): ?>
                                        <option value="<?php echo htmlspecialchars((string) $ip); ?>"><?php echo htmlspecialchars((string) $ip); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td></tr>
                        <tr><td><?php echo __('Services'); ?></td>
                            <td>
                                <label><input type="checkbox" name="config[services_web]"  value="1" checked> <?php echo __('Web'); ?></label>&nbsp;
                                <label><input type="checkbox" name="config[services_mail]" value="1" checked> <?php echo __('Mail'); ?></label>&nbsp;
                                <label><input type="checkbox" name="config[services_dns]"  value="1" checked> <?php echo __('DNS'); ?></label>
                                <small><?php echo __('Web -> webroot + apache vhost. DNS -> zone with default records. Mail -> reserved (mailboxes added separately).'); ?></small>
                            </td></tr>
                        <tr><td><?php echo __('Registration start'); ?></td>
                            <td><input type="date" name="config[reg_start]"
                                       value="<?php echo htmlspecialchars($reg_start); ?>"></td></tr>
                        <tr><td><?php echo __('Registration end'); ?></td>
                            <td><input type="date" name="config[reg_end]"
                                       value="<?php echo htmlspecialchars($reg_end); ?>"></td></tr>
                        <tr><td><?php echo __('Hosting start'); ?></td>
                            <td><input type="date" name="config[host_start]"
                                       value="<?php echo htmlspecialchars($host_start); ?>"></td></tr>
                        <tr><td><?php echo __('Hosting end'); ?></td>
                            <td><input type="date" name="config[host_end]"
                                       value="<?php echo htmlspecialchars($host_end); ?>"></td></tr>
                    </table>

                    <div class="form-actions">
                        <a href="<?php echo BASE_URI; ?>pmwh3/domain/overview"
                           class="button small-action cancel"><?php echo __('Cancel'); ?></a>
                        <button type="submit" class="button small-action save"><?php echo __('Create'); ?></button>
                    </div>
                </form>

            <?php elseif ($mode === 'bulk'): ?>

                <p><em><?php echo __('Bulk import not yet implemented.'); ?></em></p>
                <p><a href="<?php echo BASE_URI; ?>pmwh3/domain/new_domain"
                      class="button small-action cancel"><?php echo __('Back to single'); ?></a></p>

            <?php endif; ?>

        </div>
    </div>
</div>
