<?php
declare(strict_types=1);
// Test-Schemas auf dem developer mysql80 anlegen (idempotent).
// Creds via Environment: TEST_DB_HOST/PORT/USER/PASS (docker run -e ...).
//   mydns_test: exakt die Legacy-Spalten aus ck000005_pmwh2.mydns_*
//   pdns_test:  die 4 vom PdnsAdapter benutzten Tabellen (domains, records,
//               cryptokeys, domainmetadata) im Stock-Schema
//   pmwh3_test: pmwh3_configuration mit DNS_TYPE/MAIL_TYPE Seed

$u    = getenv('TEST_DB_USER') ?: 'pmtst';
$pw   = getenv('TEST_DB_PASS') ?: '';
$host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
$port = getenv('TEST_DB_PORT') ?: '3306';
if ($pw === '') {
    fwrite(STDERR, "TEST_DB_PASS fehlt\n");
    exit(1);
}

$pdo = new PDO('mysql:host=' . $host . ';port=' . $port . ';charset=utf8mb4', $u, $pw, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$pdo->exec("CREATE DATABASE IF NOT EXISTS pmwh3_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("CREATE DATABASE IF NOT EXISTS mydns_test CHARACTER SET utf8mb3");
$pdo->exec("CREATE DATABASE IF NOT EXISTS pdns_test CHARACTER SET utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS mydns_test.mydns_soa (
  id int unsigned NOT NULL AUTO_INCREMENT,
  origin char(255) NOT NULL,
  ns char(255) NOT NULL,
  mbox char(255) NOT NULL,
  serial int unsigned NOT NULL DEFAULT 1,
  refresh int unsigned NOT NULL DEFAULT 28800,
  retry int unsigned NOT NULL DEFAULT 7200,
  expire int unsigned NOT NULL DEFAULT 604800,
  minimum int unsigned NOT NULL DEFAULT 86400,
  ttl int unsigned NOT NULL DEFAULT 86400,
  xfer char(255) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY origin (origin)
) ENGINE=MyISAM");
$pdo->exec("CREATE TABLE IF NOT EXISTS mydns_test.mydns_rr (
  id int unsigned NOT NULL AUTO_INCREMENT,
  zone int unsigned NOT NULL DEFAULT 0,
  name char(64) NOT NULL DEFAULT '',
  type enum('A','AAAA','ALIAS','CNAME','HINFO','MX','NS','PTR','RP','SRV','TXT') DEFAULT NULL,
  data char(128) NOT NULL DEFAULT '',
  aux int unsigned NOT NULL DEFAULT 0,
  ttl int unsigned NOT NULL DEFAULT 86400,
  PRIMARY KEY (id),
  KEY mydns_rr_index (zone, name, type, data)
) ENGINE=MyISAM");

$pdo->exec("CREATE TABLE IF NOT EXISTS pdns_test.domains (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  master VARCHAR(128) DEFAULT NULL,
  last_check INT DEFAULT NULL,
  type VARCHAR(8) NOT NULL,
  notified_serial INT UNSIGNED DEFAULT NULL,
  account VARCHAR(40) DEFAULT NULL,
  UNIQUE KEY name_index (name)
) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE IF NOT EXISTS pdns_test.records (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  domain_id INT DEFAULT NULL,
  name VARCHAR(255) DEFAULT NULL,
  type VARCHAR(10) DEFAULT NULL,
  content TEXT DEFAULT NULL,
  ttl INT DEFAULT NULL,
  prio INT DEFAULT NULL,
  disabled TINYINT(1) DEFAULT 0,
  auth TINYINT(1) DEFAULT 1,
  ordername VARCHAR(255) BINARY DEFAULT NULL,
  KEY nametype_index (name, type),
  KEY domain_id (domain_id)
) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE IF NOT EXISTS pdns_test.cryptokeys (
  id INT AUTO_INCREMENT PRIMARY KEY,
  domain_id INT DEFAULT NULL,
  flags INT NOT NULL,
  active TINYINT(1) DEFAULT 1,
  published TINYINT(1) DEFAULT 1,
  content TEXT
) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE IF NOT EXISTS pdns_test.domainmetadata (
  id INT AUTO_INCREMENT PRIMARY KEY,
  domain_id INT DEFAULT NULL,
  kind VARCHAR(32),
  content TEXT
) ENGINE=InnoDB");

$pdo->exec("CREATE TABLE IF NOT EXISTS pmwh3_test.pmwh3_configuration (
  id INT NOT NULL AUTO_INCREMENT,
  configuration_key VARCHAR(100) NOT NULL DEFAULT '',
  configuration_value TEXT DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY configuration_key (configuration_key)
) ENGINE=InnoDB");

$adapter = $argv[1] ?? 'mydns';
$st = $pdo->prepare("INSERT INTO pmwh3_test.pmwh3_configuration
  (configuration_key, configuration_value) VALUES ('DNS_TYPE', :t)
  ON DUPLICATE KEY UPDATE configuration_value = VALUES(configuration_value)");
$st->execute([':t' => $adapter]);

$st2 = $pdo->prepare("INSERT INTO pmwh3_test.pmwh3_configuration
  (configuration_key, configuration_value) VALUES ('BACKUP_DIR', '/app/var/backup')
  ON DUPLICATE KEY UPDATE configuration_value = VALUES(configuration_value)");
$st2->execute();
echo "schemas ok (DNS_TYPE=$adapter)\n";
