<?php
/**
 * Menu row edit form. Used both for new + edit; the difference
 * is the form action and a hidden origBox/origSort pair on edit.
 *
 * Data:
 *   mode        : 'new' | 'edit'
 *   row         : the row to edit (or an empty skeleton for new)
 *   origBox     : the row's original box (edit only)
 *   origSort    : the row's original sort (edit only)
 *   boxes       : distinct box numbers currently in the table
 *   permissions : list of bare permKeys (no 'pmwh3.') available
 *                 for the permission dropdown
 */
$mode        = (string) ($this->data['mode']        ?? 'new');
$row         = (array)  ($this->data['row']         ?? []);
$origBox     = isset($this->data['origBox'])  ? (int) $this->data['origBox']  : null;
$origSort    = isset($this->data['origSort']) ? (int) $this->data['origSort'] : null;
$boxes       = (array)  ($this->data['boxes']       ?? []);
$permissions = (array)  ($this->data['permissions'] ?? []);

$action = $mode === 'new'
        ? BASE_URI . 'pmwh3/tools/insert_menu_row'
        : BASE_URI . 'pmwh3/tools/save_menu_row';

$rowName = (string) ($row['name']       ?? '');
$rowLink = (string) ($row['link']       ?? '');
$rowBox  = (int)    ($row['box']        ?? 0);
$rowSort = (int)    ($row['sort']       ?? 0);
$rowHide = ((string)($row['hide']       ?? 'N')) === 'Y';
$rowIcon = (string) ($row['icon']       ?? '');
$rowPerm = (string) ($row['permission'] ?? '');
?>
<div class="pmwh3-content">
    <div class="widget">
        <div class="widget-header">
            <h3>
                <?php echo $mode === 'new'
                        ? __('New menu row')
                        : __('Edit menu row'); ?>
            </h3>
            <a href="<?php echo BASE_URI; ?>pmwh3/tools/menu"
               class="button small-action cancel"><?php echo __('Back'); ?></a>
        </div>
        <div class="widget-body">

            <form method="post" action="<?php echo $action; ?>" data-confirm="<?php echo __('Save changes?'); ?>" data-confirm-type="change">
                <?php if ($mode === 'edit'): ?>
                    <input type="hidden" name="origBox"  value="<?php echo (int) $origBox; ?>">
                    <input type="hidden" name="origSort" value="<?php echo (int) $origSort; ?>">
                <?php endif; ?>

                <table class="widget-table">
                    <tr>
                        <td><label for="f_name"><?php echo __('Name'); ?></label></td>
                        <td><input type="text" id="f_name" name="name"
                                   value="<?php echo htmlspecialchars($rowName); ?>" required></td>
                    </tr>
                    <tr>
                        <td><label for="f_link"><?php echo __('Link'); ?></label></td>
                        <td><input type="text" id="f_link" name="link"
                                   value="<?php echo htmlspecialchars($rowLink); ?>"
                                   placeholder="e.g. domain/overview"></td>
                    </tr>
                    <tr>
                        <td><label for="f_box"><?php echo __('Box'); ?></label></td>
                        <td>
                            <input type="number" id="f_box" name="box"
                                   value="<?php echo $rowBox; ?>"
                                   list="known_boxes"
                                   required>
                            <datalist id="known_boxes">
                                <?php foreach ($boxes as $b): ?>
                                    <option value="<?php echo (int) $b; ?>"></option>
                                <?php endforeach; ?>
                            </datalist>
                            <small><?php echo __('Existing:'); ?>
                                <?php echo implode(', ', array_map('intval', $boxes)); ?></small>
                        </td>
                    </tr>
                    <tr>
                        <td><label for="f_sort"><?php echo __('Sort'); ?></label></td>
                        <td><input type="number" id="f_sort" name="sort"
                                   value="<?php echo $rowSort; ?>" required></td>
                    </tr>
                    <tr>
                        <td><label for="f_icon"><?php echo __('Icon'); ?></label></td>
                        <td><input type="text" id="f_icon" name="icon"
                                   value="<?php echo htmlspecialchars($rowIcon); ?>"
                                   placeholder="e.g. menu_domains.png"></td>
                    </tr>
                    <tr>
                        <td><label for="f_perm"><?php echo __('Permission'); ?></label></td>
                        <td>
                            <input type="text" id="f_perm" name="permission"
                                   value="<?php echo htmlspecialchars($rowPerm); ?>"
                                   list="known_permissions"
                                   placeholder="view_menu_xyz">
                            <datalist id="known_permissions">
                                <?php foreach ($permissions as $p): ?>
                                    <option value="<?php echo htmlspecialchars($p); ?>"></option>
                                <?php endforeach; ?>
                            </datalist>
                            <small>
                                <?php echo __('Convention:'); ?>
                                <code>view_menu_&lt;area&gt;</code>.
                                <?php echo __('Leave empty for "visible to everyone".'); ?>
                                <br>
                                <?php echo __('Auto-registered in the framework on first menu render if it doesn\'t exist yet.'); ?>
                            </small>
                        </td>
                    </tr>
                    <tr>
                        <td><label for="f_hide"><?php echo __('Hidden'); ?></label></td>
                        <td>
                            <input type="checkbox" id="f_hide" name="hide" value="Y"
                                   <?php if ($rowHide): ?>checked<?php endif; ?>>
                            <small><?php echo __('If checked, the row is hidden in the nav regardless of permission.'); ?></small>
                        </td>
                    </tr>
                </table>

                <div class="pmwh3-form-actions">
                    <button type="submit" class="button small-action save">
                        <?php echo __('Save'); ?>
                    </button>
                    <a href="<?php echo BASE_URI; ?>pmwh3/tools/menu"
                       class="button small-action cancel"><?php echo __('Cancel'); ?></a>
                </div>
            </form>

            <?php if ($mode === 'new'): ?>
                <!-- Auto-suggest the permission key from the name field
                     while the user is typing. Only kicks in as long as
                     the permission field is empty or still matches the
                     auto-derived value -- once the user manually edits
                     it, the script gets out of the way. -->
                <script>
                (function () {
                    var nameEl = document.getElementById('f_name');
                    var permEl = document.getElementById('f_perm');
                    if (!nameEl || !permEl) return;
                    var lastSuggestion = '';
                    var userEdited = false;

                    function slug(s) {
                        return s.toLowerCase()
                                .replace(/[^a-z0-9]+/g, '_')
                                .replace(/^_+|_+$/g, '');
                    }
                    permEl.addEventListener('input', function () {
                        // If the user types a value that isn't our last
                        // suggestion, mark the field as user-owned and
                        // stop auto-suggesting.
                        if (permEl.value !== lastSuggestion) {
                            userEdited = true;
                        }
                    });
                    nameEl.addEventListener('input', function () {
                        if (userEdited) return;
                        var s = slug(nameEl.value);
                        var suggestion = s === '' ? '' : 'view_menu_' + s;
                        permEl.value = suggestion;
                        lastSuggestion = suggestion;
                    });
                })();
                </script>
            <?php endif; ?>

        </div>
    </div>
</div>
