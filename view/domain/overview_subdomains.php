<?php
$viewData         = $this->data;
$subdomains       = $viewData['domains'];
$canViewAlias     = $viewData['canViewAlias'];
$canViewSubdomain = $viewData['canViewSubdomain'];
$createPerm       = $viewData['create_perm'];
$editPerm         = $viewData['edit_perm'];
$deletePerm       = $viewData['delete_perm'];
?>

<div class="pmwh3-content">
    <div class="widget">
        <div class="widget-header">
            <h3><?php echo __('Subdomains'); ?></h3>
            <form action="overview" method="post" class="inline-form">
                <input type="hidden" name="domain" value="">
                <button type="submit" class="button small-action cancel"><?php echo __('Back'); ?></button>
            </form>
            <?php if ($createPerm): ?>
                <form action="<?php echo BASE_URI; ?>pmwh3/domain/subdomain_new/<?php echo urlencode((string) ($viewData['currentDomain'] ?? '')); ?>" method="get" class="inline-form">
                    <button type="submit" class="button small-action create"><?php echo __('New subdomain'); ?></button>
                </form>
            <?php endif; ?>
        </div>
        <div class="widget-body">
            <table class="widget-table">
                <thead>
                    <tr>
                        <th><?php echo __('Subdomain'); ?></th>
                        <th><?php echo __('IP Address'); ?></th>
                        <th><?php echo __('Customer'); ?></th>
                        <th><?php echo __('# Aliases'); ?></th>
                        <th><?php echo __('# Subdomains'); ?></th>
                        <th><?php echo __('Actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($subdomains as $sub): ?>
                        <tr>
                            <td><?= htmlspecialchars((string) $sub['subdomain']) ?></td>
                            <td><?= htmlspecialchars((string) $sub['ip']) ?></td>
                            <td><?= htmlspecialchars((string) $sub['customer']) ?></td>
                            <td>
                                <?= (int) $sub['alias'] ?>
                                <?php if ($canViewAlias): ?>
                                    <form action="overview" method="post" class="inline-form">
                                        <input type="hidden" name="alias" value="<?= htmlspecialchars((string) $sub['subdomain']) ?>">
                                        <button class="button small-action edit"><?php echo __('View'); ?></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= (int) ($sub['sub_subdomain'] ?? 0) ?>
                                <?php if ($canViewSubdomain): ?>
                                    <form action="overview" method="post" class="inline-form">
                                        <input type="hidden" name="domain" value="<?= htmlspecialchars((string) $sub['subdomain']) ?>">
                                        <button class="button small-action edit"><?php echo __('View'); ?></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($editPerm): ?>
                                    <a href="<?php echo BASE_URI; ?>pmwh3/domain/subdomain_edit/<?php echo urlencode((string) $sub['subdomain']); ?>"
                                       class="button small-action edit"><?php echo __('Change'); ?></a>
                                <?php endif; ?>
                                <?php if ($deletePerm): ?>
                                    <form action="<?php echo BASE_URI; ?>pmwh3/domain/subdomain_delete" method="post" class="inline-form"
                                          data-confirm="<?php echo sprintf(__('Delete subdomain %s?'), htmlspecialchars((string) $sub['subdomain'])); ?>"
                                          data-confirm-type="delete">
                                        <input type="hidden" name="subdomain" value="<?= htmlspecialchars((string) ($sub['id'] ?? $sub['subdomain'])) ?>">
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
