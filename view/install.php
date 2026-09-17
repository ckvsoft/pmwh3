<div class="pmwh3-content">
    <div class="widget">
        <div class="entry tool">
            <h2><?php echo __('Install pmwh3'); ?> — <?php
                $stepTitles = [
                    'perms'     => __('Step 2/5: permissions prerequisites'),
                    'db'        => __('Step 3/5: module database'),
                    'dns'       => __('Step 4/5: DNS database'),
                    'bootstrap' => __('Step 5/5: create schema, roles, admin'),
                ];
                echo $stepTitles[$this->data['step'] ?? ''] ?? __('Install pmwh3');
            ?></h2>

            <table>
                <tr><th colspan="2"><?php echo __('Requirements'); ?></th></tr>
                <?php if (($this->data['step'] ?? '') === 'perms') { ?>
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
                    <tr>
                        <td style="white-space: nowrap; float:left;">
                            <strong style="color: <?php echo \pmwh3\Utils\InstallBootstrap::stateWriteProbe() ? 'green' : 'red'; ?>; font-weight: bold;">
                                <?php echo \pmwh3\Utils\InstallBootstrap::stateWriteProbe() ? 'OK' : 'FAIL'; ?>
                            </strong>
                        </td>
                        <td>var/ write probe (create &amp; delete) &mdash; <?php echo \pmwh3\Utils\InstallBootstrap::stateWriteProbe()
                                ? 'writable'
                                : 'NOT writable (file create fails — check disk/quota/chmod)'; ?></td>
                    </tr>
                    <tr><td colspan="2">
                        <small><?php echo __('Fix the failed requirements and click "Check again" below -- Install cannot run until then.'); ?></small>
                    </td></tr>
                <?php } else { ?>
                    <tr><td colspan="2">
                        <small style="color: green;">&#10003; <?php echo __('Prerequisites OK (verified in step 2).'); ?></small>
                    </td></tr>
                <?php } ?>
                <?php if (($this->data['step'] ?? '') === 'dns' && ($this->data['dnsStatus']['nodeFound'] ?? false) && !($this->data['dnsStatus']['tablesOk'] ?? false)): ?>
                    <tr>
                        <td style="white-space: nowrap; float:left;"><strong style="color:red;">FAIL</strong></td>
                        <td>DNS schema &mdash; <?php echo htmlspecialchars((string) (($this->data['dnsStatus']['detail'] ?? ''))); ?></td>
                    </tr>
                <?php endif; ?>
            </table>

            <?php if (($this->data['step'] ?? '') === 'perms') { ?>
                <div class="pmwh3-form-actions">
                    <a class="button small-action" href="<?= BASE_URI ?>pmwh3/install"><?php echo __('Check again'); ?></a>
                    <?php if (($this->data['sysChecks']['ok'] ?? false) && \pmwh3\Utils\InstallBootstrap::stateWriteProbe()): ?>
                    <form style="display:inline" autocomplete="off" action="<?= BASE_URI ?>pmwh3/install/run" method="post">
                        <input type="hidden" name="phase" value="perms_ok">
                        <button type="submit" class="button small-action save"><?php echo __('Continue to the database (step 3)'); ?></button>
                    </form>
                    <?php endif; ?>
                </div>
            <?php } ?>

            <?php $step = $this->data['step'] ?? 'db'; ?>
            <?php if ($step === 'db') { ?>
            <form autocomplete="off" action="<?= BASE_URI ?>pmwh3/install/run" method="post">
                <input type="hidden" name="phase" value="1">
                <table>
                    <tr>
                        <th colspan="2"><?php echo __('Step 3 of 5: module database (pmwh3 data store)'); ?></th>
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
                        <th colspan="2"><?php echo __('Database administrator (optional)'); ?></th>
                    </tr>
                    <tr>
                        <td colspan="2">
                            <small><?php echo __('Only needed when the database user above may NOT create databases (common on hosting panels): the installer then uses this login once to create the module database and grant the user above. Never stored.'); ?></small>
                        </td>
                    </tr>
                    <tr>
                        <td><?php echo __('Admin user'); ?></td>
                        <td><input name="db_admin_user" autocomplete="off"></td>
                    </tr>
                    <tr>
                        <td><?php echo __('Admin password'); ?></td>
                        <td><input type="password" name="db_admin_pass" autocomplete="off"></td>
                    </tr>
                </table>
                <div class="pmwh3-form-actions">
                    <button type="submit" class="button small-action save"><?php echo __('Save database connection (step 2)'); ?></button>
                </div>
            </form>
            <?php } elseif ($step === 'dns') { ?>
                <form autocomplete="off" action="<?= BASE_URI ?>pmwh3/install/run" method="post">
                    <input type="hidden" name="phase" value="dns">
                    <table>
                        <tr>
                            <th colspan="2"><?php echo __('Step 4 of 5: DNS database (PowerDNS / MyDNS)'); ?></th>
                        </tr>
                        <?php if (!empty($this->data['dnsStatus']['nodeFound'])): ?>
                        <tr>
                            <td colspan="2"><small>
                                <?php echo __('Current DNS connection status:'); ?>
                                <?php echo htmlspecialchars((string) ($this->data['dnsStatus']['detail'] ?? '')); ?>
                            </small></td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <td><?php echo __('DNS adapter schema'); ?></td>
                            <td>
                                <label><input type="radio" name="dns_type" value="pdns"
                                        <?= (($this->data['dnsType'] ?? 'pdns') === 'pdns') ? 'checked' : '' ?>> PowerDNS</label>
                                &nbsp;
                                <label><input type="radio" name="dns_type" value="mydns"
                                        <?= (($this->data['dnsType'] ?? '') === 'mydns') ? 'checked' : '' ?>> MyDNS</label>
                            </td>
                        </tr>
                        <tr>
                            <td colspan="2"><small><?php echo __('The chosen schema is applied automatically (idempotent, bundled snapshot from contrib/sql).'); ?></small></td>
                        </tr>
                        <tr>
                            <td colspan="2">
                                <label>
                                    <input type="checkbox" name="dns_same" value="1" id="dns_same"
                                           onchange="document.getElementById('dns_separate').style.display = this.checked ? 'none' : '';"
                                           <?= !empty($this->data['frameworkDnsSame']) ? 'checked' : '' ?>>
                                    <?php echo __('Same connection as the module database (same user may create the DNS tables)'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <td><?php echo __('DNS DB name'); ?></td>
                            <td><input name="dns_name" placeholder="pdns" required value="<?= htmlspecialchars((string) ($this->data['frameworkDnsName'] ?? '')) ?>"></td>
                        </tr>
                        <tbody id="dns_separate" style="display: <?= !empty($this->data['frameworkDnsSame']) ? 'none' : ''; ?>;">
                            <tr>
                                <td colspan="2"><small><?php echo __('Separate DNS database connection (only with "Same connection" UNCHECKED):'); ?></small></td>
                            </tr>
                            <tr>
                                <td><?php echo __('DNS DB host'); ?></td>
                                <td><input name="dns_host" value="<?= htmlspecialchars((string) ($this->data['frameworkDnsHost'] ?? '')) ?>" placeholder="<?= htmlspecialchars((string) ($this->data['frameworkHost'] ?? 'localhost')) ?>"></td>
                            </tr>
                            <tr>
                                <td><?php echo __('DNS DB user'); ?></td>
                                <td><input name="dns_user" autocomplete="off" value="<?= htmlspecialchars((string) ($this->data['frameworkDnsUser'] ?? '')) ?>"></td>
                            </tr>
                            <tr>
                                <td><?php echo __('DNS DB password'); ?></td>
                                <td><input type="password" name="dns_pass" autocomplete="off"></td>
                            </tr>
                        </tbody>
                        <tr>
                            <th colspan="2"><?php echo __('Database administrator (optional)'); ?></th>
                        </tr>
                        <tr>
                            <td colspan="2">
                                <small><?php echo __('Only needed when the database user above may NOT create databases: the installer then uses this login once to create the DNS database and grant the user. Never stored.'); ?></small>
                            </td>
                        </tr>
                        <tr>
                            <td><?php echo __('Admin user'); ?></td>
                            <td><input name="db_admin_user" autocomplete="off"></td>
                        </tr>
                        <tr>
                            <td><?php echo __('Admin password'); ?></td>
                            <td><input type="password" name="db_admin_pass" autocomplete="off"></td>
                        </tr>
                    </table>
                    <div class="pmwh3-form-actions">
                        <button type="submit" class="button small-action save"
                                data-confirm="<?php echo __('Create the DNS database (when missing), save the connection and apply the chosen schema?'); ?>"
                                data-confirm-type="change"><?php echo __('Save and apply DNS schema (step 4)'); ?></button>
                    </div>
                </form>
            <?php } elseif ($step === 'bootstrap') { ?>
                <form autocomplete="off" action="<?= BASE_URI ?>pmwh3/install/run" method="post">
                    <table>
                        <tr><th colspan="2"><?php echo __('Step 5 of 5: create schema, roles, admin user'); ?></th></tr>
                        <tr>
                            <td colspan="2">
                                <small><?php echo __('Configuration is checked (module database + DNS). Confirming now plays the pmwh3 baseline into the module database, creates the RBAC roles, registers all permissions and seeds the pmwh3 configuration (DNS adapter type was pre-configured while applying the DNS schema).'); ?></small>
                            </td>
                        </tr>
                        <tr>
                            <td><?php echo __('User name'); ?></td>
                            <td><input value="admin" disabled></td>
                        </tr>
                        <tr>
                            <td><?php echo __('Min. password length'); ?></td>
                            <td><input type="number" name="password_length" min="6" max="99"
                                       value="<?= (int) \pmwh3\Config\LazyConfig::get('PASSWORD_LENGTH', 8) ?>"
                                       autocomplete="off"></td>
                        </tr>
                        <tr>
                            <td><?php echo __('Password'); ?>
                                <input type="password" name="admin_password" required
                                       minlength="<?php echo (int) \pmwh3\Utils\PasswordUtil::minLength();
                                ?>"></td>
                        </tr>
                        <tr>
                            <td colspan="2">
                                <small><?php echo sprintf(__('Minimum length: %d (PASSWORD_LENGTH). The user becomes the "Ultimate Admin" role with all pmwh3 permissions.'),
                                        \pmwh3\Utils\PasswordUtil::minLength()); ?></small>
                            </td>
                        </tr>
                    </table>
                    <input type="hidden" name="phase" value="2">
                    <div class="pmwh3-form-actions">
                        <button type="submit" class="button small-action save"
                                data-confirm="<?php echo __('Create schema, RBAC roles and the admin user with the credentials from steps 3-4?'); ?>"
                                data-confirm-type="change"><?php echo __('Create admin user and finish installation (step 5)'); ?></button>
                    </div>
                </form>
            <?php } ?>
        </div>
    </div>

    <div class="widget">
        <div class="entry tool">
            <h3><?php echo __('What this installer does'); ?></h3>
            <p><small>
                1. <?php echo __('Requirements (own step): writes nothing -- filesystem + PHP + Cevian checks with check-again.'); ?><br>
                2. <?php echo __('Module database: writes the database node of modules/pmwh3/module.json, probes and creates the database (optionally via the database administrator login).'); ?><br>
                3. <?php echo __('DNS database (one form): choose the adapter schema (PowerDNS / MyDNS), connection (same as the module database or separate, optional database administrator for the CREATE), the installer creates the missing database, writes the dns node, applies the bundled schema and pre-configures DNS_TYPE pmwh3-side.'); ?><br>
                4. <?php echo __('Bootstrap: plays the pmwh3 baseline schema, creates the RBAC roles pmwh3 / Ultimate Admin / Reseller / Customer, registers all permissions and the ultimate admin customer ("admin") with unlimited counters.'); ?><br>
                5. <?php echo __('Afterwards you log in on the normal login page with the admin password chosen above.'); ?>
            </small></p>
        </div>
    </div>
</div>
