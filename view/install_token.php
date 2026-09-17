<h1>Step 0 &mdash; Security file</h1>

<?php if (!\pmwh3\Utils\InstallBootstrap::securityTokenOk()): ?>
    <p>Security file missing &#x274C;</p>
    <p><?php echo __('Please create the EMPTY security file in the ROOT directory:'); ?>
       <strong><?= htmlspecialchars((string) ($this->data['tokenName'] ?? '?')) ?></strong>
       (e.g. <code>touch &lt;cevian-root&gt;/<?= htmlspecialchars((string) ($this->data['tokenName'] ?? '?')) ?></code>
       or upload an empty file with your FTP client).</p>
    <form method="post" action="checkToken">
        <button class="button" type="submit" name="check" value="1"><?php echo __('Check again'); ?></button>
    </form>
<?php else: ?>
    <p>Security file found &#x2705;</p>
    <a href="<?= BASE_URI ?>pmwh3/install" class="button"><?php echo __('Next &rarr;'); ?></a>
<?php endif; ?>
