<?php
$viewData      = $this->data;
$domains       = (array) ($viewData['domains'] ?? []);
$create_perm   = (bool)  ($viewData['create_perm']      ?? false);
$edit_perm     = (bool)  ($viewData['edit_perm']        ?? false);
$delete_perm   = (bool)  ($viewData['delete_perm']      ?? false);
$advanced_perm = (bool)  ($viewData['advanced_perm']    ?? false);
$canViewSub    = (bool)  ($viewData['canViewSubdomain'] ?? false);
$currentDomain = (string) (\ckvsoft\Session::getNs('pmwh3', 'customer_current_domain') ?? '');
?>
<div class="pmwh3-content">
    <div class="widget">
        <div class="widget-header">
            <h3><?php echo __('Domains'); ?></h3>
            <?php if ($create_perm): ?>
                <a href="<?php echo BASE_URI; ?>pmwh3/domain/new_domain"
                   class="button small-action create"><?php echo __('New domain'); ?></a>
            <?php endif; ?>
        </div>
        <div class="widget-body">

            <?php if (empty($domains)): ?>
                <p><em><?php echo __('No domains.'); ?></em></p>
            <?php else: ?>
                <div class="paginated" data-per-page="20">
<table class="widget-table">
                    <thead>
                        <tr>
                            <th><?php echo __('Domain'); ?></th>
                            <th><?php echo __('IP Address'); ?></th>
                            <th><?php echo __('Customer'); ?></th>
                            <th><?php echo __('Sub./Aliases'); ?></th>
                            <th><?php echo __('Actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($domains as $dom):
                            $dn = (string) $dom['domain'];
                            $isCurrent = $currentDomain !== '' && $currentDomain === $dn;
                        ?>
                            <tr<?php echo $isCurrent ? ' class="row-current"' : ''; ?>>
                                <td><strong><?php echo htmlspecialchars($dn); ?></strong>
                                    <?php if ($isCurrent): ?>
                                        <small>&laquo;<?php echo __('current'); ?>&raquo;</small>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars((string) ($dom['ip'] ?? '')); ?></td>
                                <td><?php echo htmlspecialchars((string) ($dom['customer'] ?? '')); ?></td>
                                <td><?php echo (int) ($dom['subdomain'] ?? 0); ?>
                                    / <?php echo (int) ($dom['aliases'] ?? 0); ?></td>
                                <td>
                                    <?php if ($canViewSub): ?>
                                        <form action="<?php echo BASE_URI; ?>pmwh3/domain/overview" method="post" class="inline-form">
                                            <input type="hidden" name="domain" value="<?php echo htmlspecialchars($dn); ?>">
                                            <button type="submit" class="button small-action blue"><?php echo __('Subdomains'); ?></button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($edit_perm): ?>
                                        <a href="<?php echo BASE_URI; ?>pmwh3/domain/change_domain/<?php echo urlencode($dn); ?>"
                                           class="button small-action edit"><?php echo __('Edit'); ?></a>
                                    <?php endif; ?>
                                    <?php if ($advanced_perm): ?>
                                        <a href="<?php echo BASE_URI; ?>pmwh3/domain/details/<?php echo urlencode($dn); ?>"
                                           class="button small-action yellow"
                                           title="<?php echo __('Advanced details / DNS / Apache'); ?>"><?php echo __('Details'); ?></a>
                                    <?php endif; ?>
                                    <?php if ($delete_perm): ?>
                                        <form action="<?php echo BASE_URI; ?>pmwh3/domain/delete_domain/<?php echo urlencode($dn); ?>"
                                              method="post" class="inline-form"
                                              data-confirm="<?php echo __('Delete this domain (and all its data)?'); ?>"
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
            <?php endif; ?>

        </div>
    </div>
</div>
