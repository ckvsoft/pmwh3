<div class="pmwh3-content">
    <div class="widget">
        <div class="entry tool">
            <h2><?php echo __('Install pmwh3 &mdash; step 0: server access proof'); ?></h2>
            <p>
                <?php echo __('Before the wizard opens, prove that you have server access (same mechanism as the Cevian core installer): create the file'); ?>
                <code><?php echo htmlspecialchars((string) ($this->data['tokenName'] ?? '?')); ?></code>
                <?php echo __('in the Cevian root and put EXACTLY this line into it:'); ?>
            </p>
            <p><code><strong><?php echo htmlspecialchars((string) ($this->data['tokenCode'] ?? '?')); ?></strong></code></p>
            <p><small><?php echo __('e.g. via SSH:'); ?></small></p>
            <pre><code>echo "<?php echo htmlspecialchars((string) ($this->data['tokenCode'] ?? '?')); ?>" &gt; &lt;cevian-root&gt;/<?php echo htmlspecialchars((string) ($this->data['tokenName'] ?? '?')); ?></code></pre>
            <p><small><?php echo __('Reload this page after creating the file. The wizard opens only when the file content matches. The token file and the installer state are removed after a successful install.'); ?></small></p>
        </div>
    </div>
</div>
