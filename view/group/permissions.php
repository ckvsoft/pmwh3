<?php
/**
 * Permissions grid for a single customer-group role.
 *
 * Data:
 *   - group      : ['gid' => roleId, 'name' => roleName]
 *                  ('gid' key kept for backwards compat with the
 *                   surrounding overview/edit views)
 *   - grouped    : ['Category' => [['permId', 'bareKey', 'hasit'], ...], ...]
 *                  empty categories already dropped by AclManager
 *   - viewerCgrp : the editor's own role-id (for the self-edit rail)
 *   - viewerCid  : the editor's customer-id (cid=1 = ultimate admin)
 *   - change_perm: whether the editor is allowed to actually save
 *                  changes (read-only display if false)
 */
$group       = (array) ($this->data['group']       ?? []);
$grouped     = (array) ($this->data['grouped']     ?? []);
$viewerCgrp  = (int)   ($this->data['viewerCgrp']  ?? 0);
$viewerCid   = (int)   ($this->data['viewerCid']   ?? 0);
$change_perm = (bool)  ($this->data['change_perm'] ?? false);

$roleId    = (int) ($group['gid'] ?? 0);
$groupName = (string) ($group['name'] ?? '');

// Self-edit rail mirrors AclManager::applyGrid: same role-id as
// the viewer's is blocked, except for the ultimate admin
// (cid=1) who is the safety net of last resort.
$isSelfEdit = ($roleId === $viewerCgrp) && ($viewerCid !== 1);
?>
<div class="pmwh3-content">
    <div class="widget">
        <div class="widget-header">
            <h3>
                <?php echo __('Permissions'); ?>
                &mdash;
                <?php echo htmlspecialchars($groupName); ?>
            </h3>
            <a href="<?php echo BASE_URI; ?>pmwh3/group/overview"
               class="button small-action cancel"><?php echo __('Back'); ?></a>
        </div>
        <div class="widget-body">

            <?php if ($isSelfEdit): ?>
                <p class="pmwh3-error-block">
                    <strong><?php echo __('Self-edit blocked'); ?>:</strong>
                    <?php echo __('You cannot edit the permissions of your own customer group.'); ?>
                </p>
            <?php elseif (empty($grouped)): ?>
                <p><em><?php echo __('No permissions to show.'); ?></em></p>
            <?php else: ?>

                <!-- Grid form: one checkbox per permission, hidden
                     known[] so the save handler can detect what was
                     unchecked (HTML forms don't post unchecked boxes).
                     Categories are rendered as collapsible <details>
                     blocks -- the first one is open by default so
                     the page isn't entirely collapsed on first open. -->
                <form method="post"
                      action="<?php echo BASE_URI; ?>pmwh3/group/save_permissions"
                      data-confirm="<?php echo __('Save permission changes?'); ?>"
                      data-confirm-type="change">
                    <input type="hidden" name="roleId" value="<?php echo $roleId; ?>">

                    <?php $first = true; foreach ($grouped as $catLabel => $perms): ?>
                        <details class="pmwh3-permcat" <?php if ($first): echo 'open'; endif; $first = false; ?>>
                            <summary>
                                <strong><?php echo htmlspecialchars((string) __($catLabel)); ?></strong>
                                <span class="pmwh3-permcat-count">
                                    (<?php echo count($perms); ?>)
                                </span>
                            </summary>
                            <table class="widget-table">
                                <thead>
                                    <tr>
                                        <th><?php echo __('Granted'); ?></th>
                                        <th><?php echo __('Permission'); ?></th>
                                        <th class="pmwh3-permkey-col"><?php echo __('Key'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($perms as $p):
                                        $permId  = (int)  $p['permId'];
                                        $hasit   = (bool) ($p['hasit'] ?? false);
                                        $bareKey = (string) ($p['bareKey'] ?? '');
                                        // Translate the bare key for a human-
                                        // readable label. If no translation
                                        // exists, gettext returns the msgid
                                        // (= the key), so the column gracefully
                                        // falls back to showing the key in
                                        // both columns.
                                        $label   = __($bareKey);
                                    ?>
                                        <tr>
                                            <td>
                                                <input type="hidden" name="known[]" value="<?php echo $permId; ?>">
                                                <input type="checkbox"
                                                       name="permId[<?php echo $permId; ?>]"
                                                       value="1"
                                                       <?php if ($hasit): ?>checked<?php endif; ?>
                                                       <?php if (!$change_perm): ?>disabled<?php endif; ?>>
                                            </td>
                                            <td><?php echo htmlspecialchars($label); ?></td>
                                            <td class="pmwh3-permkey-col"><code><?php echo htmlspecialchars($bareKey); ?></code></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </details>
                    <?php endforeach; ?>

                    <?php if ($change_perm): ?>
                        <div class="pmwh3-form-actions">
                            <button type="submit" class="button small-action save">
                                <?php echo __('Save'); ?>
                            </button>
                        </div>
                    <?php endif; ?>
                </form>

            <?php endif; ?>

        </div>
    </div>
</div>
