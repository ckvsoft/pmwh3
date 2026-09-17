<?php
// modules/pmwh3/view/options/_viewer.php
//
// Shared errorlog viewer widget (live rows / file tail depending on
// ERRORLOG_TARGET). Included from:
//   - view/options/errorlog.php (settings section, purge -> options)
//   - view/tools/errorlog.php   (Tools menu entry, purge -> tools)
//
// Expected variables:
//   $viewerLines  - int, how many entries to show
//   $viewerTools  - bool, true when called from the Tools controller
?>
<div class="widget">
    <div class="widget-header">
        <h3><?php echo __('Error log (last ') . $viewerLines . ')'; ?></h3>
        <form method="get" class="inline-form">
            <select name="lines" onchange="this.form.submit()">
                <?php foreach ([25, 50, 100, 200, 500] as $n): ?>
                    <option value="<?= $n ?>"<?= $n === (int) $viewerLines ? ' selected' : '' ?>><?= $n ?></option>
                <?php endforeach; ?>
            </select>
        </form>
        <form method="post" class="inline-form"
              action="<?php echo BASE_URI . ($viewerTools
                  ? 'pmwh3/tools/errorlog_purge'
                  : 'pmwh3/options/errorlog_purge'); ?>">
            <button class="button small-action delete"
                    data-confirm="<?php echo __('Purge entries older than the retention window?'); ?>"
                    data-confirm-type="delete">
                <?php echo __('Purge old'); ?>
            </button>
        </form>
    </div>
    <div class="widget-body">
        <pre class="message-body"><?php
            $target = (string) \pmwh3\Config\LazyConfig::get('ERRORLOG_TARGET', 'file');
            if ($target === 'db') {
                $rows = \pmwh3\Utils\ErrorHandler::latest((int) $viewerLines);
                if (empty($rows)) {
                    echo __('No db errorlog entries.');
                } else {
                    foreach ($rows as $r) {
                        echo htmlspecialchars(sprintf(
                            "%s  %-8s %-4s %s%s\n",
                            (string) $r['ts'],
                            (string) $r['level'],
                            (string) $r['scope'],
                            (string) $r['message'],
                            ((string) ($r['file'] ?? '') !== ''
                                ? '  (' . $r['file'] . ':' . $r['line'] . ')' : ''),
                        ));
                    }
                }
            } else {
                $tail = \pmwh3\Utils\ErrorHandler::tail((int) $viewerLines);
                echo $tail !== ''
                    ? htmlspecialchars($tail)
                    : htmlspecialchars(sprintf(__('Nothing in %s (target: %s).'),
                        \pmwh3\Utils\ErrorHandler::logFilePath(), $target));
            }
        ?></pre>
    </div>
</div>
