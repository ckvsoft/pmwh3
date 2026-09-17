<?php
declare(strict_types=1);
/**
 * Subdomain CRUD via WebManager/ApacheAdapter against pmwh3_test
 * (dev-mysql80). Creates the pmwh2-shaped pmwh3_web_subdomains table in
 * the test DB (idempotent), seeds a parent domain, then runs:
 * create directory -> alias -> ip variant -> rename -> custom edit ->
 * regenerate -> delete, with quota assertions. FS docroot stays in
 * /tmp (WEBROOT config row), DNS automation is skipped here (only the
 * mydns_test DB node is present) -- the DNS wiring itself is verified
 * by the adapter lifecycle tests against the real pdns/mydns stacks.
 */

require_once __DIR__ . '/../../../library/ckvsoft/autoload.php';
$root = dirname(__DIR__, 3);
new ckvsoft\Autoload([$root . '/library/', $root . '/modules/', $root . '/modules/pmwh3/', __DIR__ . '/../']);
require_once __DIR__ . '/../modulautoload.php';

use ckvsoft\mvc\Config;
use pmwh3\Config\LazyConfig;
use pmwh3\Utils\DomainManager;
use pmwh3\Utils\SubdomainManager;
use pmwh3\Utils\WebManager;

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

// --- baseline schema (idempotent, via framework executeSqlFile) -------
try {
    $db->executeSqlFile(dirname(__DIR__) . '/inc/sql/0.0.0_baseline.sql');
} catch (\Throwable $e) {
    t('baseline apply', false, ['err' => $e->getMessage()]);
}
t('baseline applied', true);

