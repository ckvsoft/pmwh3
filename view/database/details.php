<?php
$row          = (array) ($this->data['row']          ?? []);
$users        = (array) ($this->data['users']        ?? []);
$advanced     = (bool)  ($this->data['advanced']     ?? true);
$defaultHost  = (string) ($this->data['default_host'] ?? '%');
$edit_perm    = (bool)  ($this->data['edit_perm']    ?? false);

$id   = (int) ($row['id'] ?? 0);
$name = (string) ($row['name'] ?? '');

$privKeys = ['select','insert','update','delete','create','alter','drop','index','references'];
?>
<div class="pmwh3-content">
    <div class="widget">
        <div class="widget-header">
            <h3><?php echo sprintf(__('Database: %s'), htmlspecialchars($name)); ?></h3>
            <a href="<?php echo BASE_URI; ?>pmwh3/database/overview"
               class="button small-action cancel"><?php echo __('Back'); ?></a>
        </div>
        <div class="widget-body">

            <table class="widget-table">
                <tr><td><?php echo __('Name'); ?></td><td><strong><?php echo htmlspecialchars($name); ?></strong></td></tr>
                <tr><td><?php echo __('Customer'); ?></td><td><?php echo htmlspecialchars((string) ($row['customer'] ?? '')); ?></td></tr>
                <tr><td><?php echo __('Type'); ?></td><td><?php echo htmlspecialchars((string) ($row['db_type'] ?? '')); ?></td></tr>
                <tr><td><?php echo __('Host'); ?></td><td><?php echo htmlspecialchars((string) ($row['db_host'] ?? '')); ?></td></tr>
                <tr><td><?php echo __('Created'); ?></td><td><?php echo htmlspecialchars((string) ($row['created_at'] ?? '')); ?></td></tr>
            </table>

            <div class="widget-subheader" style="margin-top:1.5em;">
                <?php echo __('Database users'); ?>
            </div>

            <?php if (empty($users)): ?>
                <p><em><?php echo __('No users yet. Add one below.'); ?></em></p>
            <?php else: ?>
                <table class="widget-table">
                    <thead>
                        <tr>
                            <th><?php echo __('User'); ?></th>
                            <th><?php echo __('Host'); ?></th>
                            <th><?php echo __('Privileges'); ?></th>
                            <th><?php echo __('Created'); ?></th>
                            <?php if ($edit_perm): ?>
                                <th><?php echo __('Actions'); ?></th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u): $uid = (int) $u['id']; ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string) $u['username']); ?></td>
                                <td><?php echo htmlspecialchars((string) $u['host']); ?></td>
                                <td><small><?php echo htmlspecialchars((string) $u['privileges']); ?></small></td>
                                <td><?php echo htmlspecialchars((string) ($u['created_at'] ?? '')); ?></td>
                                <?php if ($edit_perm): ?>
                                    <td>
                                        <form style="display:inline;" method="post"
                                              action="<?php echo BASE_URI; ?>pmwh3/database/change_user_password/<?php echo $uid; ?>"
                                              data-confirm="<?php echo __('Set a new password for this user?'); ?>"
                                              data-confirm-type="change">
                                            <input type="password" name="config[password]" placeholder="<?php echo __('new password'); ?>" required style="width:120px;">
                                            <button type="submit" class="button small-action"><?php echo __('Change'); ?></button>
                                        </form>
                                        <a href="<?php echo BASE_URI; ?>pmwh3/database/delete_user/<?php echo $uid; ?>"
                                           class="button small-action delete"
                                           data-confirm="<?php echo __('Remove this user?'); ?>"
                                           data-confirm-type="delete"><?php echo __('Remove'); ?></a>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php if ($edit_perm): ?>
                <div class="widget-subheader" style="margin-top:1.5em;">
                    <?php echo __('Add database user'); ?>
                </div>
                <form method="post" action="<?php echo BASE_URI; ?>pmwh3/database/add_user/<?php echo $id; ?>">
                    <table class="widget-table">
                        <tr>
                            <td><?php echo __('Username'); ?></td>
                            <td><input name="config[username]" required></td>
                        </tr>
                        <tr>
                            <td><?php echo __('Password'); ?></td>
                            <td><input type="password" name="config[password]" required></td>
                        </tr>
                        <tr>
                            <td><?php echo __('Host'); ?></td>
                            <td><input name="config[host]" value="<?php echo htmlspecialchars($defaultHost); ?>">
                                <small><?php echo __('% = any, localhost = local, or specific IP'); ?></small>
                            </td>
                        </tr>
                        <tr>
                            <td><?php echo __('Privileges'); ?></td>
                            <td>
                                <label>
                                    <input type="checkbox" name="config[priv_all]" value="1" checked>
                                    <?php echo __('Full access (ALL PRIVILEGES)'); ?>
                                </label>
                                <?php if ($advanced): ?>
                                    <br>
                                    <small><?php echo __('Or pick specific:'); ?></small><br>
                                    <?php foreach ($privKeys as $k): ?>
                                        <label style="margin-right:.5em;">
                                            <input type="checkbox" name="config[priv_<?php echo $k; ?>]" value="1">
                                            <?php echo strtoupper($k); ?>
                                        </label>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                    <div style="margin-top:1em;">
                        <button type="submit" class="button"><?php echo __('Add user'); ?></button>
                    </div>
                </form>
            <?php endif; ?>

        </div>
    </div>
</div>
