<div class="pmwh3-content">
    <div class="widget">
        <div class="entry tool">
            <h2><?php echo __('Install pmwh3 &mdash; step 1: server access'); ?></h2>
            <p>
                <?php echo __('Before the wizard opens, prove that you have server access (same mechanism as the Cevian core installer): create the following EMPTY text file in the Cevian root'); ?>
            </p>
            <p><code><?php echo htmlspecialchars((string) ($this->data['tokenName'] ?? '?')); ?></code>
            </p>
            <p><small>
                <?php echo __('Create it e.g. via SSH:'); ?><br>
                <code>touch &lt;cevian-root&gt;/<?php echo htmlspecialchars((string) ($this->data['tokenName'] ?? '?')); ?></code><br>
                <?php echo __('or upload the file with your FTP client.'); ?>
            </small></p>
            <p><small>
                <?php echo __('The wizard shows up automatically after the file exists. The token file is removed together with the installer state after a successful install.'); ?>
            </small></p>
        </div>
    </div>
</div>
