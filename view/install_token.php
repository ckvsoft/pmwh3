<div class="pmwh3-content">
    <div class="widget">
        <div class="entry tool">
            <h2><?php echo __('Install pmwh3'); ?></h2>

            <?php if (!\pmwh3\Utils\InstallBootstrap::securityTokenOk()) { ?>
                <p>
                    <strong style="color:red">&#10060; <?php echo __('Security file missing'); ?></strong><br>
                    <?php echo __('Please create the EMPTY security file inside the pmwh3 module folder (next to module.json.example):'); ?><br>
                    <code><strong><?php echo htmlspecialchars((string) ($this->data['tokenName'] ?? '?')); ?></strong></code><br>
                    <small><?php echo __('Create it in the pmwh3 module folder -- via SSH'); ?>
                    <code>cd &lt;pmwh3-module-folder&gt;; touch <?php echo htmlspecialchars((string) ($this->data['tokenName'] ?? '?')); ?></code>
                    <?php echo __('or upload an empty file with your FTP client.'); ?></small>
                </p>
                <div class="pmwh3-form-actions">
                    <form action="<?= BASE_URI ?>pmwh3/install/checkToken" method="post">
                        <input class="button small-action save" type="submit" name="check" value="<?php echo __('Check again'); ?>">
                    </form>
                </div>
            <?php } else { ?>
                <p>
                    <strong style="color:green">&#9989; <?php echo __('Security file found'); ?></strong><br>
                    <?php echo __('Continue to the install wizard:'); ?>
                </p>
                <div class="pmwh3-form-actions">
                    <a class="button small-action save" href="<?= BASE_URI ?>pmwh3/install"><?php echo __('Next'); ?></a>
                </div>
            <?php } ?>
        </div>
    </div>
</div>
