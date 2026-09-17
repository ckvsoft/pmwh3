<?php
declare(strict_types=1);
/**
 * G1 security tests (PASSWORD_LENGTH / RESERVED_NAMES / reCAPTCHA).
 *
 * Runs against pmwh3_test (dev-mysql80). Sets pmwh3_configuration
 * rows via cfg(), verifies PasswordUtil policy rules, reserved-name
 * matching, and the full CaptchaManager matrix against a disposable
 * php -S mock for Google's siteverify endpoint (CAPTCHA_VERIFY_URL
 * override). No prod contact.
 */

require_once __DIR__ . '/../../../library/ckvsoft/autoload.php';
$root = dirname(__DIR__, 3);
new ckvsoft\Autoload([$root . '/library/', $root . '/modules/', $root . '/modules/pmwh3/', __DIR__ . '/../']);
require_once __DIR__ . '/../modulautoload.php';

use pmwh3\Utils\PasswordUtil;
use pmwh3\Utils\CustomerUtil;
use pmwh3\Utils\CaptchaManager;

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

function cfg(string $k, string $v): void
{
    $db = \ckvsoft\mvc\Config::moduleDb();
    $db->insertUpdate('pmwh3_configuration', [
        'configuration_key'   => $k,
        'configuration_value' => $v,
    ]);
    \pmwh3\Config\LazyConfig::clearCache();
}

function cfgDrop(array $keys): void
{
    $db = \ckvsoft\mvc\Config::moduleDb();
    $db->delete('pmwh3_configuration', 'configuration_key IN (:k0,:k1,:k2,:k3,:k4)', [
        'k0' => $keys[0] ?? '', 'k1' => $keys[1] ?? '', 'k2' => $keys[2] ?? '',
        'k3' => $keys[3] ?? '', 'k4' => $keys[4] ?? '',
    ]);
    \pmwh3\Config\LazyConfig::clearCache();
}

if (!function_exists('curl_init')) {
    fwrite(STDERR, "SKIP: curl extension missing in this PHP build\n");
    exit(0);
}

// --- baseline (idempotent, pmwh3_configuration) -------------------
$db = \ckvsoft\mvc\Config::moduleDb();
$db->executeSqlFile(dirname(__DIR__) . '/inc/sql/0.0.0_baseline.sql');

// --- PASSWORD_LENGTH -------------------------------------------------
cfg('PASSWORD_LENGTH', '12');
t('minLength default 12', PasswordUtil::minLength() === 12);
t('defaultLength 12', PasswordUtil::defaultLength() === 12);
t('isValidLength ok for 12', PasswordUtil::isValidLength(str_repeat('x', 12)));
t('isValidLength fails for 11', !PasswordUtil::isValidLength(str_repeat('x', 11)));

cfg('PASSWORD_LENGTH', '8');
t('minLength honors 8', PasswordUtil::minLength() === 8);
cfg('PASSWORD_LENGTH', '4');
t('minLength floored at 6', PasswordUtil::minLength() === 6);

$gen = PasswordUtil::generate();
t('generate() default length = min', strlen($gen) === PasswordUtil::minLength(), ['len' => strlen($gen)]);
t('generate() charset only', (bool) preg_match('/^[A-Za-z0-9!@#$%^&*()]+$/', $gen));
$gen8 = PasswordUtil::generate(8);
t('generate(8) length', strlen($gen8) === 8);
$gen3 = PasswordUtil::generate(3);
t('generate(3) floored to 6', strlen($gen3) === 6);
$uniq = [];
for ($i = 0; $i < 20; $i++) {
    $uniq[PasswordUtil::generate(16)] = true;
}
t('generate is not degenerate', count($uniq) >= 19, ['distinct' => count($uniq)]);
cfgDrop(['PASSWORD_LENGTH', '']);

// --- RESERVED_NAMES ----------------------------------------------------
cfg('RESERVED_NAMES', 'root,postfix,pmwh,vmail');
t('reserved exact', CustomerUtil::isReservedName('root'));
t('reserved case-insensitive', CustomerUtil::isReservedName('POSTFIX'));
t('reserved trimmed', CustomerUtil::isReservedName(' pmwh '));
t('reserved not-matched', !CustomerUtil::isReservedName('chris'));
t('reserved empty name', !CustomerUtil::isReservedName(''));
t('reserved list parse',
    CustomerUtil::reservedNames() === ['root', 'postfix', 'pmwh', 'vmail']);
cfgDrop(['RESERVED_NAMES', '']);

// --- CAPTCHA_TYPE disabled ----------------------------------------------
cfg('CAPTCHA_TYPE', 'none');
t('type() none', CaptchaManager::type() === 'none');
t('isEnabled false on none', !CaptchaManager::isEnabled());
t('renderContext null when disabled', CaptchaManager::renderContext() === null);
t('verify pass-through when disabled', CaptchaManager::verify('') === true);
t('verify pass-through no token', CaptchaManager::verify(null) === true);
cfgDrop(['CAPTCHA_TYPE', 'RECAPTCHA_SITE_KEY', 'RECAPTCHA_SECRET_KEY', 'RECAPTCHA_SCORE']);

