<?php
declare(strict_types=1);
/**
 * Tools model + BackupUtil test against pmwh3_test (dev-mysql80).
 * Applies 3.0.18 (applications table), then CRUD roundtrip and a
 * full dump→restore cycle into a scratch DB only.
 */

require_once __DIR__ . '/../../../library/ckvsoft/autoload.php';
$root = dirname(__DIR__, 3);
new ckvsoft\Autoload([$root . '/library/', $root . '/modules/', $root . '/modules/pmwh3/', __DIR__ . '/../']);
require_once __DIR__ . '/../modulautoload.php';
require_once __DIR__ . '/../model/tools_model.php';
require_once __DIR__ . '/../utils/backuputil.php';

use pmwh3\Utils\BackupUtil;

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

// --- migration 3.0.18 (framework replay helper) ------------------------
\ckvsoft\mvc\Config::moduleDb()->executeSqlFile(dirname(__DIR__) . '/inc/sql/3.0.18.sql');
t('migration 3.0.18 applied', true);

// --- fill skeleton tables for backup roundtrip ---
$db = \ckvsoft\mvc\Config::moduleDb();
$db->execDdl("CREATE TABLE IF NOT EXISTS pmwh3_test.pmwh3_news (
  id INT NOT NULL AUTO_INCREMENT, datetime DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  news TEXT, active CHAR(1) NOT NULL DEFAULT 'N', author VARCHAR(100) NOT NULL DEFAULT '',
  authorid INT NOT NULL DEFAULT 0, PRIMARY KEY (id)) ENGINE=InnoDB");

$model = new Tools_Model();

// --- News CRUD --------------------------------------------------------
$idN = $model->saveNews(null, 'Hello news test', true);
t('news create', $idN > 0, ['id' => $idN]);
$ro = $model->getNewsRow($idN);
t('news row readback', ($ro['active'] ?? '') === 'Y');
t('news update', $model->saveNews($idN, 'Updated text', false) > 0);
$ro = $model->getNewsRow($idN);
t('news update readback', ($ro['news'] ?? '') === 'Updated text' && ($ro['active'] ?? '') === 'N');
t('news toggle', $model->toggleNews($idN));
$ro = $model->getNewsRow($idN);
t('news toggle readback', ($ro['active'] ?? '') === 'Y');
t('news empty rejected', $model->saveNews(null, '   ') === 0);

// --- G4: listActiveNews + MAX_NEWS limit ------------------------------
$db->delete('pmwh3_news', 'id > :z', ['z' => 0]);
$idA1 = $model->saveNews(null, 'T1 oldest', true);
$idA2 = $model->saveNews(null, 'T2 middle', true);
$idA3 = $model->saveNews(null, 'T3 newest', false); // inaktiv
$active = $model->listActiveNews(10);
$activeTexts = array_map(fn($r) => (string) ($r['news'] ?? ''), $active);
t('active news count + filter (inactive T3 out)',
    count($active) === 2
    && in_array('T1 oldest', $activeTexts, true)
    && in_array('T2 middle', $activeTexts),
    ['n' => count($active)]);
$capped = $model->listActiveNews(1);
t('MAX_NEWS cap', count($capped) === 1
    && in_array($capped[0]['news'], ['T1 oldest', 'T2 middle'], true));
$lall = $model->listNews(2);
t('listNews limit (admin overview)', count($lall) === 2, ['n' => count($lall)]);
$db->delete('pmwh3_news', 'id IN (:i1,:i2,:i3)',
    ['i1' => $idA1, 'i2' => $idA2, 'i3' => $idA3]);

// --- Applications CRUD ------------------------------------------------
$idA = $model->saveApplication(null, 'QRK', 'https://qrk.example.org', 5);
t('application create', $idA > 0, ['id' => $idA]);
$ro = $model->getApplicationRow($idA);
t('application https-prefix added', str_starts_with((string) ($ro['link'] ?? ''), 'https://'), $ro ?? []);
t('application update', $model->saveApplication($idA, 'QRK Gastro', 'http://gastro', 2) === $idA);
$ro = $model->getApplicationRow($idA);
t('application sort update', (int) $ro['sort'] === 2);

// --- cleanup ----------------------------------------------------------
t('news delete', $model->deleteNews($idN));
t('application delete', $model->deleteApplication($idA));

// --- Backup roundtrip (dump tables of pmwh3_test) ---------------------
$t0 = $model;
$n = BackupUtil::listFiles();
$before = count($n);
$idN2 = $model->saveNews(null, 'survivor row', true);
$file = BackupUtil::backup();
t('backup created', $file !== '', ['file' => $file]);
t('backup listed', count(BackupUtil::listFiles()) === $before + 1);

// alter a table row, then restore and verify survivor
$db->delete('pmwh3_news', 'id = :i', ['i' => $idN2]);
t('deleted row gone', $model->getNewsRow($idN2) === null);
BackupUtil::restore($file);
$ro = $model->getNewsRow($idN2);
t('restore brought row back', is_array($ro) && ($ro['news'] ?? '') === 'survivor row', ['news' => $ro['news'] ?? null]);

t('delete backup', BackupUtil::delete($file));
$model->deleteNews($idN2);
t('news delete 2', true);

echo "=== ALL PASS (tools) ===\n";
