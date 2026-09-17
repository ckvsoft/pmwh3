<div class="pmwh3-content">
<?php
$to         = (string) ($this->data['to']         ?? '');
$subject    = (string) ($this->data['subject']    ?? '');
$candidates = (array)  ($this->data['candidates'] ?? []);
?>
<div class="widget">
    <div class="widget-header">
        <h3><?php echo __('Compose Message'); ?></h3>
    </div>
    <div class="widget-body">

        <form method="post" action="<?php echo BASE_URI; ?>pmwh3/message/send">
            <table class="widget-table">
                <tr><td><?php echo __('To'); ?></td>
                    <td>
                        <input type="text" name="recipient" list="recipient_candidates"
                               value="<?php echo htmlspecialchars($to); ?>"
                               autocomplete="off" required>
                        <?php if (!empty($candidates)): ?>
                            <datalist id="recipient_candidates">
                                <?php foreach ($candidates as $name): ?>
                                    <option value="<?php echo htmlspecialchars((string) $name); ?>">
                                <?php endforeach; ?>
                            </datalist>
                            <small><?php echo sprintf(__('%d customers available'), count($candidates)); ?></small>
                        <?php endif; ?>
                    </td></tr>
                <tr><td><?php echo __('Subject'); ?></td>
                    <td><input type="text" name="subject"
                               value="<?php echo htmlspecialchars($subject); ?>" required></td></tr>
                <tr><td valign="top"><?php echo __('Text'); ?></td>
                    <td><textarea name="text" rows="10" cols="60" required></textarea></td></tr>
            </table>

            <div class="form-actions">
                <a href="<?php echo BASE_URI; ?>pmwh3/message/inbox"
                   class="button small-action cancel"><?php echo __('Cancel'); ?></a>
                <button type="submit" class="button small-action save"><?php echo __('Send'); ?></button>
            </div>
        </form>

    </div>
</div>
</div>
