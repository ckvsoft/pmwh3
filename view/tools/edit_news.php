<div class="pmwh3-content">
<?php
$row    = $this->data['row'] ?? null;
$isEdit = is_array($row);
$action = $isEdit ? 'pmwh3/tools/save_news/' . (int) ($row['id'] ?? 0)
                  : 'pmwh3/tools/insert_news';
$text   = $isEdit ? (string) ($row['news'] ?? '') : '';
$active = $isEdit ? (($row['active'] ?? 'N') === 'Y') : false;
?>
<div class="widget">
    <div class="widget-header">
        <h3><?php echo $isEdit ? __('Edit news') : __('New news'); ?></h3>
        <a href="<?php echo BASE_URI; ?>pmwh3/tools/news"
           class="button small-action cancel"><?php echo __('Back'); ?></a>
    </div>
    <div class="widget-body">
        <form action="<?php echo BASE_URI; ?><?php echo $action; ?>" method="post" data-confirm="<?php echo __('Save changes?'); ?>" data-confirm-type="change">
            <div class="form-group">
                <label for="n_text"><?php echo __('Text'); ?></label>
                <textarea id="n_text" name="news" rows="6" required
                          style="width:100%;"><?php echo htmlspecialchars($text); ?></textarea>
            </div>
            <div class="form-group">
                <label>
                    <input type="checkbox" name="active" value="1" <?php echo $active ? 'checked' : ''; ?>>
                    <?php echo __('Active (visible on the login page ticker)'); ?>
                </label>
            </div>
            <button type="submit" class="button small-action save"><?php echo __('Save'); ?></button>
            <a href="<?php echo BASE_URI; ?>pmwh3/tools/news" class="button small-action cancel"><?php echo __('Cancel'); ?></a>
        </form>
    </div>
</div>
</div>
