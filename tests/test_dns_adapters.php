<?php
declare(strict_types=1);
/**
 * Adapter-Lifecycle-Tests für MyDNS- und PowerDNS-Adapter.
 * Runs against the TEST_DB_* databases (mydns_test / pdns_test on the dev
 * mysql80); DNS_TYPE kommt aus pmwh3_test.pmwh3_configuration (Seed
 * via test_schema.php <adapter>).
 *
 * phases: zone lifecycle -> exists, records CRUD, serial bump, delete.
 */

require_once __DIR__ . '/../../../library/ckvsoft/autoload.php';
$root = dirname(__DIR__, 3); // Deploy-/Repo-Root (kein CWD-Gefallen)
new ckvsoft\Autoload([
    $root . '/library/',
    $root . '/modules/',
    $root . '/modules/pmwh3/',
]);
require_once __DIR__ . '/../modulautoload.php';
require_once __DIR__ . '/../../../modules/pmwh3/utils/db/abstractdbadapter.php';
require_once __DIR__ . '/../../../modules/pmwh3/utils/db/mysqldbadapter.php';

use pmwh3\Utils\DnsManager;

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

$adapter = $argv[1] ?? 'mydns';
echo "=== Lifecycle: ", $adapter, " ===\n";

t('DNS_TYPE resolved', DnsManager::getAdapterClass() !== null, [
    'class' => DnsManager::getAdapterClass(),
]);
t('enabled', DnsManager::isEnabled());
foreach (['zone-write', 'record-write', 'record-manage'] as $c) {
    t('supports ' . $c, DnsManager::supports($c));
}

$zoneTest = 'dns1-test.pmwh3tetstl.at';
$cls = DnsManager::getAdapterClass();

// 0) Connect sanity via moduleDb dotted path
try {
    \ckvsoft\mvc\Config::moduleDb(null, 'dns.database');
    t('moduleDb(dns.database) connects', true);
} catch (\Throwable $e) {
    t('moduleDb(dns.database) connects', false, ['err' => $e->getMessage()]);
}

// 0) idempotent start (vorheriger Lauf kann abgebrochen haben)
DnsManager::deleteZone($zoneTest);

// 1) Zone lifecycle
t('zoneExists(pre) false', !DnsManager::zoneExists($zoneTest));
$zid = DnsManager::createZone($zoneTest, '1.2.3.4', 'hostmaster@dns1-test.pmwh3tetst.at', ['ttl' => 3600]);
t('createZone>0', $zid > 0, ['id' => $zid]);
t('zoneExists(post) true', DnsManager::zoneExists($zoneTest));

$recs = DnsManager::listRecords($zoneTest);
// PowerDNS führt SOA als Record-Wrow in records (6 rows); MyDNS hält
// SOA in mydns_soa (5 rows). Beides korrekt — Pattern-Prüfung:
$types = array_count_values(array_column($recs, 'type'));
$expectCount = ($adapter === 'pdns') ? 6 : 5;
t('baseline record count', count($recs) === $expectCount, ['expected' => $expectCount, 'got' => count($recs)]);
t('zone default records', ($types['NS'] ?? 0) === 2 && ($types['A'] ?? 0) === 1
        && ($types['CNAME'] ?? 0) === 1 && ($types['MX'] ?? 0) === 1
        && ($types['SOA'] ?? 0) === ($adapter === 'pdns' ? 1 : 0), ['types' => $types]);

$soa = DnsManager::getSoa($zoneTest);
t('getSoa fields', $soa !== null && rtrim((string) $soa['primary'], '.') === 'ns1.' . $zoneTest, ['primary' => $soa['primary'] ?? null]);
$serialBefore = (int) $soa['serial'];

// 2) Record CRUD
$rid = DnsManager::addRecord($zoneTest, 'sub', 'A', '8.8.8.8', 300);
t('addRecord>0', $rid > 0, ['id' => $rid]);
$recs = DnsManager::listRecords($zoneTest, 'A');
t('added record visible', count($recs) === 2, ['A count' => count($recs)]);

t('updateRecord', DnsManager::updateRecord($rid, ['content' => '8.8.4.4']));
$recs = DnsManager::listRecords($zoneTest, 'A');
$content = '';
foreach ($recs as $r) {
    if ((string) $r['name'] === 'sub' || (string) $r['name'] === 'sub.' . $zoneTest) {
        $content = (string) $r['content'];
    }
}
t('updated content visible', $content === '8.8.4.4', ['content' => $content]);

t('deleteRecord', DnsManager::deleteRecord($rid));

$serialAfter = (int) (DnsManager::getSoa($zoneTest)['serial'] ?? 0);
t('serial bumped', $serialAfter > $serialBefore, ['before' => $serialBefore, 'after' => $serialAfter]);

// 3) lookupRecord (unified content shape) + resolver loop over CNAME
$addr = $cls::lookupRecord($zoneTest, 'www');
t('lookupRecord www (CNAME)', $addr !== null && strtolower((string) $addr['type']) === 'cname'
        && rtrim((string) ($addr['content'] ?? $addr['data'] ?? ''), '.') === rtrim($zoneTest, '.'),
        $addr ?? []);
$ip = DnsManager::getIpByDnsRecordLookup($zoneTest, 'www');
t('resolver resolves www -> A', $ip === '1.2.3.4', ['ip' => $ip]);

// 4) teardown
t('deleteZone', DnsManager::deleteZone($zoneTest));
t('zoneExists(after) false', !DnsManager::zoneExists($zoneTest));

echo "=== ALL PASS (", $adapter, ") ===\n";
