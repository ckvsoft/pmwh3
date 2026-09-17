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
                <?php if (($this->data['step'] ?? '') === 'dns'): ?>
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
            <?php $nodeFound = (bool) ($this->data['dnsStatus']['nodeFound'] ?? false); ?>
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
                <?php if (!$nodeFound) { ?>
                <form autocomplete="off" action="<?= BASE_URI ?>pmwh3/install/run" method="post">
                    <input type="hidden" name="phase" value="dns_conn">
                    <table>
                        <tr>
                            <th colspan="2"><?php echo __('Step 4 of 5: DNS database (PowerDNS / MyDNS)'); ?></th>
                        </tr>
                        <tr>
                            <td colspan="2">
                                <label>
                                    <input type="checkbox" name="dns_same" value="1" id="dns_same"
                                           onchange="document.getElementById('dns_separate').style.display = this.checked ? 'none' : 'table-row';"
                                           <?= !empty($this->data['frameworkDnsSame']) ? 'checked' : '' ?>>
                                    <?php echo __('Same connection as the module database (same user may create the DNS tables)'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <td><?php echo __('DNS DB name'); ?></td>
                            <td><input name="dns_name" placeholder="pdns" value="<?= htmlspecialchars((string) ($this->data['frameworkDnsName'] ?? '')) ?>"></td>
                        </tr>
                    </table>
                    <table style="width:100%; display: <?= !empty($this->data['frameworkDnsSame']) ? 'none' : 'table-row'; ?>;" id="dns_separate">
                        <tr>
                            <td colspan="2"><small><?php echo __('Separate DNS database connection (only with "Same connection" UNCHECKED):'); ?></small></td>
                        </tr>
                        <tr>
                            <td><?php echo __('DNS DB host'); ?></td>
                            <td><input name="dns_host" value="<?= htmlspecialchars((string) ($this->data['frameworkDnsHost'] ?? '')) ?>" placeholder="<?= htmlspecialchars((string) ($this->data['frameworkHost'] ?? 'localhost')) ?>" style="border:1px solid #ccc;"></td>
                        </tr>
                        <tr>
                            <td><?php echo __('DNS DB user'); ?></td>
                            <td><input name="dns_user" autocomplete="off" value="<?= htmlspecialchars((string) ($this->data['frameworkDnsUser'] ?? '')) ?>"></td>
                        </tr>
                        <tr>
                            <td><?php echo __('DNS DB password'); ?></td>
                            <td><input type="password" name="dns_pass" autocomplete="off"></td>
                        </tr>
                        <tr>
                            <td colspan="2">
                                <small><?php echo __('With "same connection" the DNS adapter uses the module DB credentials and only the DNS database NAME matters (e.g. an own pdns database on the same server). When unchecked, provide host/name/user/password of the DNS database.'); ?></small>
                            </td>
                        </tr>
                    </table>
                    <div class="pmwh3-form-actions">
                        <button type="submit" class="button small-action save"><?php echo __('Save DNS database (step 3)'); ?></button>
                    </div>
                </form>
                <?php } else { ?>
                <form autocomplete="off" action="<?= BASE_URI ?>pmwh3/install/run" method="post" enctype="multipart/form-data">
                    <table>
                        <tr><th colspan="2"><?php echo __('Step 4 of 5: apply DNS schema'); ?></th></tr>
                        <tr>
                            <td colspan="2">
                                <small><?php echo __('Choose the bundled schema snapshot (pdns = PowerDNS, mydns = MyDNS), or supply your own SQL file / paste the statements:'); ?></small>
                            </td>
                        </tr>
                        <tr>
                            <td><?php echo __('Schema'); ?></td>
                            <td>
                                <label><input type="radio" name="dns_schema_choice" value="pdns" checked> PowerDNS</label>
                                &nbsp;
                                <label><input type="radio" name="dns_schema_choice" value="mydns"> MyDNS</label>
                            </td>
                        </tr>
                        <tr>
                            <td><?php echo __('Own file (optional)'); ?></td>
                            <td><input type="file" name="dns_schema_file" accept=".sql,text/plain"></td>
                        </tr>
                        <tr>
                            <td><?php echo __('Pasted SQL'); ?></td>
                            <td><textarea name="dns_schema_text" rows="6" style="width:100%;font-family:monospace;"
                                          placeholder="CREATE TABLE ..."></textarea></td>
                        </tr>
                    </table>
                    <input type="hidden" name="phase" value="dns">
                    <div class="pmwh3-form-actions">
                        <button type="submit" class="button small-action"
                                data-confirm="<?php echo __('Run the selected DNS schema against the configured DNS database?'); ?>"
                                data-confirm-type="change"><?php echo __('Apply DNS schema'); ?></button>
                    </div>
                </form>
                <?php } ?>
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
                            <td><?php echo __('Password'); ?></td>
                            <td><input type="password" name="admin_password" required></td>
                        </tr>
                        <tr>
                            <td colspan="2">
                                <small><?php echo __('Minimum length: see PASSWORD_LENGTH default 12. The user becomes the "Ultimate Admin" role with all pmwh3 permissions.'); ?></small>
                            </td>
                        </tr>
                    </table>
                    <input type="hidden" name="phase" value="2">
                    <div class="pmwh3-form-actions">
                        <button type="submit" class="button small-action"
                                data-confirm="<?php echo __('Create schema, RBAC roles and the admin user with the credentials from steps 3-4?'); ?>"
                                data-confirm-type="change"><?php echo __('Install (step 3)'); ?></button>
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
                3. <?php echo __('DNS database: writes the dns node, applies the schema when missing (bundled snapshots pdns/mydns, own file or pasted SQL) and pre-configures DNS_TYPE pmwh3-side.'); ?><br>
                4. <?php echo __('Bootstrap: plays the pmwh3 baseline schema, creates the RBAC roles pmwh3 / Ultimate Admin / Reseller / Customer, registers all permissions and the ultimate admin customer ("admin") with unlimited counters.'); ?><br>
                5. <?php echo __('Afterwards you log in on the normal login page with the admin password chosen above.'); ?>
            </small></p>
        </div>
    </div>
</div>
