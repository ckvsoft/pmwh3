<div class="pmwh3-content">
<?php
$domain        = $this->data['domain']        ?? '';
$mode          = $this->data['mode']          ?? 'new';   // 'new' | 'edit'
$source        = $this->data['source']        ?? '';
$local         = $this->data['local']         ?? '';
$destinations  = $this->data['destinations']  ?? '';
$isEdit        = $mode === 'edit';
$formAction    = $isEdit
        ? BASE_URI . 'pmwh3/email/save_forward/' . urlencode($source)
        : BASE_URI . 'pmwh3/email/insert_forward';
$title         = $isEdit
        ? sprintf(__('Edit forward: %s'), htmlspecialchars($source))
        : sprintf(__('New forward in %s'), htmlspecialchars($domain));
?>
<div class="widget">
    <div class="widget-header">
        <h3><?php echo $title; ?></h3>
    </div>
    <div class="widget-body">

        <form method="post" action="<?php echo $formAction; ?>" data-confirm="<?php echo __('Save changes?'); ?>" data-confirm-type="change">
            <table class="widget-table">
                <tr>
                    <td><?php echo __('Source local part'); ?></td>
                    <td>
                        <?php if ($isEdit): ?>
                            <strong><?php echo htmlspecialchars($local); ?></strong>
                            @<?php echo htmlspecialchars($domain); ?>
                        <?php else: ?>
                            <input type="text" name="local" required>
                            @<?php echo htmlspecialchars($domain); ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td class="pmwh3-cell-top"><?php echo __('Destinations'); ?>
                        <br><small><em><?php echo __('One email address per line.'); ?></em></small>
                    </td>
                    <td><textarea name="destinations" rows="6" cols="40" required
                                  placeholder="user1@example.com&#10;user2@example.com"><?php
                            echo htmlspecialchars($destinations);
                        ?></textarea></td>
                </tr>
            </table>
            <div class="form-actions">
                <a href="<?php echo BASE_URI; ?>pmwh3/email/details/forward"
                   class="button small-action cancel"><?php echo __('Cancel'); ?></a>
                <button type="submit" class="button small-action save">
                    <?php echo $isEdit ? __('Save') : __('Create'); ?>
                </button>
            </div>
        </form>

    </div>
</div>
</div>
