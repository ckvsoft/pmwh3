<?php
$row     = (array) ($this->data['row']      ?? []);
$domains = (array) ($this->data['domains']  ?? []);
$quotaMb = (int)   ($this->data['quota_mb'] ?? 0);
$isNew   = (bool)  ($this->data['is_new']   ?? true);

$username = (string) ($row['username'] ?? '');
$action   = $isNew ? 'insert_account' : 'change_account';
$title    = $isNew ? __('New FTP account') : sprintf(__('Edit FTP account: %s'), $username);
?>
<div class="pmwh3-content">
    <div class="widget">
        <div class="widget-header">
            <h3><?php echo htmlspecialchars($title); ?></h3>
            <a href="<?php echo BASE_URI; ?>pmwh3/ftp/overview"
               class="button small-action cancel"><?php echo __('Back'); ?></a>
        </div>
        <div class="widget-body">

            <form id="ftp_form" action="<?php echo BASE_URI; ?>pmwh3/ftp/<?php echo $action; ?>"
                  method="post" data-redirect="pmwh3/ftp/overview"
                  data-confirm="<?php echo $isNew ? __('Create FTP account?') : __('Save FTP account changes?'); ?>"
                  data-confirm-type="change">

                <table class="widget-table">
                    <tr>
                        <td><?php echo __('Username'); ?></td>
                        <td>
                            <?php if ($isNew): ?>
                                <input name="config[username]" required>
                                <small><?php echo __('e.g. user@example.com or just user'); ?></small>
                            <?php else: ?>
                                <strong><?php echo htmlspecialchars($username); ?></strong>
                                <input type="hidden" name="config[username]"
                                       value="<?php echo htmlspecialchars($username); ?>">
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><?php echo __('Password'); ?></td>
                        <td>
                            <input type="password" name="config[password]"
                                   <?php echo $isNew ? 'required' : ''; ?>>
                            <?php if (!$isNew): ?>
                                <small><?php echo __('Leave blank to keep the current password'); ?></small>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><?php echo __('Domain'); ?></td>
                        <td>
                            <select name="config[domain]">
                                <option value=""><?php echo __('— none —'); ?></option>
                                <?php
                                $currentDomain = (string) ($row['domain'] ?? '');
                                foreach ($domains as $d):
                                    $dn = (string) ($d['domain'] ?? '');
                                ?>
                                    <option value="<?php echo htmlspecialchars($dn); ?>"
                                            <?php if ($dn === $currentDomain) echo 'selected'; ?>>
                                        <?php echo htmlspecialchars($dn); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <td><?php echo __('Home directory'); ?></td>
                        <td>
                            <input name="config[homedir]" size="60" required
                                   value="<?php echo htmlspecialchars((string) ($row['homedir'] ?? '')); ?>">
                            <small><?php echo __('Absolute path on the server, e.g. /vhome/customer/example.com/www'); ?></small>
                        </td>
                    </tr>
                    <tr>
                        <td><?php echo __('Quota (MB)'); ?></td>
                        <td>
                            <input type="number" name="config[quota_mb]" min="0"
                                   value="<?php echo $quotaMb; ?>">
                            <small><?php echo __('0 = unlimited'); ?></small>
                        </td>
                    </tr>
                </table>

                <div class="pmwh3-form-actions">
                    <button type="submit" class="button small-action <?php echo $isNew ? 'create' : 'save'; ?>"><?php echo $isNew ? __('Create') : __('Save'); ?></button>
                </div>
            </form>

        </div>
    </div>
</div>
