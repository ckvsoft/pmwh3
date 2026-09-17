#!/usr/bin/env php
<?php

/**
 * compile_all_po.php
 * Aktualisiert und kompiliert alle PO-Dateien in locale/po/ gegen template.pot
 * MO-Dateien werden unter <lang>/LC_MESSAGES/pmwh3.mo erzeugt
 *
 * ACHTUNG — Datenverlust-Schutz:
 *  - msgmerge läuft IMMER mit --no-fuzzy-matching. Ohne dieses Flag ersetzt
 *    msgmerge bei kleinen msgid-Änderungen die bestehende Übersetzung durch
 *    "fuzzy" und danach entfernt msgattrib --no-obsolete sie endgültig.
 *  - Voraussetzung für --no-obsolete: template.pot muss ALLE verwendeten
 *    Strings enthalten, auch DB-/RBAC-/Settings-/hartkodierte. Diese pflegt
 *    generate_pot.php über i18n/tools/runtime_strings.php. Ohne Seed läuft
 *    msgattrib --no-obsolete in Datenverlust (Strings werden als #~ gelöscht).
 *  - Reihenfolge: ./generate_pot.php  →  ./compile_all_po.php
 */
$poDir = __DIR__ . '/../locale/po';
$template = __DIR__ . '/../locale/template.pot';

// Prüfen
if (!is_dir($poDir))
    die("PO-Verzeichnis existiert nicht: $poDir\n");
if (!file_exists($template))
    die("Template-POT nicht gefunden: $template\n");

// Alle PO-Dateien aktualisieren + bereinigen
$poFiles = glob("$poDir/*.po");
if (!$poFiles) {
    echo "Keine PO-Dateien im Verzeichnis $poDir gefunden!\n";
    exit;
}

foreach ($poFiles as $poFile) {
    $filename = basename($poFile);
    $lang = preg_replace('/\.po$/', '', $filename); // z. B. de_DE
    // 1. Merge mit template.pot (ohne Fuzzy — keine Übersetzungen verlieren)
    $cmdMerge = "msgmerge --no-fuzzy-matching --update --backup=none " . escapeshellarg($poFile) . " " . escapeshellarg($template);
    exec($cmdMerge, $outputMerge, $returnMerge);
    if ($returnMerge !== 0) {
        echo "Fehler beim Mergen von $poFile\n";
        continue;
    }

    // 2. Obsolete Einträge entfernen
    $cmdClean = "msgattrib --no-obsolete " . escapeshellarg($poFile) . " -o " . escapeshellarg($poFile);
    exec($cmdClean, $outputClean, $returnClean);
    if ($returnClean !== 0) {
        echo "Fehler beim Bereinigen von $poFile\n";
        continue;
    }

    // 3. MO-Datei erstellen im richtigen Verzeichnis
    $lcMessagesDir = __DIR__ . "/../locale/$lang/LC_MESSAGES";
    if (!is_dir($lcMessagesDir))
        mkdir($lcMessagesDir, 0777, true);

    $moFile = "$lcMessagesDir/pmwh3.mo";
    $cmdCompile = "msgfmt " . escapeshellarg($poFile) . " -o " . escapeshellarg($moFile);
    exec($cmdCompile, $outputCompile, $returnCompile);
    if ($returnCompile === 0) {
        echo "Kompiliert $poFile -> $moFile\n";
    } else {
        echo "Fehler beim Kompilieren von $poFile\n";
    }
}

echo "Fertig! MO-Dateien liegen in <lang>/LC_MESSAGES/pmwh3.mo\n";
