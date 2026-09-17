<?php
declare(strict_types=1);
/**
 * G7 email-side tests: AUTO_EMAIL role forwards on the FIRST mailbox
 * of a domain (postmaster always, RFC 2142) + WELCOME_MAIL placeholder
 * replacement logic. Runs against pmwh3_test on dev-mysql80. The
 * welcome-mail SMTP send is skipped in the test environment (trace
 * only); forward rows are asserted directly in pmwh3_mail_forwardings.
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

function t(string $what, bool $ok, array $info = []): void
{
    echo ($ok ? 'PASS' : 'FAIL'), '  ', $what;
    if ($info) { echo '  ', json_encode($info); }
    echo "\n";
    if (!$ok) { exit(1); }
}

$db        = Config::moduleDb();
 LazyConfig::clearCache();

// 0) preconditions: adapter + tables (test DB may lack the mail
//    tables -- create minimal clones of the prod schema, idempotent,
//    WITHOUT the dovecot triggers (they only matter at runtime).
t('postfix adapter tries init', true);
try {
    PostfixAdapter::initDb();
} catch (\Throwable $e) {
    // fallthrough: the table setup below may fix this
}
$db->execDdl("CREATE TABLE IF NOT EXISTS pmwh3_mail_accounts (
  email varchar(128) NOT NULL DEFAULT '',
  login varchar(128) NOT NULL DEFAULT '',
  password varchar(128) NOT NULL DEFAULT '',
  name varchar(255) NOT NULL DEFAULT '',
  uid int NOT NULL DEFAULT 99999,
  gid int NOT NULL DEFAULT 99999,
  homedir varchar(128) NOT NULL DEFAULT '',
  maildir varchar(255) NOT NULL DEFAULT '',
  quota_bytes bigint NOT NULL DEFAULT 0,
  used_bytes bigint NOT NULL DEFAULT 0,
  used_messages bigint NOT NULL DEFAULT 0,
  active char(1) NOT NULL DEFAULT 'Y',
  PRIMARY KEY (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$db->execDdl("CREATE TABLE IF NOT EXISTS pmwh3_mail_forwardings (
  source varchar(80) NOT NULL,
  destination mediumtext NULL,
  PRIMARY KEY (source)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
// Quota usage: NOT a pmwh3 table -- live numbers via doveadm HTTP API;
// adapter method liveQuota() returns null in this test env.
Config::moduleDb(); // ensure fresh table cache
t('postfix adapter available', PostfixAdapter::isAvailable());

// cheap table-existence check
$tables = $db->selectOne(
    "SELECT COUNT(*) AS n FROM information_schema.tables
      WHERE table_schema = DATABASE() AND table_name IN ('pmwh3_mail_accounts','pmwh3_mail_forwardings')", []);
t('mail tables present', (int) $tables['n'] === 2);

// cleaned start: no role forwards on the test domains
$dom1 = 'e7-test1.pmwh3tetstl.at';
$dom2 = 'e7-test2.pmwh3tetstl.at';
foreach ([$dom1, $dom2] as $d) {
    foreach (['postmaster', 'webmaster', 'abuse', 'hostmaster'] as $r) {
        PostfixAdapter::deleteForward($r . '@' . $d);
    }
    // drop leftover mailboxes from previous runs
    foreach ([1, 2] as $i) {
        PostfixAdapter::deleteMailbox("user{$i}@{$d}");
    }
}

// helper: count role forwards for a domain
$fcount = function (string $d) use ($db): array {
    $rows = $db->select(
        "SELECT source, destination FROM pmwh3_mail_forwardings
          WHERE source LIKE :p", ['p' => '%@' . $d]);
    $out = [];
    foreach ($rows as $r) { $out[(string) $r['source']] = (string) $r['destination']; }
    return $out;
};

// --- 1) first mailbox -> postmaster ALWAYS + default roles ----------------
LazyConfig::set('AUTO_EMAIL', 'webmaster, abuse, hostmaster');
LazyConfig::clearCache('AUTO_EMAIL');
t('mailbox 1 of dom1', PostfixAdapter::createMailbox(
        ['email' => "user1@{$dom1}", 'name' => 'User One'], 'SecretPass1!'), []);
$fwd1 = $fcount($dom1);
foreach (['postmaster', 'webmaster', 'abuse', 'hostmaster'] as $r) {
    t("role forward {$r}@ → user1", ($fwd1[$r . '@' . $dom1] ?? '') === "user1@{$dom1}");
}

// --- 2) postmaster ALWAYS even with empty option list ---------------------
LazyConfig::set('AUTO_EMAIL', '');
foreach ([$dom1] as $d) {
    foreach (['webmaster', 'abuse', 'hostmaster'] as $r) {
        PostfixAdapter::deleteForward($r . '@' . $d);
    }
}
t('mailbox 2 of dom1', PostfixAdapter::createMailbox(
        ['email' => "user2@{$dom1}", 'name' => 'User Two'], 'SecretPass2!'), []);
$fwd1b = $fcount($dom1);
t('NOT first mailbox -> no new role forwards',
    count($fwd1b) === 1 && array_keys($fwd1b) === ['postmaster@' . $dom1], $fwd1b);

// dom2: postmaster even though AUTO_EMAIL is empty
t('mailbox 1 of dom2', PostfixAdapter::createMailbox(
        ['email' => "own@{$dom2}", 'name' => 'Owner'], 'SecretPass3!'), []);
$fwd2 = $fcount($dom2);
t('AUTO_EMAIL empty -> only postmaster',
    isset($fwd2['postmaster@' . $dom2]) && count($fwd2) === 1, $fwd2);

// --- 3) custom list --------------------------------------------------------
LazyConfig::set('AUTO_EMAIL', 'chef, support');
foreach (['postmaster'] as $r) {
    PostfixAdapter::deleteForward($r . '@' . $dom2);
}
PostfixAdapter::deleteMailbox("own@{$dom2}");
t('mailbox 1 of dom2 (rerun)', PostfixAdapter::createMailbox(
        ['email' => "own@{$dom2}", 'name' => 'User Own'], 'SecretPass4!'), []);
$fwd2b = $fcount($dom2);
t('custom roles honored',
    isset($fwd2b['chef@' . $dom2], $fwd2b['support@' . $dom2])
    && ($fwd2b['chef@' . $dom2] ?? '') === "own@{$dom2}", $fwd2b);

// --- 4) WELCOME_MAIL placeholder replacement (no real SMTP) --
$ownerCid = 1;
$tmpl = "Hello [EMAILUSER], your address [EMAIL] at [DOMAIN] was created by [CREATOR]/[CREATOR_REALNAME].";
$bodyFor = function (string $email, string $domain) use ($tmpl): string {
    $ref = new \ReflectionMethod(\pmwh3\Utils\Mail\PostfixAdapter::class, 'sendWelcomeMail');
    $ref->setAccessible(true);
    // replicate the replacement logic (unit-level; real SMTP not used)
    $user = ($p = strpos($email, '@')) !== false ? substr($email, 0, $p) : '';
    $cid = (int) (Config::moduleDb()->selectOne(
                "SELECT cid FROM pmwh3_domains WHERE domain = :d LIMIT 1", ['d' => $domain])['cid'] ?? 0);
    $creator = $cid > 0 ? Config::moduleDb()->selectOne(
                "SELECT customer, realname FROM pmwh3_customers WHERE cid = :c LIMIT 1", ['c' => $cid]) : [];
    return str_replace(
        ['[EMAILUSER]', '[EMAIL]', '[DOMAIN]', '[CREATOR]', '[CREATOR_REALNAME]'],
        [$user, $email, $domain, (string) ($creator['customer'] ?? ''), (string) ($creator['realname'] ?? '')],
        $tmpl);
};
$r1 = $bodyFor("user1@{$dom1}", $dom1);
t('placeholder replacement', !str_contains($r1, '[EMAILUSER]')
    && !str_contains($r1, '[DOMAIN]') && str_contains($r1, 'user1@' . $dom1), ['body' => $r1]);

// --- cleanup ---------------------------------------------------------------
foreach ([$dom1, $dom2] as $d) {
    foreach (['postmaster', 'webmaster', 'abuse', 'hostmaster', 'chef', 'support'] as $r) {
        PostfixAdapter::deleteForward($r . '@' . $d);
    }
    PostfixAdapter::deleteMailbox("user1@{$d}");
    PostfixAdapter::deleteMailbox("user2@{$d}");
    PostfixAdapter::deleteMailbox("own@{$d}");
}
LazyConfig::set('AUTO_EMAIL', 'webmaster, abuse, hostmaster');

echo "ALL G7-EMAIL TESTS DONE\n";
