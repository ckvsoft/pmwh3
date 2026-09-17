<?php
$pw = getenv('PW');
try {
    $p = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'pmtst', $pw, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    echo 'OK as ', $p->query('SELECT CURRENT_USER()')->fetchColumn(), "\n";
} catch (Throwable $t) {
    echo 'ERR: ', $t->getMessage(), "\n";
}
