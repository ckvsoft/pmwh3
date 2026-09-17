<?php
declare(strict_types=1);
/**
 * G6: DB_NAME_PREFIX style in DatabaseManager::composeName()
 * (customer prefix vs suffix as-is). Small focused unit test against
 * the dev test DB (LazyConfig needs a DB for the setting read).
 */

require_once __DIR__ . '/../../../library/ckvsoft/autoload.php';
$root = dirname(__DIR__, 3);
new ckvsoft\Autoload([
    $root . '/library/',
    $root . '/modules/',
    $root . '/modules/pmwh3/',
]);
require_once __DIR__ . '/../modulautoload.php';
require_once __DIR__ . '/../../../modules/pmwh3/utils/db/abstractdbadapter.php';
require_once __DIR__ . '/../../../modules/pmwh3/utils/db/mysqldbadapter.php';

use ckvsoft\mvc\Config;
use pmwh3\Config\LazyConfig;
use pmwh3\Utils\DatabaseManager;

function t(string $what, bool $ok, array $info = []): void
{
    echo ($ok ? 'PASS' : 'FAIL'), '  ', $what;
    if ($info) { echo '  ', json_encode($info); }
    echo "\n";
    if (!$ok) { exit(1); }
}

LazyConfig::clearCache();

// default style (no row): customer prefix
$configRow = Config::moduleDb()->selectOne(
    "SELECT configuration_value FROM pmwh3_configuration WHERE configuration_key = 'DB_NAME_PREFIX'", []);
if ($configRow !== null) {
    Config::moduleDb()->delete('pmwh3_configuration', 'configuration_key = :k', ['k' => 'DB_NAME_PREFIX']);
    LazyConfig::clearCache('DB_NAME_PREFIX');
}

// 1) default (no row) -> customer style
t('default style customer prefix',
    DatabaseManager::composeName('g6cust', 'blog') === 'g6cust_blog');

// 2) explicit customer row
Config::moduleDb()->insert('pmwh3_configuration',
        ['configuration_key' => 'DB_NAME_PREFIX', 'configuration_value' => 'customer']);
LazyConfig::clearCache('DB_NAME_PREFIX');
t('customer style explicit',
    DatabaseManager::composeName('g6cust', 'blog') === 'g6cust_blog');

// 3) none style -> suffix as-is (cleaned/lowered)
Config::moduleDb()->update('pmwh3_configuration', ['configuration_value' => 'none'],
        'configuration_key = :k', ['k' => 'DB_NAME_PREFIX']);
LazyConfig::clearCache('DB_NAME_PREFIX');
t('none style uses suffix as-is',
    DatabaseManager::composeName('g6cust', 'blog') === 'blog');

// 4) sanitisation unchanged in both styles
Config::moduleDb()->update('pmwh3_configuration', ['configuration_value' => 'customer'],
        'configuration_key = :k', ['k' => 'DB_NAME_PREFIX']);
LazyConfig::clearCache('DB_NAME_PREFIX');
t('cleaning stays (customer style)',
    strpos(DatabaseManager::composeName('G6 Cööst!', 'weird $#name!!'), 'g6cst_') === 0,
    ['n' => DatabaseManager::composeName('G6 Cööst!', 'weird $#name!!')]);
// 5) none-style cleaning: chars filtered + lowercase as before
Config::moduleDb()->update('pmwh3_configuration', ['configuration_value' => 'none'],
        'configuration_key = :k', ['k' => 'DB_NAME_PREFIX']);
LazyConfig::clearCache('DB_NAME_PREFIX');
t('cleaning stays (none style)',
    DatabaseManager::composeName('g6cust', 'We##ird?')
        === strtolower(preg_replace('/[^A-Za-z0-9_]/', '', 'We##ird?')));

// --- cleanup ------------------------------------------------------------
Config::moduleDb()->delete('pmwh3_configuration', 'configuration_key = :k', ['k' => 'DB_NAME_PREFIX']);
LazyConfig::clearCache('DB_NAME_PREFIX');

echo "ALL G6 TESTS DONE\n";
