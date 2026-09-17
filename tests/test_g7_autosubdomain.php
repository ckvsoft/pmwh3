<?php
declare(strict_types=1);
/**
 * G7 Auto-Provision tests: SubdomainManager::provisionDefaults() +
 * DomainManager::create wiring (CREATE_SUBDOMAIN + customer
 * standard_subdomain flag), lifecycle against the dev-mysql80 test DB.
 * Idempotent: cleans up afterwards.
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
use pmwh3\Utils\DomainManager;
use pmwh3\Utils\SubdomainManager;
use pmwh3\Config\LazyConfig;

function t(string $what, bool $ok, array $info = []): void
{
    echo ($ok ? 'PASS' : 'FAIL'), '  ', $what;
    if ($info) { echo '  ', json_encode($info); }
    echo "\n";
    if (!$ok) { exit(1); }
}

$db    = Config::moduleDb();
$domain = 'g7-test.pmwh3tetstl.at';
$pattern = 'www(ftp);smtp(imap,pop3,mail)';

// opportunistic schema step: pmwh3_customers.standard_subdomain must
// exist on the test DB (older baselines don't ship it).
$hasCol = $db->selectOne(
        "SELECT COUNT(*) AS n FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = 'pmwh3_customers'
            AND column_name = 'standard_subdomain'", []);
if (empty($hasCol['n'])) {
    $db->execDdl("ALTER TABLE pmwh3_customers
            ADD COLUMN standard_subdomain CHAR(1) NOT NULL DEFAULT 'N'");
}

// 0) idempotent cleanup from earlier runs (delete cascades aliases)
DomainManager::delete($domain);
Config::moduleDb()->delete('pmwh3_web_subdomains', 'domain = :d', ['d' => $domain]);
Config::moduleDb()->delete('pmwh3_domains', 'domain = :d', ['d' => $domain]);

// dedicated test customer (pmwh3_test has no baseline rows)
$cid = 0;
$existing = Config::moduleDb()->selectOne("SELECT cid, customer FROM pmwh3_customers WHERE customer = 'g7cust'", []);
if ($existing !== null) {
    $cid = (int) $existing['cid'];
} else {
    Config::moduleDb()->insert('pmwh3_customers', [
        'customer' => 'g7cust', 'role_id' => 3, 'email' => 'g7@pmwh3.test',
        'realname' => 'G7 Test', 'password' => str_repeat('0', 64),
        'creator' => 0, 'php' => 'N', 'cgi' => 'N', 'standard_subdomain' => 'Y',
    ]);
    $cid = (int) Config::moduleDb()->lastInsertId();
}
t('test customer exists', $cid > 0, ['cid' => $cid]);
$cid = $cid ?: 1;

// quota scaffold (unlimited subdomains for the test customer)
Config::moduleDb()->delete('pmwh3_countings', 'cid = :c AND type = :t', ['c' => $cid, 't' => 'max']);
Config::moduleDb()->delete('pmwh3_countings', 'cid = :c AND type = :t', ['c' => $cid, 't' => 'used']);
Config::moduleDb()->insert('pmwh3_countings', ['cid' => $cid, 'type' => 'max', 'subdomains' => -1]);
Config::moduleDb()->insert('pmwh3_countings', ['cid' => $cid, 'type' => 'used', 'subdomains' => 0]);
Config::moduleDb()->update('pmwh3_customers', ['standard_subdomain' => 'Y'], 'cid = :c', ['c' => $cid]);

// --- direct provisionDefaults test ---------------------------------------
LazyConfig::clearCache();
LazyConfig::set('CREATE_SUBDOMAIN', 'Y');

// ensure DNS zone exists so subdomain DNS best-effort succeeds-or-skips cleanly
$dnsType = (string) LazyConfig::get('DNS_TYPE', 'pdns');
$dnsEnabled = \pmwh3\Utils\DnsManager::isEnabled();
DomainManager::create([
    'domain' => $domain, 'cid' => $cid,
    'services' => 'web,' . ($dnsEnabled ? 'dns' : 'web'), 'ip' => '1.2.3.4',
]);

$rows = \pmwh3\Utils\WebManager::listByDomain($domain);
$labels = [];
foreach ($rows as $r) { $labels[(string) $r['subdomain']] = (string) ($r['alias_of'] ?? ''); }

foreach (['www' => '', 'smtp' => ''] as $want => $ignore) {
    t('directory created: ' . $want, isset($labels[$want . '.' . $domain]));
}
foreach (['ftp' => 'www', 'imap' => 'smtp', 'pop3' => 'smtp', 'mail' => 'smtp'] as $want => $target) {
    $wFq = $want . '.' . $domain;
    t('alias ' . $want . ' of ' . $target, ($labels[$wFq] ?? null) === $target . '.' . $domain);
}

// --- flag OFF (customer level) suppresses ---------------------------------
$db->update('pmwh3_customers', ['standard_subdomain' => 'N'], 'cid = :c', ['c' => $cid]);
LazyConfig::set('CREATE_SUBDOMAIN', 'Y');
// remove the zone first so create() is a clean second run
if ($dnsEnabled) { \pmwh3\Utils\DnsManager::deleteZone($domain); }
DomainManager::delete($domain);
Config::moduleDb()->delete('pmwh3_web_subdomains', 'domain = :d', ['d' => $domain]);
Config::moduleDb()->delete('pmwh3_domains', 'domain = :d', ['d' => $domain]);
DomainManager::create([
    'domain' => $domain, 'cid' => $cid,
    'services' => 'web,' . ($dnsEnabled ? 'dns' : 'web'), 'ip' => '1.2.3.4',
]);
t('customer flag N -> no standard subdomains',
    !isset($labelsAfter[$domain]));

// --- flag on, global setting off suppresses -------------------------------
$db->update('pmwh3_customers', ['standard_subdomain' => 'Y'], 'cid = :c', ['c' => $cid]);
LazyConfig::set('CREATE_SUBDOMAIN', 'N');
if ($dnsEnabled) { \pmwh3\Utils\DnsManager::deleteZone($domain); }
DomainManager::delete($domain);
Config::moduleDb()->delete('pmwh3_web_subdomains', 'domain = :d', ['d' => $domain]);
Config::moduleDb()->delete('pmwh3_domains', 'domain = :d', ['d' => $domain]);
DomainManager::create([
    'domain' => $domain, 'cid' => $cid,
    'services' => 'web,' . ($dnsEnabled ? 'dns' : 'web'), 'ip' => '1.2.3.4',
]);
t('CREATE_SUBDOMAIN N -> no standard subdomains',
    !isset($labelsAfter[$domain]));

// --- reserved label skip --------------------------------------------------
LazyConfig::set('CREATE_SUBDOMAIN', 'Y');
$p = SubdomainManager::provisionDefaults('uncreated.pmwh3tetstl.at', $cid, 'www(ftp);mailadmin;sm@(x)');
t('unknown parent domain -> all skipped',
    count($p['created']) === 0 && count($p['skipped']) === 2, $p['skipped']);

// --- custom pattern override ---------------------------------------------
$p2 = SubdomainManager::provisionDefaults($domain, $cid, 'shop(cdn)');
t('custom pattern creates shop',
    in_array('shop.' . $domain, $p2['created']) && in_array('cdn.' . $domain, $p2['created']), $p2);

// --- cleanup --------------------------------------------------------------
DomainManager::delete($domain);
Config::moduleDb()->delete('pmwh3_domains', 'domain = :d', ['d' => $domain]);
Config::moduleDb()->delete('pmwh3_web_subdomains', 'domain = :d', ['d' => $domain]);
Config::moduleDb()->delete('pmwh3_countings', 'cid = :c', ['c' => $cid]);
LazyConfig::set('CREATE_SUBDOMAIN', 'Y');

echo "ALL G7 TESTS DONE\n";
