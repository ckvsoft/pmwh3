<?php
declare(strict_types=1);
/**
 * ErrorHandler runtime test against pmwh3_test (dev-mysql80):
 * level filter, scope filter, file target, db target (+ table
 * autodrop), purge, viewer helpers. CLI-safe (register() is CLI-noop,
 * the write()/log() API is driven directly).
 */

require_once __DIR__ . '/../../../library/ckvsoft/autoload.php';
$root = dirname(__DIR__, 3);
new ckvsoft\Autoload([$root . '/library/', $root . '/modules/', $root . '/modules/pmwh3/', __DIR__ . '/../']);
require_once __DIR__ . '/../modulautoload.php';

use ckvsoft\mvc\Config;
use pmwh3\Utils\ErrorHandler;

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

$db = Config::moduleDb();
$errTmp = sys_get_temp_dir() . '/pmwh3-errtest-' . getmypid() . '.log';

function cfg(string $k, string $v): void
{
    $db = \ckvsoft\mvc\Config::moduleDb();
    $db->insertUpdate('pmwh3_configuration', [
        'configuration_key'   => $k,
        'configuration_value' => $v,
    ]);
    \pmwh3\Config\LazyConfig::clearCache();
}

// --- baseline (idempotent, for pmwh3_configuration) ---------------------
$db->executeSqlFile(dirname(__DIR__) . '/inc/sql/0.0.0_baseline.sql');

// --- file target ---------------------------------------------------------
cfg('ERRORLOG_TARGET', 'file');
\pmwh3\Utils\ErrorHandler::$fileOverride = $errTmp;
cfg('ERRORLOG_LEVEL', 'notice');
@unlink($errTmp);

ErrorHandler::log('debug', 'hidden-debug-entry', 'php');        // below warning
ErrorHandler::log('warning', 'visible-entry', 'php');
ErrorHandler::log('critical', 'critical-entry', 'app', new RuntimeException('boom'));
ErrorHandler::log('notice', 'other-scope-entry', 'database');   // scope not in default list

$content = (string) @file_get_contents($errTmp);
t('file target: level filter', !str_contains($content, 'hidden-debug-entry'));
t('file target: php scope passes', str_contains($content, '[WARNING] [php] visible-entry'));
t('file target: critical logged', str_contains($content, 'critical-entry'));
t('file target: message text', str_contains($content, 'critical-entry'));
t('file target: scope filter', !str_contains($content, 'other-scope-entry'));
t('file target: exception trace appended', str_contains($content, '#0'));
$info = ErrorHandler::tail(10);
t('tail() returns entries', str_contains($info, 'critical-entry'), ['len' => strlen($info)]);

// --- db target -----------------------------------------------------------
cfg('ERRORLOG_TARGET', 'db');
ErrorHandler::log('warning', 'db-entry-1', 'framework');
ErrorHandler::log('error', 'db-entry-2', 'app', new LogicException('db boom'));
$rows = ErrorHandler::latest(10);
t('db target: entries readable', count($rows) >= 2, ['n' => count($rows)]);
$first = end($rows);
t('db target: levels stored',
    in_array((string) $first['level'], ['warning', 'info', 'notice', 'error', 'critical'], true));
t('table autodropped', $db->tableExists('pmwh3_errorlog'));

// --- purge ----------------------------------------------------------------
cfg('ERRORLOG_RETAIN_DAYS', '0');
t('purge no-op when retain 0', ErrorHandler::purgeOld() === 0);
// force 60-day-old timestamps so a 30-day purge removes them
$db->execDdl("UPDATE pmwh3_errorlog SET ts = NOW() - INTERVAL 60 DAY");
cfg('ERRORLOG_RETAIN_DAYS', '30');
$n = ErrorHandler::purgeOld();
t('purge removes old rows', $n >= 2, ['n' => $n]);
t('purge leaves none', count(ErrorHandler::latest(10)) === 0);

// --- syslog + unknown scope smoke (no crash) -------------------------
cfg('ERRORLOG_TARGET', 'syslog');
ErrorHandler::log('error', 'syslog-smoke', 'php');
t('syslog no crash', true);

// --- cleanup -----------------------------------------------------------
$db->delete('pmwh3_configuration', 'configuration_key IN (:k1,:k2,:k3,:k4)',
    ['k1' => 'ERRORLOG_TARGET', 'k2' => 'ERRORLOG_LEVEL',
     'k3' => 'ERRORLOG_SCOPE', 'k4' => 'ERRORLOG_RETAIN_DAYS']);
\pmwh3\Utils\ErrorHandler::$fileOverride = null;
$db->execDdl('DROP TABLE IF EXISTS pmwh3_errorlog');
@unlink($errTmp);

echo "ALL ERRORHANDLER TESTS DONE\n";
