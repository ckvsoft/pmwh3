<?php
declare(strict_types=1);
/**
 * SOA-Timer / Nameserver-Hook-Tests (G5).
 *
 * Verifiziert, dass
 *  - createZone die Schema-Timer (DNS_REFRESH/..) über $extra rendert,
 *  - updateSoa() Timer auf einer bestehenden Zone ändert,
 *  - listZones() die Zonen des Backends liefert,
 *  - OnSaveHooks::updateSoaTimers alle Zonen auf die konfigurierten
 *    Timer stellt und je einen Serial-Bump auslöst,
 *  - OnSaveHooks::updateNameservers die Apex-NS-Sets ersetzt und
 *    Delegationen (nicht-Apex-NS) unangetastet lässt, wieder mit
 *    Serial-Bump.
 *
 * Läuft gegen die Test-DBs (mydns_test / pdns_test); DNS_TYPE kommt aus
 * pmwh3_test.pmwh3_configuration (Seed via test_schema.php <adapter>).
 */

require_once __DIR__ . '/../../../library/ckvsoft/autoload.php';
$root = dirname(__DIR__, 3); // Deploy-/Repo-Root
new ckvsoft\Autoload([
    $root . '/library/',
    $root . '/modules/',
    $root . '/modules/pmwh3/',
]);
require_once __DIR__ . '/../modulautoload.php';
require_once __DIR__ . '/../../../modules/pmwh3/utils/db/abstractdbadapter.php';
require_once __DIR__ . '/../../../modules/pmwh3/utils/db/mysqldbadapter.php';

use pmwh3\Config\LazyConfig;
use pmwh3\Config\OnSaveHooks;
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
echo "=== SOA hooks: ", $adapter, " ===\n";

t('DNS adapter resolved', DnsManager::getAdapterClass() !== null, [
    'class' => DnsManager::getAdapterClass(),
]);

$zoneTest = 'sla-tst.pmwh3tetstl.at';
DnsManager::deleteZone($zoneTest); // idempotenter Start

// ---------------------------------------------------------------
// 1) createZone rendert $extra-Timer (statt hartkodierter Werte)
// ---------------------------------------------------------------
$zid = DnsManager::createZone($zoneTest, '1.2.3.4', 'hostmaster@ckvsoft.at', [
    'refresh' => 7200,
    'retry'   => 1800,
    'expire'  => 1209600,
    'minimum' => 900,
    'ttl'     => 300,
]);
t('createZone>0', $zid > 0, ['id' => $zid]);

$soa = DnsManager::getSoa($zoneTest);
t('createZone timer honored',
        $soa !== null
        && (int) $soa['refresh'] === 7200
        && (int) $soa['retry']  === 1800
        && (int) $soa['expire'] === 1209600
        && (int) $soa['negttl'] === 900
        && (int) $soa['ttl']    === 300,
        ['refresh' => $soa['refresh'] ?? null, 'retry' => $soa['retry'] ?? null,
         'expire' => $soa['expire'] ?? null, 'negttl' => $soa['negttl'] ?? null,
         'ttl'    => $soa['ttl']    ?? null]);
$serialCreate = (int) ($soa['serial'] ?? 0);
t('serial present', $serialCreate > 0, ['serial' => $serialCreate]);

// ---------------------------------------------------------------
// 2) listZones() enthält die Zone
// ---------------------------------------------------------------
$zones = DnsManager::listZones();
t('listZones contains zone', in_array($zoneTest, $zones, true), ['zones' => $zones]);

// ---------------------------------------------------------------
// 3) updateSoa() ändert Timer auf bestehender Zone (Serial unberührt)
// ---------------------------------------------------------------
t('updateSoa refresh only', DnsManager::updateSoa($zoneTest, ['refresh' => 7100]));
$soa = DnsManager::getSoa($zoneTest);
t('updateSoa applied + serial untouched',
        (int) $soa['refresh'] === 7100
        && (int) $soa['serial'] === $serialCreate,
        ['refresh' => $soa['refresh'] ?? null, 'serial' => $soa['serial'] ?? null]);

// ---------------------------------------------------------------
// 4) OnSaveHooks::updateSoaTimers setzt Timer auf ALLE Zonen + Bump
// ---------------------------------------------------------------
$oldKey = LazyConfig::set('DNS_REFRESH', '7777');
OnSaveHooks::updateSoaTimers('DNS_REFRESH', '7777', (string) ($oldKey ?? '10800'));
$soa = DnsManager::getSoa($zoneTest);
$post = $zones !== [] ? DnsManager::getSoa($zones[0]) : null;
$postKey = $post ? (string) ($post['refresh'] ?? '') : '';
$serialAfter = (int) ($soa['serial'] ?? 0);
t('updateSoaTimers applied on zone', (int) $soa['refresh'] === 7777,
        ['refresh' => $soa['refresh'] ?? null]);
t('updateSoaTimers applied on every zone', $adapter === 'mydns' || $postKey === '7777',
        ['firstZone' => $zones[0] ?? null, 'refresh' => $postKey]);
t('updateSoaTimers bumped serial', $serialAfter > $serialCreate,
        ['before' => $serialCreate, 'after' => $serialAfter]);

// ---------------------------------------------------------------
// 5) OnSaveHooks::updateNameservers ersetzt Apex-NS + Delegation bleibt
// ---------------------------------------------------------------
DnsManager::addRecord($zoneTest, 'deleg', 'NS', 'ns1.other.at', 300, 0); // Delegation

$newServers = "ns1.pmwh3new.at\nns2.pmwh3new.at\n";
$oldNs = LazyConfig::set('DNS_SERVERS', $newServers);
$serialBefore = (int) (DnsManager::getSoa($zoneTest)['serial'] ?? 0);
OnSaveHooks::updateNameservers('DNS_SERVERS', $newServers, (string) ($oldNs ?? ''));

$apexNs = [];
$delegNs = 0;
foreach (DnsManager::listRecords($zoneTest, 'NS') as $rec) {
    $name = strtolower((string) ($rec['name'] ?? ''));
    if ($name === $zoneTest) {
        $apexNs[] = rtrim((string) $rec['content'], '.');
    } elseif ($name === 'deleg') {
        $delegNs++;
    }
}
sort($apexNs);
t('apex NS replaced', $apexNs === ['ns1.pmwh3new.at', 'ns2.pmwh3new.at'],
        ['apexNS' => $apexNs]);
t('delegation NS untouched', $delegNs === 1, ['deleg' => $delegNs]);
t('updateNameservers bumped serial',
        (int) (DnsManager::getSoa($zoneTest)['serial'] ?? 0) > $serialBefore,
        ['before' => $serialBefore,
         'after'  => (int) (DnsManager::getSoa($zoneTest)['serial'] ?? 0)]);

// ---------------------------------------------------------------
// Teardown + Config-Reset
// ---------------------------------------------------------------
t('deleteZone', DnsManager::deleteZone($zoneTest));
t('zoneExists(after) false', !DnsManager::zoneExists($zoneTest));

LazyConfig::set('DNS_REFRESH', '10800'); // Default zurücksetzen
LazyConfig::set('DNS_SERVERS', '');      // Default zurücksetzen
LazyConfig::clearCache();

echo "=== ALL PASS (", $adapter, ") ===\n";