<?php
$tree         = (array) ($this->data['tree']         ?? []);
$quotas       = (array) ($this->data['quotas']       ?? []);
$advanced     = (bool)  ($this->data['advanced']     ?? true);
$defaultHost  = (string) ($this->data['default_host'] ?? '%');
$create_perm  = (bool)  ($this->data['create_perm']  ?? false);
$delete_perm  = (bool)  ($this->data['delete_perm']  ?? false);
$assign_perm  = (bool)  ($this->data['assign_perm']  ?? false);
$error        = (string) ($this->data['error']       ?? '');

$privKeys = $advanced
        ? ['SELECT','INSERT','UPDATE','DELETE','CREATE','ALTER','DROP']
        : [];
?>
<div class="pmwh3-content">
    <div class="widget">
        <div class="widget-header">
            <h3><?php echo __('Databases'); ?></h3>
            <?php if ($create_perm): ?>
                <a href="<?php echo BASE_URI; ?>pmwh3/databases/new_database"
                   class="button small-action create"><?php echo __('New database'); ?></a>
            <?php endif; ?>
        </div>
        <div class="widget-body">

            <?php if ($error !== ''): ?>
                <p class="pmwh3-error-block"><strong><?php echo __('Database backend error:'); ?></strong>
                    <br><small><?php echo htmlspecialchars($error); ?></small></p>
                <p><small><?php echo __('Check Options > Databases (DB_HOST / DB_ADMIN_USER / DB_ADMIN_PASS) and that pmwh3_admin has SELECT on mysql.*.'); ?></small></p>
            <?php elseif (empty($tree)): ?>
                <p><em><?php echo __('No databases yet.'); ?></em></p>
            <?php else: ?>

                <table class="widget-table">
                    <thead>
                        <tr>
                            <th><?php echo __('Customer'); ?></th>
                            <th><?php echo __('Database'); ?></th>
                            <th><?php echo __('User'); ?></th>
                            <th><?php echo __('Host'); ?></th>
                            <?php foreach ($privKeys as $p): ?>
                                <th><?php echo $p; ?></th>
                            <?php endforeach; ?>
                            <th><?php echo __('Actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($tree as $customer => $dbs):
                        $custEnc = htmlspecialchars($customer);
                        // Quota label
                        $qLabel = '';
                        if (isset($quotas[$customer])) {
                            $q = $quotas[$customer];
                            if ($q['max'] === -1) {
                                $qLabel = ' <span class="pmwh3-quota">(' . __('unlimited') . ')</span>';
                            } elseif ($q['max'] === 0) {
                                $qLabel = ' <span class="pmwh3-quota warn">(' . __('not in package') . ')</span>';
                            } else {
                                $used = $q['used'] + $q['granted'];
                                $cls = $q['available'] === 0 ? 'pmwh3-quota warn' : 'pmwh3-quota';
                                $qLabel = ' <span class="' . $cls . '">('
                                        . $used . '/' . $q['max'] . ')</span>';
                            }
                        }
                        if (empty($dbs)):
                        ?>
                            <tr>
                                <td><strong><?php echo $custEnc; ?></strong><?php echo $qLabel; ?></td>
                                <td colspan="<?php echo 3 + count($privKeys) + 1; ?>">
                                    <em><?php echo __('No databases'); ?></em>
                                </td>
                            </tr>
                        <?php else:
                            $custFirst = true;
                            foreach ($dbs as $dbname => $users):
                                $dbEnc  = htmlspecialchars($dbname);
                                $dbUrl  = urlencode($dbname);
                                $dbFirst = true;
                                if (empty($users)):
                        ?>
                                    <tr>
                                        <td><?php echo $custFirst ? '<strong>' . $custEnc . '</strong>' . $qLabel : ''; ?></td>
                                        <td><strong><?php echo $dbEnc; ?></strong></td>
                                        <td colspan="<?php echo 2 + count($privKeys); ?>"><em><?php echo __('No users'); ?></em></td>
                                        <td>
                                            <?php if ($delete_perm): ?>
                                                <a href="<?php echo BASE_URI; ?>pmwh3/databases/delete_database/<?php echo $dbUrl; ?>"
                                                   class="button small-action delete"
                                                   data-confirm="<?php echo __('Drop this database?'); ?>"
                                                   data-confirm-type="delete"><?php echo __('Delete DB'); ?></a>
                                            <?php endif; ?>
                                            <?php if ($assign_perm): ?>
                                                <button type="button" class="button small-action create"
                                                        onclick="document.getElementById('assign-form-<?php echo md5($dbname); ?>').classList.remove('pmwh3-hidden');"><?php echo __('Assign user'); ?></button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php
                                else:
                                    foreach ($users as $u):
                                        $userEnc = htmlspecialchars((string) $u['username']);
                                        $userUrl = urlencode((string) $u['username']);
                                        $hostEnc = htmlspecialchars((string) $u['host']);
                                        $hostUrl = urlencode((string) $u['host']);
                                        $isAll   = $u['privileges'] === 'ALL';
                                        $userPrivs = $isAll ? $privKeys : explode(',', $u['privileges']);
                                    ?>
                                        <tr>
                                            <td><?php echo $custFirst ? '<strong>' . $custEnc . '</strong>' . $qLabel : ''; ?></td>
                                            <td><?php echo $dbFirst ? '<strong>' . $dbEnc . '</strong>' : ''; ?></td>
                                            <td><?php echo $userEnc; ?></td>
                                            <td><small><?php echo $hostEnc; ?></small></td>
                                            <?php foreach ($privKeys as $p):
                                                $granted = $isAll || in_array($p, $userPrivs, true);
                                            ?>
                                                <td>
                                                    <?php if ($assign_perm): ?>
                                                        <form class="pmwh3-form-inline" method="post"
                                                              action="<?php echo BASE_URI; ?>pmwh3/databases/toggle_privilege/<?php echo $dbUrl; ?>/<?php echo $userUrl; ?>/<?php echo $hostUrl; ?>/<?php echo $p; ?>"
                                                              data-confirm="<?php echo __($granted ? 'Revoke this privilege?' : 'Grant this privilege?'); ?>"
                                                              data-confirm-type="change">
                                                            <button type="submit" class="button small-action <?php echo $granted ? 'save' : 'cancel'; ?>">
                                                                <?php echo $granted ? 'Y' : 'N'; ?>
                                                            </button>
                                                        </form>
                                                    <?php else: ?>
                                                        <?php echo $granted ? 'Y' : 'N'; ?>
                                                    <?php endif; ?>
                                                </td>
                                            <?php endforeach; ?>
                                            <td>
                                                <?php if ($dbFirst && $delete_perm): ?>
                                                    <a href="<?php echo BASE_URI; ?>pmwh3/databases/delete_database/<?php echo $dbUrl; ?>"
                                                       class="button small-action delete"
                                                       data-confirm="<?php echo __('Drop this database?'); ?>"
                                                       data-confirm-type="delete"><?php echo __('Delete DB'); ?></a>
                                                <?php endif; ?>
                                                <?php if ($assign_perm): ?>
                                                    <a href="<?php echo BASE_URI; ?>pmwh3/databases/delete_user/<?php echo $dbUrl; ?>/<?php echo $userUrl; ?>/<?php echo $hostUrl; ?>"
                                                       class="button small-action delete"
                                                       data-confirm="<?php echo __('Remove this user?'); ?>"
                                                       data-confirm-type="delete"><?php echo __('Drop user'); ?></a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php
                                        $dbFirst = false;
                                        $custFirst = false;
                                    endforeach;
                                endif;
                                ?>
                                <?php if ($assign_perm): ?>
                                <tr id="assign-form-<?php echo md5($dbname); ?>" class="pmwh3-inline-form pmwh3-hidden">
                                    <td></td>
                                    <td colspan="<?php echo 3 + count($privKeys) + 1; ?>">
                                        <form method="post" action="<?php echo BASE_URI; ?>pmwh3/databases/assign_user">
                                            <input type="hidden" name="config[database]" value="<?php echo $dbEnc; ?>">
                                            <strong><?php echo sprintf(__('Assign user to %s'), $dbEnc); ?></strong><br>
                                            <label><input type="radio" name="config[mode]" value="new" checked> <?php echo __('Create new user'); ?></label>
                                            <input name="config[username]" placeholder="<?php echo __('username'); ?>" size="15">
                                            <input type="password" name="config[password]" placeholder="<?php echo __('password'); ?>" size="15">
                                            <br>
                                            <label><input type="radio" name="config[mode]" value="existing"> <?php echo __('Assign existing user'); ?></label>
                                            <small>(<?php echo __('see DB users on the server already'); ?>)</small>
                                            <input name="config[existing_user]" placeholder="user|host" size="20">
                                            <br>
                                            <small><?php echo __('Host:'); ?></small>
                                            <input name="config[host]" value="<?php echo htmlspecialchars($defaultHost); ?>" size="8">
                                            <label><input type="checkbox" name="config[priv_all]" value="1" checked> <?php echo __('ALL PRIVILEGES'); ?></label>
                                            <?php if ($advanced): ?>
                                                <br>
                                                <small><?php echo __('Or specific:'); ?></small>
                                                <?php foreach (['select','insert','update','delete','create','alter','drop','index','references'] as $k): ?>
                                                    <label><input type="checkbox" name="config[priv_<?php echo $k; ?>]" value="1"> <?php echo strtoupper($k); ?></label>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                            <br>
                                            <button type="submit" class="button small-action create"><?php echo __('Assign'); ?></button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>

            <?php endif; ?>

        </div>
    </div>
</div>
