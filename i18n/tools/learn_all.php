#!/usr/bin/env php
<?php

/**
 * learn_all.php
 * Standalone: Exportiert DB-Strings + File-Strings in eine POT-Datei
 * (DB-Zugriff über das Framework: Config::moduleDb() -- keine
 * Inline-Creds mehr. Gruppennamen kommen aus den RBAC-Roles.)
 */

// --- Framework-Bootstrapping (wie andere pmwh3-Testscripts) ---
if (!file_exists(__DIR__ . '/../../../var/config.php')) {
    die("var/config.php fehlt -- learn_all.php muss in einer Deployment-Installation laufen.\n");
}
require_once __DIR__ . '/../../../var/config.php';
require_once __DIR__ . '/../../../library/ckvsoft/autoload.php';
require_once __DIR__ . '/../../../modules/pmwh3/modulautoload.php';

use ckvsoft\mvc\Config;

// --- Konfiguration ---
$potFile = __DIR__ . '/../locale/messages.pot';

// --- DB-Strings exportieren ---
$dbStrings = [];
try {
    $db = Config::moduleDb();

    // pmwh3_menu
    foreach ($db->select("SELECT name FROM pmwh3_menu ORDER BY sort") as $row) {
        $dbStrings[] = trim($row['name']);
    }

    // Gruppennamen: Framework-RBAC-Roles (Etappe 4b; pmwh3_groups ist ab 4d weg)
    foreach ($db->select("SELECT roleName FROM roles WHERE module = 'pmwh3' ORDER BY roleName") as $row) {
        $dbStrings[] = trim($row['roleName']);
    }
} catch (Throwable $t) {
    echo "DB-Export fehlgeschlagen: " . $t->getMessage() . "\n";
}

// --- File-Strings exportieren ---
// 1. constants.php
$fileStrings = [];
$files = [__DIR__ . '/../config/constants.php'];
foreach ($files as $f) {
    if (!file_exists($f)) {
        continue;
    }
    $content = file_get_contents($f);
    if (preg_match_all('/define\(\s*"[^"]+"\s*,\s*_\("([^"]+)"\)\s*\)\s*;/', $content, $matches)) {
        foreach ($matches[1] as $msgid) {
            $fileStrings[] = trim($msgid);
        }
    }
}

// 2. Rekursiv diesen pmwh3-Modulebaum (Deployment) scannen
$pmwh3Dir = realpath(__DIR__ . '/../../..');
if (is_dir($pmwh3Dir)) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pmwh3Dir));
    foreach ($iterator as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
            $content = file_get_contents($file->getPathname());
            if (preg_match_all('/define\(\s*"[^"]+"\s*,\s*_\("([^"]+)"\)\s*\)\s*;/', $content, $matches)) {
                foreach ($matches[1] as $msgid) {
                    $fileStrings[] = trim($msgid);
                }
            }
        }
    }
} else {
    echo "PMWH3-Verzeichnis existiert nicht: $pmwh3Dir\n";
}

// --- Kombinieren & sortieren ---
$allStrings = array_unique(array_merge($dbStrings, $fileStrings));
sort($allStrings);

// --- POT-Datei erstellen ---
$pot = <<<EOT
#, fuzzy
msgid ""
msgstr ""
"Content-Type: text/plain; charset=UTF-8\\n"
"Content-Transfer-Encoding: 8bit\\n"
"Language: en\\n"

EOT;

foreach ($allStrings as $msgid) {
    if ($msgid === '') {
        continue;
    }
    $msgidEsc = addcslashes($msgid, "\"\\");
    $pot .= "msgid \"$msgidEsc\"\nmsgstr \"\"\n\n";
}

// Datei schreiben
file_put_contents($potFile, $pot);
echo "POT-Datei erstellt: $potFile\n";
