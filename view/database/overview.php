<?php
$databases   = (array) ($this->data['databases']   ?? []);
$create_perm = (bool)  ($this->data['create_perm'] ?? false);
$delete_perm = (bool)  ($this->data['delete_perm'] ?? false);
?>
<div class="pmwh3-content">
    <div class="widget">
        <div class="widget-header">
            <h3><?php echo __('Databases'); ?></h3>
            <?php if ($create_perm): ?>
                <a href="<?php echo BASE_URI; ?>pmwh3/database/new_database"
                   class="button small-action create"><?php echo __('New database'); ?></a>
            <?php endif; ?>
        </div>
        <div class="widget-body">

            <?php if (empty($databases)): ?>
                <p><em><?php echo __('No databases yet.'); ?></em></p>
            <?php else: ?>
                <div class="paginated" data-per-page="20">
                <table class="widget-table">
                    <thead>
                        <tr>
                            <th><?php echo __('Database'); ?></th>
                            <th><?php echo __('Customer'); ?></th>
                            <th><?php echo __('Type'); ?></th>
                            <th><?php echo __('Host'); ?></th>
                            <th><?php echo __('Created'); ?></th>
                            <th><?php echo __('Actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($databases as $d):
                            $id   = (int) ($d['id']   ?? 0);
                            $name = (string) ($d['name'] ?? '');
                        ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($name); ?></strong></td>
                                <td><?php echo htmlspecialchars((string) ($d['customer'] ?? '')); ?></td>
                                <td><?php echo htmlspecialchars((string) ($d['db_type'] ?? '')); ?></td>
                                <td><?php echo htmlspecialchars((string) ($d['db_host'] ?? '')); ?></td>
                                <td><?php echo htmlspecialchars((string) ($d['created_at'] ?? '')); ?></td>
                                <td>
                                    <a href="<?php echo BASE_URI; ?>pmwh3/database/details/<?php echo $id; ?>"
                                       class="button small-action edit"><?php echo __('Manage'); ?></a>
                                    <?php if ($delete_perm): ?>
                                        <a href="<?php echo BASE_URI; ?>pmwh3/database/delete_database/<?php echo $id; ?>"
                                           class="button small-action delete"
                                           data-confirm="<?php echo __('Drop this database AND its users?'); ?>"
                                           data-confirm-type="delete"><?php echo __('Drop'); ?></a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>

        </div>
    </div>
</div>
