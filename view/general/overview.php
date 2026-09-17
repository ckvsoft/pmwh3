<div class="pmwh3-content">
<?php
/**
 * Dashboard landing page.
 *
 * Sections come from General_Model::overview(), each is an array of
 * {key => label, value => value} or similar. Security items also have
 * a 'good' boolean for the green/red marker.
 */
$sections = [
    'resources' => __('Used resources'),
    'server'    => __('Server Summary'),
    'security'  => __('Security Details'),
    'groups'    => __('Group Details'),
    'pmwh'      => __('PMWH Details'),
];

// G-Apps widget: pmwh2 "Customer Applications" tiles. Hidden when
// APPS_PER_ROW=OFF or no applications defined (empty 'applications').
$appsBlock = (array) ($this->data['applications'] ?? []);
?>
<?php if (!empty($appsBlock['apps'])): $appsPerRow = max(1, (int) $appsBlock['perRow']); ?>
    <div class="widget">
        <div class="widget-header">
            <h3><?php echo __('Applications'); ?></h3>
        </div>
        <div class="widget-body">
            <div class="apps-grid"
                 style="grid-template-columns: repeat(<?php echo (int) $appsPerRow; ?>, 1fr);">
                <?php foreach ($appsBlock['apps'] as $appsItem): ?>
                    <a class="apps-tile"
                       href="<?php echo htmlspecialchars((string) ($appsItem['link'] ?? '')); ?>"
                       target="_blank" rel="noopener noreferrer">
                        <?php echo htmlspecialchars((string) ($appsItem['name'] ?? '')); ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php endif; ?>
<?php foreach ($sections as $key => $title): ?>
    <?php if (!empty($this->data[$key])): ?>
        <div class="widget">
            <div class="widget-header">
                <h3><?php echo $title; ?></h3>
            </div>
            <div class="widget-body">
                <table class="widget-table">
                    <?php foreach ($this->data[$key] as $item): ?>
                        <tr>
                            <td class="widget-key">
                                <?php
                                $label = (string) ($item['key'] ?? '');
                                if ($label === '' && isset($item['groupname'])) {
                                    $label = (string) $item['groupname'];
                                    $label = __($label);
                                }
                                echo htmlspecialchars($label);
                                ?>:
                            </td>
                            <td class="widget-value">
                                <?php
                                if ($key === 'security') {
                                    echo '<span class="' . (!empty($item['good']) ? 'security-green' : 'security-red') . '">'
                                            . htmlspecialchars((string) $item['value']) . '</span>';
                                } else {
                                    echo htmlspecialchars((string) ($item['value'] ?? $item['count'] ?? ''));
                                }
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        </div>
    <?php endif; ?>
<?php endforeach; ?>
</div>
