<?php
$owners   = (array)  ($this->data['owners']   ?? []);
$myOwner  = (string) ($this->data['my_owner'] ?? '');
$isMulti  = count($owners) > 1;
?>
<div class="pmwh3-content">
    <div class="widget">
        <div class="widget-header">
            <h3><?php echo __('Create database'); ?></h3>
            <a href="<?php echo BASE_URI; ?>pmwh3/databases/overview"
               class="button small-action cancel"><?php echo __('Back'); ?></a>
        </div>
        <div class="widget-body">
            <form method="post" action="<?php echo BASE_URI; ?>pmwh3/databases/insert_database" data-confirm="<?php echo __('Create database?'); ?>" data-confirm-type="change">
                <table class="widget-table">
                    <tr>
                        <td><?php echo __('Owner'); ?></td>
                        <td>
                            <?php if ($isMulti): ?>
                                <select name="config[owner]" id="owner_select">
                                    <?php foreach ($owners as $o): ?>
                                        <option value="<?php echo htmlspecialchars($o); ?>"
                                                <?php if ($o === $myOwner) echo 'selected'; ?>>
                                            <?php echo htmlspecialchars($o); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?>
                                <strong><?php echo htmlspecialchars($myOwner); ?></strong>
                                <input type="hidden" name="config[owner]"
                                       value="<?php echo htmlspecialchars($myOwner); ?>">
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><?php echo __('Database name'); ?></td>
                        <td>
                            <span id="owner_prefix"><?php echo htmlspecialchars($myOwner); ?>_</span>
                            <input name="config[suffix]" required>
                            <small><?php echo __('Letters, digits, underscore only.'); ?></small>
                        </td>
                    </tr>
                </table>
                <div class="pmwh3-form-actions">
                    <button type="submit" class="button small-action create"><?php echo __('Create'); ?></button>
                </div>
            </form>
            <?php if ($isMulti): ?>
            <script>
                document.getElementById('owner_select').addEventListener('change', function (e) {
                    document.getElementById('owner_prefix').textContent = e.target.value + '_';
                });
            </script>
            <?php endif; ?>
        </div>
    </div>
</div>
