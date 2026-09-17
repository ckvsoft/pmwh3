<?php
$viewData         = $this->data;
$aliases          = $viewData['domains'];
$oneLevelUpDomain = $viewData['oneLevelUpDomain'] ?? null;
$createPerm       = $viewData['create_perm'] ?? false;
$editPerm         = $viewData['edit_perm']   ?? false;
$deletePerm       = $viewData['delete_perm'] ?? false;
?>

<div class="pmwh3-content">
    <div class="widget">
        <div class="widget-header">
            <h3><?php echo __('Aliases'); ?></h3>
            <?php if ($oneLevelUpDomain): ?>
                <form action="overview" method="post" class="inline-form">
                    <input type="hidden" name="domain" value="<?= htmlspecialchars((string) $oneLevelUpDomain) ?>">
                    <button type="submit" class="button small-action cancel"><?php echo __('Back'); ?></button>
                </form>
            <?php endif; ?>
            <?php if ($createPerm): ?>
                <form action="create_subdomain_form" method="get" class="inline-form">
                    <button type="submit" class="button small-action create"><?php echo __('New alias'); ?></button>
                </form>
            <?php endif; ?>
        </div>
        <div class="widget-body">
            <table class="widget-table">
                <thead>
                    <tr>
                        <th><?php echo __('Alias'); ?></th>
                        <th><?php echo __('IP Address'); ?></th>
                        <th><?php echo __('Customer'); ?></th>
                        <th><?php echo __('Actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($aliases as $alias): ?>
                        <tr>
                            <td><?= htmlspecialchars((string) $alias['alias']) ?></td>
                            <td><?= htmlspecialchars((string) $alias['ip']) ?></td>
                            <td><?= htmlspecialchars((string) $alias['customer']) ?></td>
                            <td>
                                <?php if ($editPerm): ?>
                                    <form action="edit_alias" method="post" class="inline-form">
                                        <input type="hidden" name="alias_identifier" value="<?= htmlspecialchars((string) ($alias['id'] ?? $alias['alias'])) ?>">
                                        <button class="button small-action edit"><?php echo __('Change'); ?></button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($deletePerm): ?>
                                    <form action="delete_alias" method="post" class="inline-form"
                                          data-confirm="<?php echo sprintf(__('Delete alias %s?'), htmlspecialchars((string) $alias['alias'])); ?>"
                                          data-confirm-type="delete">
                                        <input type="hidden" name="alias_identifier" value="<?= htmlspecialchars((string) ($alias['id'] ?? $alias['alias'])) ?>">
                                        <button class="button small-action delete"><?php echo __('Delete'); ?></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
