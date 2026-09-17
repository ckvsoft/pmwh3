<div class="pmwh3-content">
<?php
$packages    = (array) ($this->data['packages']    ?? []);
$sessionCid  = (int)   ($this->data['session_cid'] ?? 0);
$create_perm = (bool)  ($this->data['create_perm'] ?? false);
$edit_perm   = (bool)  ($this->data['edit_perm']   ?? false);
$delete_perm = (bool)  ($this->data['delete_perm'] ?? false);

$canTouch = function (array $p) use ($sessionCid): bool {
    return $sessionCid === 1 || (int) ($p['creator'] ?? 0) === $sessionCid;
};
?>
<div class="widget">
    <div class="widget-header">
        <h3><?php echo __('Packages'); ?></h3>
        <?php if ($create_perm): ?>
            <a href="<?php echo BASE_URI; ?>pmwh3/package/new_package"
               class="button small-action create"><?php echo __('New package'); ?></a>
        <?php endif; ?>
    </div>
    <div class="widget-body">

        <?php if (empty($packages)): ?>
            <p><em><?php echo __('No packages defined.'); ?></em></p>
        <?php else: ?>
            <div class="paginated" data-per-page="20">
<table class="widget-table">
                <thead>
                    <tr>
                        <th><?php echo __('Name'); ?></th>
                        <th><?php echo __('Creator'); ?></th>
                        <th><?php echo __('Webspace'); ?></th>
                        <th><?php echo __('Traffic'); ?></th>
                        <th><?php echo __('Domains'); ?></th>
                        <th><?php echo __('Subdomains'); ?></th>
                        <th><?php echo __('Emails'); ?></th>
                        <th><?php echo __('Forwards'); ?></th>
                        <th><?php echo __('DBs'); ?></th>
                        <th><?php echo __('PHP'); ?></th>
                        <th><?php echo __('CGI'); ?></th>
                        <th><?php echo __('Actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($packages as $p):
                        $name    = (string) ($p['package_name'] ?? '');
                        $creator = (int)    ($p['creator']      ?? 0);
                        $touchable = $canTouch($p);
                    ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($name); ?></strong></td>
                            <td>
                                <?php
                                if ($creator === 0) {
                                    echo '<em>' . __('system') . '</em>';
                                } else {
                                    echo '#' . $creator;
                                }
                                ?>
                            </td>
                            <td><?php echo (int) ($p['webspace']   ?? 0); ?></td>
                            <td><?php echo (int) ($p['traffic']    ?? 0); ?></td>
                            <td><?php echo (int) ($p['domains']    ?? 0); ?></td>
                            <td><?php echo (int) ($p['subdomains'] ?? 0); ?></td>
                            <td><?php echo (int) ($p['emails']     ?? 0); ?></td>
                            <td><?php echo (int) ($p['forwards']   ?? 0); ?></td>
                            <td><?php echo (int) ($p['dbases']     ?? 0); ?></td>
                            <td><?php echo ($p['php'] ?? 'N') === 'Y' ? __('Yes') : __('No'); ?></td>
                            <td><?php echo ($p['cgi'] ?? 'N') === 'Y' ? __('Yes') : __('No'); ?></td>
                            <td>
                                <?php if ($edit_perm && $touchable): ?>
                                    <a href="<?php echo BASE_URI; ?>pmwh3/package/change_package/<?php echo urlencode($name); ?>/<?php echo $creator; ?>"
                                       class="button small-action edit"><?php echo __('Edit'); ?></a>
                                <?php endif; ?>
                                <?php if ($delete_perm && $touchable): ?>
                                    <form method="post"
                                          action="<?php echo BASE_URI; ?>pmwh3/package/delete_package/<?php echo urlencode($name); ?>/<?php echo $creator; ?>"
                                          class="inline-form"
                                          data-confirm="<?php echo __('Delete this package?'); ?>"
                                          data-confirm-type="delete">
                                        <button type="submit" class="button small-action delete"><?php echo __('Delete'); ?></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
</div>
            <p><small><?php echo __('-1 means unlimited, 0 means disabled. System packages (creator=system) are read-only for non-admin users.'); ?></small></p>
        <?php endif; ?>

    </div>
</div>
</div>
