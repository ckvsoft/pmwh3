<div class="pmwh3-content">
<?php
$row    = $this->data['row'] ?? null;
$isEdit = is_array($row);
$action = $isEdit ? 'pmwh3/tools/save_application/' . (int) ($row['id'] ?? 0)
                  : 'pmwh3/tools/insert_application';
$name   = $isEdit ? (string) ($row['name'] ?? '') : '';
$link   = $isEdit ? (string) ($row['link'] ?? '') : '';
$sort   = $isEdit ? (int) ($row['sort'] ?? 0) : 0;
?>
<div class="widget">
    <div class="widget-header">
        <h3><?php echo $isEdit ? __('Edit application') : __('New application'); ?></h3>
        <a href="<?php echo BASE_URI; ?>pmwh3/tools/applications"
           class="button small-action cancel"><?php echo __('Back'); ?></a>
    </div>
    <div class="widget-body">
        <form action="<?php echo BASE_URI; ?><?php echo $action; ?>" method="post" data-confirm="<?php echo __('Save changes?'); ?>" data-confirm-type="change">
            <div class="form-group">
                <label for="a_name"><?php echo __('Name'); ?></label>
                <input type="text" id="a_name" name="name" required maxlength="100"
                       value="<?php echo htmlspecialchars($name); ?>">
            </div>
            <div class="form-group">
                <label for="a_link"><?php echo __('Link (URL)'); ?></label>
                <input type="url" id="a_link" name="link" required maxlength="255"
                       value="<?php echo htmlspecialchars($link); ?>" placeholder="https://...">
            </div>
            <div class="form-group">
                <label for="a_sort"><?php echo __('Sort'); ?></label>
                <input type="number" id="a_sort" name="sort" value="<?php echo (int) $sort; ?>">
            </div>
            <button type="submit" class="button small-action save"><?php echo __('Save'); ?></button>
            <a href="<?php echo BASE_URI; ?>pmwh3/tools/applications" class="button small-action cancel"><?php echo __('Cancel'); ?></a>
        </form>
    </div>
</div>
</div>
