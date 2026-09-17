<?php
// modules/pmwh3/view/tools/errorlog.php
//
// Tools > Errorlog: live errorlog viewer (shared partial).
// The ERRORLOG_* settings remain in Options > Errorlog ("Settings"
// link below points there).
?>
<div class="pmwh3-content">
<?php
use pmwh3\Config\LazyConfig;
use pmwh3\Utils\ErrorHandler;

$viewerLines = max(10, min(500, (int) ($this->data['viewerLines'] ?? 50)));
$viewerTools = true;
include __DIR__ . '/../options/_viewer.php';
?>
    <div class="form-actions">
        <a href="<?php echo BASE_URI; ?>pmwh3/options/errorlog"
           class="tab-link"><?php echo __('Errorlog settings'); ?></a>
    </div>
</div>
