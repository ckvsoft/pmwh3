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

            <input type="hidden" name="phase" value="<?= ($this->data['phase2'] ?? false) ? '2' : '1' ?>">
            <form autocomplete="off" action="<?= BASE_URI ?>pmwh3/install/run" method="post" data-confirm="<?php echo ($this->data['phase2'] ?? false)
                    ? __('Create schema, RBAC roles and the admin user with the credentials from step 1?')
                    : __('Run the pmwh3 install? This writes the module config, creates the schema, RBAC roles and the admin user.'); ?>" data-confirm-type="change">
            <?php if (!($this->data['phase2'] ?? false)) { ?>
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
                        <td><input name="db_name" required value="<?= htmlspecialchars((string) ($this->data['frameworkName'] ?? '')) ?>" autocomplete="off"></td>
                    </tr>
                    <tr>
                        <td><?php echo __('DB user'); ?></td>
                        <td><input name="db_user" required value="<?= htmlspecialchars((string) ($this->data['frameworkUser'] ?? '')) ?>" autocomplete="off"></td>
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
                                <input type="checkbox" name="dns_same" value="1" id="dns_same"
                                       onchange="document.getElementById('dns_separate').style.display = this.checked ? 'none' : 'table-row';"
                                       <?= ($this->data['frameworkDnsName'] ?? '') !== '' ? '' : 'checked' ?>>
                                <?php echo __('Same connection as the module database (same user may create the DNS tables)'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr id="dns_separate" style="<?= ($this->data['frameworkDnsName'] ?? '') !== '' ? '' : 'display:none;' ?>">
                        <td colspan="2" style="padding:0;">
                            <table style="width:100%;">
                                <tr>
                                    <td><?php echo __('DNS DB host'); ?></td>
                                    <td><input name="dns_host" placeholder="<?= htmlspecialchars((string) ($this->data['frameworkHost'] ?? 'localhost')) ?>"></td>
                                </tr>
                                <tr>
                                    <td><?php echo __('DNS DB name'); ?></td>
                                    <td><input name="dns_name" placeholder="pdns" value="<?= htmlspecialchars((string) ($this->data['frameworkDnsName'] ?? '')) ?>"></td>
                                </tr>
                                <tr>
                                    <td><?php echo __('DNS DB user'); ?></td>
                                    <td><input name="dns_user" autocomplete="off"></td>
                                </tr>
                                <tr>
                                    <td><?php echo __('DNS DB password'); ?></td>
                                    <td><input type="password" name="dns_pass" autocomplete="off"></td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td colspan="2">
                            <small><?php echo __('With "same connection" the DNS adapter uses the module DB credentials and only the DNS database NAME matters (e.g. an own pdns database on the same server). When unchecked, provide host/name/user/password of the DNS database.'); ?></small>
                        </td>
                    </tr>

<?php } // phase2: database + dns sections skipped ?>
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