// --- enabled but missing keys = fail-open --------------------------------
cfg('CAPTCHA_TYPE', 'recaptcha_v3');
t('isEnabled false without keys', !CaptchaManager::isEnabled());
t('verify fail-open without keys', CaptchaManager::verify('whatever') === true);
t('renderContext null without keys', CaptchaManager::renderContext() === null);
cfgDrop(['CAPTCHA_TYPE', 'RECAPTCHA_SITE_KEY', 'RECAPTCHA_SECRET_KEY', 'RECAPTCHA_SCORE']);

// --- http mock server -----------------------------------------------------
$port = random_int(20000, 40000);
$router = sys_get_temp_dir() . '/pmwh3-g1-router-' . getmypid() . '.php';
$routerCode = <<<'PHP'
<?php
// Mock für Google siteverify. Antwort hängt am POST-Parameter 'response'.
$body = file_get_contents('php://input');
parse_str((string) $body, $post);
$r = (string) ($post['response'] ?? '');
$remoteip = (string) ($post['remoteip'] ?? '');
$secret = (string) ($post['secret'] ?? '');
header('Content-Type: application/json');
switch ($r) {
    case 'ok':
        echo json_encode(['success' => true, 'score' => 0.9, 'challenge_ts' => date('c')]);
        return;
    case 'low':
        echo json_encode(['success' => true, 'score' => 0.2, 'challenge_ts' => date('c')]);
        return;
    case 'blocked':
        echo json_encode(['success' => false, 'error-codes' => ['invalid-input-response']]);
        return;
    case 'googlerr':
        echo json_encode(['success' => false, 'error-codes' => ['timeout-or-duplicate']]);
        return;
    case 'junk':
        echo 'this is not json';
        return;
    default:
        echo json_encode(['success' => false, 'error-codes' => ['missing-input-response']]);
}
PHP;
file_put_contents($router, $routerCode);

$cmd = sprintf('php -S 127.0.0.1:%d %s', $port, escapeshellarg($router));
$proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($proc)) {
    fwrite(STDERR, "FATAL: could not start php -S mock\n");
    exit(2);
}
$verifyUrl = "http://127.0.0.1:{$port}/siteverify";
putenv("CAPTCHA_VERIFY_URL={$verifyUrl}");
usleep(300000); // Server darf hochfahren

// --- recaptcha_v2 matrix ----------------------------------------------------
cfg('CAPTCHA_TYPE', 'recaptcha_v2');
cfg('RECAPTCHA_SITE_KEY', 'site-key-v2');
cfg('RECAPTCHA_SECRET_KEY', 'secret-key-v2');
t('v2 isEnabled', CaptchaManager::isEnabled());
t('v2 renderContext', CaptchaManager::renderContext() === ['type' => 'recaptcha_v2', 'siteKey' => 'site-key-v2']);
t('v2 empty token blocked', CaptchaManager::verify('') === false);
t('v2 ok token allowed', CaptchaManager::verify('ok') === true);
t('v2 blocked token rejected', CaptchaManager::verify('blocked') === false);
t('v2 google error = fail-open', CaptchaManager::verify('googlerr') === true);
t('v2 non-json = fail-open', CaptchaManager::verify('junk') === true);
t('v2 unknown token rejected', CaptchaManager::verify('nope') === false);
t('v2 ip forwarded to mock', CaptchaManager::verify('ok', '203.0.113.7') === true);

// --- recaptcha_v3 matrix (score threshold) ------------------------------------
cfg('CAPTCHA_TYPE', 'recaptcha_v3');
cfg('RECAPTCHA_SCORE', '0.5');
t('v3 isEnabled', CaptchaManager::isEnabled());
t('v3 renderContext', CaptchaManager::renderContext() === ['type' => 'recaptcha_v3', 'siteKey' => 'site-key-v2']);
t('v3 score above threshold', CaptchaManager::verify('ok') === true);
t('v3 score below threshold', CaptchaManager::verify('low') === false);
t('v3 empty token blocked', CaptchaManager::verify('') === false);

// --- threshold edge: non-numeric falls back to 0.5 --------------------------------
cfg('RECAPTCHA_SCORE', 'banana');
t('v3 invalid threshold fallback 0.5', CaptchaManager::verify('low') === false);  // 0.2 < 0.5
cfg('RECAPTCHA_SCORE', '0.1');
t('v3 threshold 0.1 accepts 0.2', CaptchaManager::verify('low') === true);

// --- curl transport failure = fail-open ---------------------------------------
// Server abschießen → curl scheitert → verify muss trotzdem true liefern.
proc_terminate($proc, 9); // SIGKILL
proc_close($proc);
@unlink($router);
sleep(1); // Port-Pendant freigeben
t('transport error = fail-open', CaptchaManager::verify('ok') === true);
putenv('CAPTCHA_VERIFY_URL');

// --- cleanup ----------------------------------------------------------------
cfgDrop(['CAPTCHA_TYPE', 'RECAPTCHA_SITE_KEY', 'RECAPTCHA_SECRET_KEY', 'RECAPTCHA_SCORE', '']);

echo "ALL G1 SECURITY TESTS DONE\n";