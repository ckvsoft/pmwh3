<?php
declare(strict_types=1);
/**
 * FilteringManager integration test against pmwh3_test on the
 * developer mysql80. Applies the 3.0.17 migration (idempotent), seeds
 * policy rows, verifies the inheritance chain resolution and the
 * external_map settings shape.
 */

require_once __DIR__ . '/../../../library/ckvsoft/autoload.php';
$root = dirname(__DIR__, 3);
new ckvsoft\Autoload([$root . '/library/', $root . '/modules/', $root . '/modules/pmwh3/', __DIR__ . '/../']);
require_once __DIR__ . '/../modulautoload.php';
require_once __DIR__ . '/../utils/filteringmanager.php';

use pmwh3\Utils\FilteringManager;

function t(string $what, bool $ok, array $info = []): void
{
    echo ($ok ? 'PASS' : 'FAIL'), '  ', $what;
    if ($info) {
        echo '  ', json_encode($info);
    }
    echo "\n";
    if (!$ok) {
        exit(1);
    }
}

// --- apply the 3.0.17 migration into pmwh3_test (idempotent) ---------
$sql = file_get_contents(dirname(__DIR__) . '/inc/sql/3.0.17.sql');
foreach (array_filter(array_map('trim', preg_split('/;\s*[\r\n]/', $sql))) as $stmtRaw) {
    // strip inline comment lines (streamed SQL file keeps them)
    $stmt = '';
    foreach (preg_split('/\R/', $stmtRaw) as $line) {
        if (trim($line) === '' || str_starts_with(ltrim($line), '--')) {
            continue;
        }
        $stmt .= $line . "\n";
    }
    $stmt = trim($stmt);
    if ($stmt === '') {
        continue;
    }
    try {
        \ckvsoft\mvc\Config::moduleDb()->exec($stmt);
    } catch (\Throwable $e) {
        t('migration 3.0.17 apply', false, ['err' => $e->getMessage()]);
    }
}
t('migration 3.0.17 applied', true);

// --- seed (idempotent) ----------------------------------------------
$db = \ckvsoft\mvc\Config::moduleDb();
$db->exec("DELETE FROM pmwh3_filtering");
$db->insert('pmwh3_filtering', [
    'scope_type' => 'domain', 'scope' => '@example.org',
    'tag_threshold' => 6.0, 'kill_threshold' => 15.0,
]);
$db->insert('pmwh3_filtering', [
    'scope_type' => 'email', 'scope' => 'vip@example.org',
    'tag_threshold' => 999.0, 'kill_threshold' => 999.0,
]);
t('seed ok', true);

// --- chain resolution ------------------------------------------------
$r = FilteringManager::resolveThresholds('vip@example.org');
t('vip email -> own thresholds', $r !== null && $r['tag_threshold'] === 999.0 && $r['kill_threshold'] === 999.0, $r ?? []);

$r = FilteringManager::resolveThresholds('other@example.org');
t('other email -> domain thresholds', $r !== null && $r['tag_threshold'] === 6.0, $r ?? []);

$r = FilteringManager::resolveThresholds('user@soft.example.org');
t('subdomain email falls to @example.org', $r !== null && (int) $r['tag_threshold'] === 6 && ($r['scope'] === '@example.org' || $r['kill_threshold'] === 15.0), $r ?? []);

$r = FilteringManager::resolveThresholds('user@nowhere.invalid');
t('unrelated scope -> no override (rspamd globals)', $r === null || $r === [] || empty($r['scope']), $r ?? []);

// --- external map shape ----------------------------------------------
$ext = FilteringManager::externalMapSettings(6.0, 15.0, '@example.org');
t('externalMapSettings shape', ($ext['actions']['add header'] ?? 0) === 6.0 && ($ext['actions']['reject'] ?? 0) === 15.0 && isset($ext['id']), $ext);
$ext2 = FilteringManager::externalMapSettings(null, 5.0);
t('externalMapSettings ordering guard', ($ext2['actions']['add header'] ?? 0) < ($ext2['actions']['reject'] ?? 99), $ext2);

// --- cleanup ---------------------------------------------------------
$db->exec("DELETE FROM pmwh3_filtering");
echo "=== ALL PASS (filtering) ===\n";
