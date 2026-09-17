<?php
declare(strict_types=1);
/**
 * G8 mail transport: pmwh3 owns the postfix routing table
 * (pmwh3_mail_transport). Verifies
 *   - PostfixAdapter::syncDomainTransport() (enabled/disabled,
 *     MAIL_MASTER_IP empty vs set, custom MAIL_TRANSPORT, idempotent)
 *   - MailManager::syncDomainTransport() soft-capability facade
 *   - DomainManager::create/update/delete provisioning
 * Runs against pmwh3_test on dev-mysql80, idempotent.
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
use pmwh3\Utils\MailManager;
use pmwh3\Utils\Mail\PostfixAdapter;
use pmwh3\Utils\DomainManager;

function t(string $what, bool $ok, array $info = []): void
{
    echo ($ok ? 'PASS' : 'FAIL'), '  ', $what;
    if ($info) { echo '  ', json_encode($info); }
    echo "\n";
    if (!$ok) { exit(1); }
}

$db = Config::moduleDb();
LazyConfig::clearCache();

try { PostfixAdapter::initDb(); } catch (\Throwable $e) { /* table setup below */ }

// idempotent minimal clones for the test DB (baseline normally has them)
$db->execDdl("CREATE TABLE IF NOT EXISTS pmwh3_mail_transport (
  domain varchar(128) NOT NULL DEFAULT '',
  destination varchar(128) NOT NULL DEFAULT '',
  master_destination varchar(128) NOT NULL DEFAULT '',
  PRIMARY KEY (domain)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$db->execDdl("CREATE TABLE IF NOT EXISTS pmwh3_customers (
  cid int NOT NULL AUTO_INCREMENT,
  customer varchar(100) NOT NULL DEFAULT '',
  role_id int NOT NULL DEFAULT 0,
  email varchar(254) NOT NULL DEFAULT '',
  realname varchar(100) NOT NULL DEFAULT '',
  customer_number varchar(30) NOT NULL DEFAULT '0',
  password varchar(64) NOT NULL DEFAULT '',
  creator int NOT NULL DEFAULT 0,
  standard_subdomain char(1) NOT NULL DEFAULT 'N',
  PRIMARY KEY (cid), UNIQUE KEY customers_name (customer)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
Config::moduleDb(); // refresh table cache

t('postfix adapter available', PostfixAdapter::isAvailable());

// scaffold: one customer for the DomainManager integration
$custName = 'g8transport';
$cust = $db->selectOne("SELECT cid FROM pmwh3_customers WHERE customer = :n", ['n' => $custName]);
if (!$cust) {
    $db->insert('pmwh3_customers', [
        'customer' => $custName, 'realname' => 'G8 Transport',
        'email' => 'g8@example.test', 'standard_subdomain' => 'N',
    ]);
    $cust = $db->selectOne("SELECT cid FROM pmwh3_customers WHERE customer = :n", ['n' => $custName]);
}
$cid = (int) ($cust['cid'] ?? 0);
t('test customer present', $cid > 0, ['cid' => $cid]);

$dom  = 'g8-transport.pmwh3tetstl.at';
$dom2 = 'g8-dm.pmwh3tetstl.at';
$rowFor = function (string $d) use ($db): ?array {
    return $db->selectOne("SELECT * FROM pmwh3_mail_transport WHERE domain = :d", ['d' => $d]);
};

// cleaned start
$db->delete('pmwh3_mail_transport', 'domain = :d', ['d' => $dom]);
$db->delete('pmwh3_mail_transport', 'domain = :d', ['d' => $dom2]);
$db->delete('pmwh3_domains', 'domain = :d', ['d' => $dom]);
$db->delete('pmwh3_domains', 'domain = :d', ['d' => $dom2]);

// --- 1) adapter-level sync ------------------------------------------------
LazyConfig::set('MAIL_MASTER_IP', '');
LazyConfig::set('MAIL_TRANSPORT', 'lmtp:inet:dovecot:24');
LazyConfig::clearCache('MAIL_MASTER_IP');
LazyConfig::clearCache('MAIL_TRANSPORT');

PostfixAdapter::syncDomainTransport($dom, false);
t('disabled on fresh domain -> no row', $rowFor($dom) === null);

PostfixAdapter::syncDomainTransport($dom, true);
t('enabled + empty MAIL_MASTER_IP -> no row (single server)', $rowFor($dom) === null);

LazyConfig::set('MAIL_MASTER_IP', '203.0.113.10');
LazyConfig::clearCache('MAIL_MASTER_IP');
PostfixAdapter::syncDomainTransport($dom, true);
$r = $rowFor($dom);
t('enabled + MAIL_MASTER_IP -> row provisioned',
    $r !== null
    && $r['destination'] === 'lmtp:inet:dovecot:24'
    && $r['master_destination'] === 'smtp:[203.0.113.10]:25',
    $r ?: []);

LazyConfig::set('MAIL_TRANSPORT', 'lmtp:inet:myrelay:2525');
LazyConfig::clearCache('MAIL_TRANSPORT');
PostfixAdapter::syncDomainTransport($dom, true);
$r2 = $rowFor($dom);
t('custom MAIL_TRANSPORT honored, master_destination kept',
    $r2 !== null
    && $r2['destination'] === 'lmtp:inet:myrelay:2525'
    && $r2['master_destination'] === 'smtp:[203.0.113.10]:25',
    $r2 ?: []);

$n = $db->selectOne("SELECT COUNT(*) AS c FROM pmwh3_mail_transport WHERE domain = :d", ['d' => $dom]);
t('re-run idempotent (exactly 1 row)', (int) $n['c'] === 1);

LazyConfig::set('MAIL_MASTER_IP', '');
LazyConfig::clearCache('MAIL_MASTER_IP');
LazyConfig::set('MAIL_TRANSPORT', 'lmtp:inet:dovecot:24');
LazyConfig::clearCache('MAIL_TRANSPORT');

// --- 2) MailManager facade (soft capability) ------------------------------
LazyConfig::set('MAIL_MASTER_IP', '203.0.113.10');
LazyConfig::clearCache('MAIL_MASTER_IP');
MailManager::syncDomainTransport($dom, true);
t('MailManager::syncDomainTransport enabled -> row', $rowFor($dom) !== null);
MailManager::syncDomainTransport($dom, false);
t('MailManager::syncDomainTransport disabled -> no row', $rowFor($dom) === null);

// --- 3) DomainManager provisioning ---------------------------------------
$ok = DomainManager::create([
    'domain'   => $dom2,
    'cid'      => $cid,
    'services' => 'mail',
]);
t('DomainManager::create(services=mail)', $ok, []);
$domRow = $db->selectOne("SELECT domain FROM pmwh3_domains WHERE domain = :d", ['d' => $dom2]);
t('domain row created', ($domRow['domain'] ?? '') === $dom2);
t('create provisioned transport row', $rowFor($dom2) !== null, $rowFor($dom2) ?: []);

DomainManager::update($dom2, ['services' => 'web']);
t('update services mail->web removes transport row', $rowFor($dom2) === null);

DomainManager::update($dom2, ['services' => 'mail']);
t('update services web->mail provisions transport row', $rowFor($dom2) !== null);

DomainManager::delete($dom2);
$domRow = $db->selectOne("SELECT domain FROM pmwh3_domains WHERE domain = :d", ['d' => $dom2]);
t('delete removes domain row', $domRow === null);
t('delete removes transport row', $rowFor($dom2) === null);

// --- cleanup ---------------------------------------------------------------
$db->delete('pmwh3_mail_transport', 'domain = :d', ['d' => $dom]);
$db->delete('pmwh3_mail_transport', 'domain = :d', ['d' => $dom2]);
$db->delete('pmwh3_domains', 'domain = :d', ['d' => $dom]);
$db->delete('pmwh3_domains', 'domain = :d', ['d' => $dom2]);
$db->delete('pmwh3_customers', 'customer = :n', ['n' => $custName]);
LazyConfig::set('MAIL_MASTER_IP', '');
LazyConfig::clearCache('MAIL_MASTER_IP');
LazyConfig::set('MAIL_TRANSPORT', 'lmtp:inet:dovecot:24');
LazyConfig::clearCache('MAIL_TRANSPORT');

echo "ALL G8-MAIL-TRANSPORT TESTS DONE\n";
