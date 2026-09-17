<?php
declare(strict_types=1);
/** Installer engine test (framework core pre-seeded by preinstall_setup.php). */
require_once __DIR__ . "/../../../library/ckvsoft/autoload.php";
$root = dirname(__DIR__, 3);
new ckvsoft\Autoload([$root . "/library/", $root . "/modules/", $root . "/modules/pmwh3/"]);
require_once __DIR__ . "/../modulautoload.php";
require_once $root . "/modules/pmwh3/utils/db/abstractdbadapter.php";
require_once $root . "/modules/pmwh3/utils/db/mysqldbadapter.php";

use ckvsoft\mvc\Config;
use pmwh3\Utils\InstallBootstrap;

function t(string $what, bool $ok, array $info = []): void {
    echo ($ok ? "PASS" : "FAIL"), "  ", $what;
    if ($info) echo "  ", json_encode($info);
    echo "\n";
    if (!$ok) exit(1);
}

define("BASE_URI", "/");
define("MODULES_URI", "modules/");
define("CORE_MODULES_URI", "core_modules/");
if (!defined("HASH_KEY")) { $_app = json_decode((string) file_get_contents("/srv/config/app.json"), true); define("HASH_KEY", $_app["app"]["hash_key"] ?? hex2bin("aabbcc")); }

$fdb = Config::db();

foreach (["roles","permissions","role_perms","user_roles"] as $tbl) {
    try { $fdb->execDdl("TRUNCATE TABLE $tbl"); } catch (Throwable $e) { /* grouped */ }
}
try { Config::moduleDb()->delete("pmwh3_customers", "customer = :c", ["c" => "admin"]); } catch (Throwable $e) { /* fresh */ }
try { Config::moduleDb()->exec("DELETE FROM pmwh3_countings"); } catch (Throwable $e) { /* idempotent */ }

$moduleJsonPath = $root . "/modules/pmwh3/module.json";
if (!file_exists($root . "/tmp_module_backup.json")) {
    file_put_contents($root . "/tmp_module_backup.json", (string) file_get_contents($moduleJsonPath));
}
file_put_contents($moduleJsonPath, (string) file_get_contents($root . "/tmp_module_placeholder.json"));
Config::moduleDb()->executeSqlFile($root . "/modules/pmwh3/inc/sql/0.0.0_baseline.sql"); // idempotent baseline replay per run

t("needsInstall (placeholder)", InstallBootstrap::needsInstall());

$in = [
    "db_host" => "172.17.0.2", "db_name" => "pmwh3_test",
    "db_user" => (string) (getenv('TEST_DB_USER') ?: 'pmtst'),
    "db_pass" => (string) (getenv('TEST_DB_PASS') ?: ''),
    "dns_same" => "1", "dns_name" => (string) (getenv('TEST_DNS_DB_NAME') ?: 'mydns_test'),
    "admin_password" => "TestInstall12!",
];
$res = InstallBootstrap::run($in);
t("run() ok", !empty($res["ok"]), $res["steps"] ?? []);

$roles = [];
foreach ($fdb->select("SELECT roleName FROM roles WHERE module = :m", ["m" => "pmwh3"]) ?: [] as $r) {
    $roles[] = $r["roleName"];
}
t("roles pmwh3 tree", in_array("Ultimate Admin", $roles) && in_array("Reseller", $roles) && in_array("Customer", $roles), $roles);

$permCount = (int) ($fdb->selectOne("SELECT COUNT(*) c FROM permissions WHERE module = :m", ["m" => "pmwh3"])["c"] ?? 0);
t("permissions registered", $permCount >= 70, ["count" => $permCount]);

$urole = $fdb->selectOne("SELECT id FROM roles WHERE roleName = :n AND module = :m", ["n" => "Ultimate Admin", "m" => "pmwh3"]);
$grants = (int) ($fdb->selectOne("SELECT COUNT(*) c FROM role_perms WHERE roleID = :r", ["r" => $urole["id"]])["c"] ?? 0);
t("Ultimate Admin has all grants", $grants === $permCount, ["grants" => $grants, "perms" => $permCount]);

$mod = Config::moduleDb();
$admin = $mod->selectOne("SELECT cid, role_id FROM pmwh3_customers WHERE customer = :c", ["c" => "admin"]);
t("admin customer row", !empty($admin["cid"]), $admin);
t("admin has ultimate role", ((int) $admin["role_id"]) === (int) $urole["id"]);

$maxRow = $mod->selectOne("SELECT * FROM pmwh3_countings WHERE cid = :c AND type = :t", ["c" => $admin["cid"], "t" => "max"]);
t("counters unlimited (-1)", (int) $maxRow["domains"] === -1, ["domains" => $maxRow["domains"]]);

t("needsInstall cleared", !InstallBootstrap::needsInstall());

$res2 = InstallBootstrap::run($in);
t("re-run ok", !empty($res2["ok"]), $res2["steps"] ?? []);

file_put_contents($moduleJsonPath, (string) file_get_contents($root . "/tmp_module_backup.json"));
echo "ALL INSTALL TESTS DONE\n";