// --- pmwh3_web_subdomains (pmwh2 shape; fpext/traffic unused by pmwh3) ---
// Fresh per run so the legacy fpext column is present and the
// adapter's one-time cleanup gets exercised every time.
$db->execDdl("DROP TABLE IF EXISTS pmwh3_web_subdomains");
$db->execDdl("CREATE TABLE pmwh3_web_subdomains (
    subdomain  VARCHAR(255) NOT NULL,
    domain     VARCHAR(255) NOT NULL,
    customer   VARCHAR(100) NOT NULL,
    path       VARCHAR(255) NOT NULL DEFAULT '',
    data       MEDIUMTEXT   DEFAULT NULL,
    fpext      INT(1)       NOT NULL DEFAULT 0,
    traffic    INT(20)      NOT NULL DEFAULT 0,
    alias_of   VARCHAR(128) DEFAULT NULL,
    -- consolidated shape (3.0.82); legacy fpext column below keeps
    -- the adapter's one-time fpext cleanup exercised
    mode       VARCHAR(16)  NOT NULL DEFAULT 'directory',
    ip         VARCHAR(45)  DEFAULT NULL,
    ssl_cert   VARCHAR(255) DEFAULT NULL,
    custom     TEXT         DEFAULT NULL,
    adapter    VARCHAR(32)  NOT NULL DEFAULT 'apache',
    PRIMARY KEY (subdomain),
    KEY alias_of (alias_of)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// --- sandbox webroot (keep FS best-effort calls out of /vhome) -------
$webTmp = sys_get_temp_dir() . '/pmwh3-sdmtest-' . getmypid();
$db->insertUpdate('pmwh3_configuration', [
    'configuration_key'   => 'WEBROOT',
    'configuration_value' => $webTmp,
]);
// Index-file behaviour: name from CREATE_INDEXFILE, content from
// CONTENT_INDEXFILE (empty => built-in "under construction" fallback).
$db->insertUpdate('pmwh3_configuration', [
    'configuration_key'   => 'CREATE_INDEXFILE',
    'configuration_value' => 'index.html',
]);
$db->insertUpdate('pmwh3_configuration', [
    'configuration_key'   => 'CONTENT_INDEXFILE',
    'configuration_value' => '<h1>welcome</h1>\nplaceholder',
]);

$cid = 777001;
$domainName = 'sdmtest-' . substr(md5((string) time()), 0, 6) . '.example.org';

// --- cleanup any earlier run ------------------------------------------
$db->delete('pmwh3_web_subdomains', 'subdomain LIKE :p', ['p' => 'sdmtest-%']);
$db->delete('pmwh3_domains', 'cid = :c', ['c' => $cid]);
$db->delete('pmwh3_countings', 'cid = :c', ['c' => $cid]);

// --- seed -------------------------------------------------------------
$db->insert('pmwh3_domains', [
    'domain'   => $domainName,
    'customer' => 'sdmtst',
    'cid'      => $cid,
    'ip'       => '203.0.113.10',
    'services' => 'web,mail,dns',
]);
t('parent domain seeded', DomainManager::getByName($domainName) !== null);

foreach (['max' => 2, 'used' => 0, 'granted' => 0] as $type => $v) {
    $db->insert('pmwh3_countings', ['cid' => $cid, 'type' => $type, 'subdomains' => $v]);
}
$q = SubdomainManager::quota($cid);
t('quota scaffold', $q['max'] === 2 && $q['used'] === 0, $q);

// --- web adapter resolved ---------------------------------------------
t('WEB_TYPE resolves', WebManager::getAdapterClass() !== null,
    ['class' => WebManager::getAdapterClass() ?? '']);
t('web enabled', WebManager::isEnabled());
t('web name', str_contains(strtolower(WebManager::getName()), 'apache'),
    ['name' => WebManager::getName()]);
t('subdomain-write supported', WebManager::supports('subdomain-write'));

// --- DNS wiring (only when a DNS adapter is really available) ----------
$dnsOn = \pmwh3\Utils\DnsManager::supports('record-write');
if ($dnsOn) {
    \pmwh3\Utils\DnsManager::createZone($domainName, '203.0.113.10',
            'hostmaster@' . $domainName);
    t('zone for parent seeded', \pmwh3\Utils\DnsManager::zoneExists($domainName));
}

/** find records of the parent zone by (name,type) */
function sdRec(string $zone, string $name, string $type): ?array
{
    foreach (\pmwh3\Utils\DnsManager::listRecords($zone, $type) as $rec) {
        if (strtolower((string) $rec['name']) === strtolower($name)) {
            return $rec;
        }
    }
    return null;
}

// fpext (FrontPage relic) must have been dropped by adapter init
$col = $db->selectOne(
        "SELECT 1 FROM information_schema.columns
          WHERE table_schema = DATABASE()
            AND table_name = 'pmwh3_web_subdomains'
            AND column_name = 'fpext'"
);
t('fpext legacy column dropped', empty($col));

// --- create directory variant -----------------------------------------
$r = SubdomainManager::create([
    'sub' => 'svc', 'domain' => $domainName, 'mode' => 'directory',
    'value' => 'svc', 'cid' => $cid,
]);
t('create directory', $r['ok'] ?? false, $r);
$row = WebManager::getRow('svc.' . $domainName);
t('row persisted', $row !== null);
t('docroot under test webroot',
    str_starts_with((string) ($row['path'] ?? ''), $webTmp . '/sdmtst/' . $domainName . '/svc/'),
    ['path' => $row['path'] ?? '', 'webTmp' => $webTmp]);
t('vhost skeleton rendered', str_contains((string) $row['data'], '<VirtualHost *:80>')
    && str_contains((string) $row['data'], 'ServerName svc.' . $domainName)
    && str_contains((string) $row['data'], '### START CUSTOM ###'));
t('docroot directory created (best effort)',
    is_dir($webTmp . '/sdmtst/' . $domainName . '/svc'));
$idxFile = $webTmp . '/sdmtst/' . $domainName . '/svc/index.html';
t('index file dropped into new docroot', is_file($idxFile));
$row = WebManager::getRow('svc.' . $domainName);
t('vhost declares DirectoryIndex',
    str_contains((string) ($row['data'] ?? ''), 'DirectoryIndex index.html'),
    ['data' => substr((string) ($row['data'] ?? ''), 0, 400)]);
$idxContent = (string) @file_get_contents($idxFile);
t('index content uses CONTENT_INDEXFILE',
    str_contains($idxContent, 'welcome'), ['len' => strlen($idxContent)]);
@file_put_contents($webTmp . '/sdmtst/' . $domainName . '/svc/probe.txt',
        str_repeat('x', 1024));
$dirSz = \pmwh3\Utils\FsManager::dirSize(
        $webTmp . '/sdmtst/' . $domainName . '/svc');
t('fs dirSize measurable', is_int($dirSz) && $dirSz >= 1024, ['bytes' => $dirSz]);
@unlink($webTmp . '/sdmtst/' . $domainName . '/svc/probe.txt');
$q2 = SubdomainManager::quota($cid);
t('quota incremented', $q2['used'] === 1, $q2);
if ($dnsOn) {
    $rec = sdRec($domainName, 'svc.' . $domainName, 'A');
    t('dns: A record for directory subdomain',
        $rec !== null && ($rec['content'] ?? '') === '203.0.113.10',
        $rec ?? []);
}

// --- custom section roundtrip ------------------------------------------
$custom = 'php_admin_value engine Off';
$r = SubdomainManager::update('svc.' . $domainName, ['custom' => $custom]);
t('custom replace ok', $r['ok'], $r);
$row = WebManager::getRow('svc.' . $domainName);
t('custom section in data', str_contains((string) $row['data'], $custom)
    && str_contains((string) $row['data'], '### START CUSTOM ###'));

// --- alias of the directory subdomain -----------------------------------
$r = SubdomainManager::create([
    'sub' => 'al', 'domain' => $domainName, 'mode' => 'alias',
    'value' => 'svc.' . $domainName, 'cid' => $cid,
]);
t('create alias', $r['ok'] ?? false, $r);
$alRow = WebManager::getRow('al.' . $domainName);
t('alias_of + shared path', ($alRow['alias_of'] ?? '') === 'svc.' . $domainName
    && ($alRow['path'] ?? '') === ($row['path'] ?? ''));
if ($dnsOn) {
    $rec = sdRec($domainName, 'al.' . $domainName, 'CNAME');
    t('dns: CNAME record for alias',
        $rec !== null && str_contains(strtolower((string) ($rec['content'] ?? '')), 'svc.' . $domainName),
        $rec ?? []);
}

// --- ip variant ---------------------------------------------------------
$r = SubdomainManager::create([
    'sub' => 'fw', 'domain' => $domainName, 'mode' => 'ip',
    'value' => '198.51.100.7', 'cid' => $cid,
]);
t('create ip variant', $r['ok'] ?? false, $r);
$ipRow = WebManager::getRow('fw.' . $domainName);
t('ip row: path=ip, data NULL', ($ipRow['path'] ?? '') === '198.51.100.7'
    && array_key_exists('data', $ipRow) && $ipRow['data'] === null, $ipRow ?? []);
if ($dnsOn) {
    $rec = sdRec($domainName, 'fw.' . $domainName, 'A');
    t('dns: A record for ip variant',
        $rec !== null && ($rec['content'] ?? '') === '198.51.100.7', $rec ?? []);
}

// --- limit + duplicate -------------------------------------------------
$r = SubdomainManager::create([
    'sub' => 'x4', 'domain' => $domainName, 'mode' => 'directory',
    'value' => 'x4', 'cid' => $cid,
]);
t('quota exhausted rejected', !$r['ok'] && str_contains((string) $r['error'], 'quota'), $r);
$r = SubdomainManager::create([
    'sub' => 'svc', 'domain' => $domainName, 'mode' => 'directory',
    'value' => 'svc', 'cid' => $cid,
]);
t('duplicate rejected', !$r['ok'], $r);

// --- rename (follows alias, moves docroot, keeps custom section) --------
$oldDir = $webTmp . '/sdmtst/' . $domainName . '/svc';
$svcCustom = 'php_admin_value engine Off';
$r = SubdomainManager::update('svc.' . $domainName, ['sub' => 'svc2']);
t('rename ok', $r['ok'], $r);
t('old name gone', WebManager::getRow('svc.' . $domainName) === null);
$rowNew = WebManager::getRow('svc2.' . $domainName);
t('row renamed', $rowNew !== null);
t('alias followed rename', (WebManager::getRow('al.' . $domainName)['alias_of'] ?? '') === 'svc2.' . $domainName);
t('docroot renamed', str_ends_with((string) ($rowNew['path'] ?? ''), '/svc2/'),
    ['path' => $rowNew['path'] ?? '']);
t('docroot moved on FS', is_dir($webTmp . '/sdmtst/' . $domainName . '/svc2'));
t('custom preserved across rename',
    str_contains((string) ($rowNew['data'] ?? ''), $svcCustom));

// --- regenerate keeps custom --------------------------------------------
WebManager::regenerateVhostData('svc2.' . $domainName);
$rowNew = WebManager::getRow('svc2.' . $domainName);
t('regenerate keeps custom', str_contains((string) ($rowNew['data'] ?? ''), $svcCustom)
    && str_contains((string) ($rowNew['data'] ?? ''), 'ServerName svc2.' . $domainName));

// --- markerless (hand-maintained) data must not be mangled ---------------
$handmade = 'AllowEncodedSlashes NoDecode';
$db->update('pmwh3_web_subdomains', ['data' => $handmade],
        'subdomain = :s', ['s' => 'fw.' . $domainName]);
SubdomainManager::update('fw.' . $domainName, ['custom' => 'x'], $cid);
$row = WebManager::getRow('fw.' . $domainName);
t('markerless data untouched', ($row['data'] ?? '') === $handmade);

// --- delete cascades alias -----------------------------------------------
$r = SubdomainManager::delete('svc2.' . $domainName, $cid);
t('delete ok', $r['ok'], $r);
t('row deleted', WebManager::getRow('svc2.' . $domainName) === null);
t('alias cascaded', WebManager::getRow('al.' . $domainName) === null);
if ($dnsOn) {
    t('dns: records of renamed sub + alias removed',
        sdRec($domainName, 'svc2.' . $domainName, 'A') === null
        && sdRec($domainName, 'al.' . $domainName, 'CNAME') === null
        && sdRec($domainName, 'svc.' . $domainName, 'A') === null);
}
$q3 = SubdomainManager::quota($cid);
t('quota decremented (ip row left)', $q3['used'] === 1, $q3);

// --- delete ip variant ----------------------------------------------------
$r = SubdomainManager::delete('fw.' . $domainName, $cid);
t('delete ip variant', $r['ok'], $r);
$q4 = SubdomainManager::quota($cid);
t('quota back to zero', $q4['used'] === 0, $q4);
if ($dnsOn) {
    t('dns: A record of ip variant removed',
        sdRec($domainName, 'fw.' . $domainName, 'A') === null);
    \pmwh3\Utils\DnsManager::deleteZone($domainName);
    t('zone cleaned up', !\pmwh3\Utils\DnsManager::zoneExists($domainName));
}

// --- FS side effects -------------------------------------------------------
t('svc dir gone after rename', !is_dir($webTmp . '/sdmtst/' . $domainName . '/svc'));
t('svc2 dir removed after delete', !is_dir($webTmp . '/sdmtst/' . $domainName . '/svc2'));

// --- onsavehooks: APACHECONFIG change regenerates default rows only ------
$dnReg = 'reg-h' . substr(md5((string) mt_rand()), 0, 4) . '.' . $domainName;
$dnHand = 'hand-h' . substr(md5((string) mt_rand()), 0, 4) . '.' . $domainName;
SubdomainManager::create(['sub' => explode('.', $dnReg)[0], 'domain' => $domainName,
    'mode' => 'directory', 'value' => explode('.', $dnReg)[0], 'cid' => $cid]);
SubdomainManager::create(['sub' => explode('.', $dnHand)[0], 'domain' => $domainName,
    'mode' => 'directory', 'value' => explode('.', $dnHand)[0], 'cid' => $cid]);
SubdomainManager::update($dnHand, ['custom' => 'php_admin_value hand Edited = On']);

// production flow: the value is saved first, then the hook runs
$db->insertUpdate('pmwh3_configuration', [
    'configuration_key'   => 'APACHECONFIG',
    'configuration_value' => 'deny all  # new snippet',
]);
LazyConfig::clearCache();
\pmwh3\Config\OnSaveHooks::updateApacheconfig(
        'APACHECONFIG', 'deny all  # new snippet', '');

$row = WebManager::getRow($dnReg);
t('hook: default-snippet row regenerated',
    str_contains((string) ($row['data'] ?? ''), 'deny all')
    && str_contains((string) ($row['data'] ?? ''), 'ServerName ' . $dnReg));
$row = WebManager::getRow($dnHand);
t('hook: hand-edited custom untouched',
    str_contains((string) ($row['data'] ?? ''), 'php_admin_value hand Edited')
    && !str_contains((string) ($row['data'] ?? ''), 'deny all'));

// --- ssl: cert dir + resolver + vhost rendering ------------------------
$certDir = sys_get_temp_dir() . '/pmwh3-certs-' . getmypid();
@mkdir($certDir, 0775, true);
@unlink($certDir . '/svc.pem');
@file_put_contents($certDir . '/exact.' . $domainName . '.pem', 'cert');
@file_put_contents($certDir . '/exact.' . $domainName . '.key', 'key');
@file_put_contents($certDir . '/_.' . $domainName . '.pem', 'cert');
@file_put_contents($certDir . '/_.' . $domainName . '.key', 'key');
@file_put_contents($certDir . '/invalid-no-key.pem', 'orphan');
$db->insertUpdate('pmwh3_configuration', [
    'configuration_key'   => 'WEB_SSL_DIR',
    'configuration_value' => $certDir,
]);
LazyConfig::clearCache();

// ssl section needs quota headroom: make it unlimited from here on
$db->update('pmwh3_countings', ['subdomains' => -1], 'cid = :c AND type = :t',
        ['c' => $cid, 't' => 'max']);

$certs = WebManager::listCerts();
t('ssl: listCerts (pair-wise)', isset($certs['exact.' . $domainName])
    && isset($certs['_.' . $domainName]) && !isset($certs['invalid-no-key']),
    array_keys($certs));
t('ssl: resolve matches exact',
    WebManager::resolveCert('exact.' . $domainName) === 'exact.' . $domainName);
t('ssl: resolve wildcard one label',
    WebManager::resolveCert('anything.' . $domainName) === '_.' . $domainName);
t('ssl: resolve does not cross two labels',
    WebManager::resolveCert('a.b.' . $domainName) === null);

// vhost with certificate: :443 block (SSLEngine + cert paths) first,
// then the plain :80 block -- same `data`, both served by mod_perl.
$r = SubdomainManager::create([
    'sub' => 'tls', 'domain' => $domainName, 'mode' => 'directory',
    'value' => 'tls', 'cid' => $cid, 'ssl_cert' => 'exact.' . $domainName,
]);
t('ssl: create with explicit cert', $r['ok'] ?? false, $r);
$crtRow = WebManager::getRow('tls.' . $domainName);
$crtData = (string) ($crtRow['data'] ?? '');
t('ssl: :443 rendered first with SSLEngine',
    str_contains($crtData, '<VirtualHost *:443>')
    && str_contains($crtData, 'SSLEngine on')
    && strpos($crtData, '<VirtualHost *:443>')
        < strpos($crtData, '<VirtualHost *:80>'));
t('ssl: cert paths from WEB_SSL_DIR',
    str_contains($crtData, $certDir . '/exact.' . $domainName . '.pem')
    && str_contains($crtData, $certDir . '/exact.' . $domainName . '.key'));
t('ssl: plain block kept', str_contains($crtData, "<VirtualHost *:80>\n"));
t('ssl: marker contract kept in both blocks',
    substr_count($crtData, '### START CUSTOM ###') === 2
    && substr_count($crtData, '### END CUSTOM ###') === 2);

// bind/strip via update(): '' removes the TLS block
SubdomainManager::update('tls.' . $domainName, ['ssl_cert' => '']);
$crtRow = WebManager::getRow('tls.' . $domainName);
t('ssl: strip via update', !str_contains((string) ($crtRow['data'] ?? ''), 'SSLEngine'));
// re-bind via auto (wildcard matches here)
SubdomainManager::update('tls.' . $domainName, ['ssl_cert' => 'auto']);
$crtRow = WebManager::getRow('tls.' . $domainName);
t('ssl: rebind via auto', str_contains((string) ($crtRow['data'] ?? ''), 'SSLEngine on'));

// ssl: create with remaining 'auto' resolution (wildcard matches)
$r = SubdomainManager::create([
    'sub' => 'tlsauto', 'domain' => $domainName, 'mode' => 'directory',
    'value' => 'tlsauto', 'cid' => $cid, 'ssl_cert' => 'auto',
]);
t('ssl: create resolved automatically', $r['ok'] ?? false, $r);
$crtData = (string) (WebManager::getRow('tlsauto.' . $domainName)['data'] ?? '');
t('ssl: auto picked the wildcard cert',
    str_contains($crtData, '_.' . $domainName . '.pem'));

// --- cleanup -----------------------------------------------------------
SubdomainManager::delete($dnReg, $cid);
SubdomainManager::delete($dnHand, $cid);
$db->delete('pmwh3_web_subdomains', 'subdomain LIKE :p', ['p' => 'sdmtest-%']);
$db->delete('pmwh3_web_subdomains', 'subdomain LIKE :p', ['p' => '%-h.' . $domainName]);
$db->delete('pmwh3_domains', 'cid = :c', ['c' => $cid]);
$db->delete('pmwh3_countings', 'cid = :c', ['c' => $cid]);
$db->delete('pmwh3_configuration', 'configuration_key IN (:k1, :k2, :k3)',
        ['k1' => 'WEBROOT', 'k2' => 'APACHECONFIG', 'k3' => 'WEB_SSL_DIR']);
@unlink($certDir . '/exact.' . $domainName . '.pem');
@unlink($certDir . '/exact.' . $domainName . '.key');
@unlink($certDir . '/_.' . $domainName . '.pem');
@unlink($certDir . '/_.' . $domainName . '.key');
@unlink($certDir . '/invalid-no-key.pem');
@rmdir($certDir);
@rmdir($webTmp . '/sdmtst/' . $domainName . '/svc');
@rmdir($webTmp . '/sdmtst/' . $domainName);
@rmdir($webTmp . '/sdmtst');
@rmdir($webTmp);

echo "ALL SUBDOMAIN TESTS DONE\n";
