<?php
declare(strict_types=1);
$pw = rtrim(file_get_contents(getenv('HOME') . '/tmp/mysql-test.cred'), "\n");
$pdo = new PDO('mysql:host=127.0.0.1;port=33061', 'root', $pw, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$sql = "
CREATE DATABASE IF NOT EXISTS pmwh3_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS mydns_test CHARACTER SET utf8mb3;
CREATE DATABASE IF NOT EXISTS pdns_test CHARACTER SET utf8mb4;
CREATE USER IF NOT EXISTS 'pmtst'@'127.0.0.1' IDENTIFIED BY :p;
GRANT ALL PRIVILEGES ON pmwh3_test.* TO 'pmtst'@'127.0.0.1';
GRANT ALL PRIVILEGES ON mydns_test.*  TO 'pmtst'@'127.0.0.1';
GRANT ALL PRIVILEGES ON pdns_test.*   TO 'pmtst'@'127.0.0.1';
FLUSH PRIVILEGES;
";
$stmt = $pdo->prepare($sql);
$stmt->execute([':p' => $pw]);
foreach ($pdo->query("SHOW DATABASES") as $r) {
    echo $r['Database'], "\n";
}
