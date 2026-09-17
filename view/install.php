<div class="pmwh3-content">
    <div class="widget">
        <div class="entry tool">
            <h2><?php echo __('Install pmwh3'); ?></h2>

            <table>
                <tr><th colspan="2"><?php echo __('Requirements'); ?></th></tr>
                <?php foreach (($this->data['sysChecks']['rows'] ?? []) as $ic): ?>
                    <tr>
                        <td style="white-space: nowrap; float:left;">
                            <strong style="color: <?php echo $ic['ok'] ? 'green' : 'red'; ?>; font-weight: bold;">
                                <?php echo $ic['ok'] ? 'OK' : 'FAIL'; ?>
                            </strong>
                        </td>
                        <td><?php echo htmlspecialchars((string) $ic['label']); ?>
                            &mdash; <?php echo htmlspecialchars($ic['detail']); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!($this->data['sysChecks']['ok'] ?? true)): ?>
                    <tr><td colspan="2">
                        <small><?php echo __('Fix the failed requirements and reload this page -- Install cannot run until then.'); ?></small>
                    </td></tr>
                <?php endif; ?>
            </table>

            <form autocomplete="off" action="<?= BASE_URI ?>pmwh3/install/run" method="post" data-confirm="<?php echo __('Run the pmwh3 install? This writes the module config, creates the schema, RBAC roles and the admin user.'); ?>" data-confirm-type="change">
                <table>
                    <tr>
                        <th colspan="2"><?php echo __('Module database (pmwh3 data store)'); ?></th>
                    </tr>
                    <tr>
                        <td><?php echo __('DB host'); ?></td>
                        <td><input name="db_host" required value="<?= htmlspecialchars((string) ($this->data['frameworkHost'] ?? '')) ?>"></td>
                    </tr>
                    <tr>
                        <td><?php echo __('DB name'); ?></td>
                        <td><input name="db_name" required autocomplete="off"></td>
                    </tr>
                    <tr>
                        <td><?php echo __('DB user'); ?></td>
                        <td><input name="db_user" required autocomplete="off"></td>
                    </tr>
                    <tr>
                        <td><?php echo __('DB password'); ?></td>
                        <td><input type="password" name="db_pass" required></td>
                    </tr>

                    <tr>
                        <th colspan="2"><?php echo __('DNS database (PowerDNS / MyDNS)'); ?></th>
                    </tr>
                    <tr>
                        <td colspan="2">
                            <label>
                                <input type="checkbox" name="dns_same" value="1" checked>
                                <?php echo __('Same connection as the module database (same user may create the DNS tables)'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <td><?php echo __('DNS DB name'); ?></td>
                        <td><input name="dns_name" placeholder="pdns"></td>
                    </tr>
                    <tr>
                        <td colspan="2">
                            <small><?php echo __('With "same connection" the DNS adapter uses the module DB credentials; the DNS database name is set here (e.g. an own pdns database on the same server).'); ?></small>
                        </td>
                    </tr>

                    <tr>
                        <th colspan="2"><?php echo __('Ultimate admin user'); ?></th>
                    </tr>
                    <tr>
                        <td><?php echo __('User name'); ?></td>
                        <td><input value="admin" disabled></td>
                    </tr>
                    <tr>
                        <td><?php echo __('Password'); ?></td>
                        <td><input type="password" name="admin_password" required></td>
                    </tr>
                    <tr>
                        <td colspan="2">
                            <small><?php echo __('Minimum length: see PASSWORD_LENGTH default 12. The user becomes the "Ultimate Admin" role with all pmwh3 permissions.'); ?></small>
                        </td>
                    </tr>
                </table>
                <div class="pmwh3-form-actions">
                    <button type="submit" class="button small-action save"><?php echo __('Install'); ?></button>
                </div>
            </form>
        </div>
    </div>

    <?php $installHint = BASE_URI . 'config/config_example.json'; ?>
    <div class="widget">
        <div class="entry tool">
            <h3><?php echo __('What this installer does'); ?></h3>
            <p><small>
                1. <?php echo __('Writes the real modules/pmwh3/module.json (DB + DNS nodes) -- the shipped file is a placeholder.'); ?><br>
                2. <?php echo __('Plays the pmwh3 baseline schema (fresh install: single schema.sql, no legacy migration replay).'); ?><br>
                3. <?php echo __('Creates the RBAC roles pmwh3 / Ultimate Admin / Reseller / Customer and registers all pmwh3 permissions.'); ?><br>
                4. <?php echo __('Creates the ultimate admin customer ("admin") with unlimited counters.'); ?><br>
                5. <?php echo __('Afterwards you log in on the normal login page with the admin password chosen above.'); ?>
            </small></p>
        </div>
    </div>
</div>
