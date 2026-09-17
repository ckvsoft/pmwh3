<h2><?php echo __('Install pmwh3'); ?></h2>

<?php $sc = ($this->data['sysChecks']['rows'] ?? []); ?>
<h3><?php echo __('Requirements'); ?></h3>
<table style="width:100%;">
    <?php foreach ($sc as $ic): ?>
        <tr>
            <td style="color: <?php echo $ic['ok'] ? 'green' : 'red'; ?>; font-weight:bold; width: 60px;">
                <?php echo $ic['ok'] ? 'OK' : 'FAIL'; ?>
            </td>
            <td><?php echo htmlspecialchars((string) $ic['label']); ?>
                &mdash; <?php echo htmlspecialchars($ic['detail']); ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!($this->data['sysChecks']['ok'] ?? true)): ?>
        <tr><td colspan="2" style="color:#888; font-size: 12px;">
            <?php echo __('Fix the failed requirements and reload this page -- Install cannot run until then.'); ?>
        </td></tr>
    <?php endif; ?>
</table>

<form action="<?= BASE_URI ?>pmwh3/install/run" method="post" data-confirm="<?php echo __('Run the pmwh3 install? This writes the module config, creates the schema, RBAC roles and the admin user.'); ?>" data-confirm-type="change">
    <h3><?php echo __('Module database (pmwh3 data store)'); ?></h3>
    <table style="width:100%;">
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
            <td><input type="password" name="db_pass" required autocomplete="off"></td>
        </tr>
    </table>

    <h3><?php echo __('DNS database (PowerDNS / MyDNS)'); ?></h3>
    <table style="width:100%;">
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
    </table>

    <h3><?php echo __('Ultimate admin user'); ?></h3>
    <table style="width:100%;">
        <tr>
            <td><?php echo __('User name'); ?></td>
            <td><input value="admin" disabled></td>
        </tr>
        <tr>
            <td><?php echo __('Password'); ?></td>
            <td><input type="password" name="admin_password" required autocomplete="off"></td>
        </tr>
        <tr>
            <td colspan="2">
                <small><?php echo __('Minimum length: see PASSWORD_LENGTH default 12. The user becomes the "Ultimate Admin" role with all pmwh3 permissions.'); ?></small>
            </td>
        </tr>
    </table>
    <button type="submit" class="button"><?php echo __('Install'); ?></button>
</form>

<p><small>
    1. <?php echo __('Writes the real modules/pmwh3/module.json (DB + DNS nodes) -- the shipped file is a placeholder.'); ?><br>
    2. <?php echo __('Plays the pmwh3 baseline schema (fresh install: single schema.sql, no legacy migration replay).'); ?><br>
    3. <?php echo __('Creates the RBAC roles pmwh3 / Ultimate Admin / Reseller / Customer and registers all pmwh3 permissions.'); ?><br>
    4. <?php echo __('Creates the ultimate admin customer ("admin") with unlimited counters.'); ?><br>
    5. <?php echo __('Afterwards you log in on the normal login page with the admin password chosen above.'); ?>
</small></p>
