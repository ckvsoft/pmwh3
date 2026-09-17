#!/usr/bin/env php
<?php
/**
 * generate_pot.php
 * Regeneriert locale/template.pot als UNION aus:
 *   a) frischem xgettext-Scan aller *.php/*.js des Moduls
 *      (__(), __n(), _(), _n()) — inkl. i18n/tools/runtime_strings.php
 *   b) automatisch extrahierten Strings aus settingsschema.php
 *      (label, help, options-Schlüssel + Labels, group-Konstanten-Namen)
 *   c) dem bisherigen template.pot (msgcat-Union) — so bleiben auch
 *      historische msgids erhalten, die xgettext heute nicht mehr findet.
 *
 * WARUM: msgmerge markiert alle PO-Einträge ohne msgid im template.pot als
 * #~ obsolete (und msgattrib --no-obsolete löscht sie!). Das POT muss daher
 * ALLE je verwendeten Strings enthalten.
 *
 * Ablauf: ./generate_pot.php
 *   erzeugt template.pot (Union) — die PO-Dateien bleiben unberührt.
 * Danach: ./compile_all_po.php (msgmerge --no-fuzzy-matching + msgfmt)
 */
$root = realpath(__DIR__ . '/../..') ?: (__DIR__ . '/..'); // modules/pmwh3

$potOld    = $root . '/i18n/locale/template.pot';
$potTmp    = sys_get_temp_dir() . '/pmwh3_fresh.pot';
$potSchema = sys_get_temp_dir() . '/pmwh3_schema.pot';
$potNew    = sys_get_temp_dir() . '/pmwh3_template_new.pot';

// --- (a) frischer xgettext-Scan (gesamter Modulbaum inkl. Seeds) ---
$find  = ['find', escapeshellarg($root), '-type', 'f', "-regex", "'.*\\.\\(php\\|js\\)$'"];
$cmd   = implode(' ', $find)
       . ' | xgettext --from-code=UTF-8 --language=PHP'
       . ' -k__:1 -k__n:1,2 -k_:1 -k_n:1,2'
       . ' --output=' . escapeshellarg($potTmp) . ' -f - 2>/dev/null';
exec($cmd, $out, $rc);
if ($rc !== 0 || !is_file($potTmp)) {
    fwrite(STDERR, "xgettext gescheitert (rc=$rc). gettext-tools installiert?\n");
    exit(1);
}
$freshCount = substr_count(file_get_contents($potTmp), "\nmsgid ");

// --- (b) settingsschema.php automatisch parsen -> msgids ---------------
$schemaFile = $root . '/config/settingsschema.php';
$schemaSrc  = file_get_contents($schemaFile);
$schemaStrings = [];

// label + help Strings
foreach (['label', 'help'] as $k) {
    preg_match_all("/'$k'\s*=>\s*'((?:[^'\\\\]|\\\\.)*)'/", $schemaSrc, $m);
    foreach ($m[1] as $s) {
        $s = stripcslashes(trim($s));
        if ($s !== '') $schemaStrings[$s] = true;
    }
}

// options arrays: Keys + Labels
preg_match_all("/'options'\s*=>\s*\[(.*?)\]/s", $schemaSrc, $opts);
foreach ($opts[1] as $block) {
    preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'\s*=>\s*'((?:[^'\\\\]|\\\\.)*)'/", $block, $pairs);
    foreach ($pairs[1] as $v) { $v = stripcslashes(trim($v)); if ($v !== '') $schemaStrings[$v] = true; }
    foreach ($pairs[2] as $v) { $v = stripcslashes(trim($v)); if ($v !== '') $schemaStrings[$v] = true; }
}

// GROUP_* constants -> readable group names for the PO context
$groupMap = [
    'GROUP_SYSTEM' => 'System', 'GROUP_WEB' => 'Web', 'GROUP_EMAIL' => 'Email',
    'GROUP_FTP' => 'Ftp', 'GROUP_DNS' => 'Dns', 'GROUP_DATABASES' => 'Databases',
    'GROUP_DOMAINS' => 'Domains', 'GROUP_CONFIRMATION' => 'Dataprotection',
    'GROUP_SESSION' => 'Sessions', 'GROUP_LAYOUT' => 'Layout',
    'GROUP_FILTERING' => 'Filtering', 'GROUP_WBLIST' => 'Wblist',
];
foreach ($groupMap as $const => $label) {
    $schemaStrings[$label] = true;
}

if ($schemaStrings) {
    // Temporäres PHP-File mit __('...') calls erzeugen, xgettext drauf laufen lassen
    $tmpPhp = sys_get_temp_dir() . '/pmwh3_schema_seed.php';
    $code = "<?php\n";
    foreach (array_keys($schemaStrings) as $s) {
        $escaped = str_replace("'", "\\'", $s);
        $code .= "__('$escaped');\n";
    }
    file_put_contents($tmpPhp, $code);

    $cmdSeed = 'xgettext --from-code=UTF-8 --language=PHP'
             . ' -k__:1 -k__n:1,2 -k_:1 -k_n:1,2'
             . ' --output=' . escapeshellarg($potSchema)
             . ' ' . escapeshellarg($tmpPhp) . ' 2>/dev/null';
    exec($cmdSeed, $outSeed, $rcSeed);
    @unlink($tmpPhp);
} else {
    // leere Datei anlegen, damit msgcat nicht scheitert
    file_put_contents($potSchema, '');
}
$schemaCount = is_file($potSchema) ? substr_count(file_get_contents($potSchema), "\nmsgid ") : 0;

// --- (c) Union: xgettext-Scan + schema-seed + altes POT ---------------
$unionParts = [$potTmp, $potSchema];
if ($oldExists = is_file($potOld)) {
    $unionParts[] = $potOld;
}
$unionCmd = 'msgcat ' . implode(' ', array_map('escapeshellarg', $unionParts))
          . ' -o ' . escapeshellarg($potNew) . ' 2>/dev/null';
exec($unionCmd, $out, $rcU);
if ($rcU !== 0 || !is_file($potNew)) {
    fwrite(STDERR, "msgcat-Union gescheitert (rc=$rcU)\n");
    exit(1);
}
$unionCount = substr_count(file_get_contents($potNew), "\nmsgid ");

copy($potNew, $potOld);
chmod($potOld, 0644);

// --- (d) Validierung ---
exec('msgfmt -c -o /dev/null ' . escapeshellarg($potOld) . ' 2>&1', $err, $rcF);
$fatal = preg_grep('/Mehrfachdefinition/i', $err);

printf("frischer xgettext-Scan : %d msgids\n", $freshCount);
printf("Schema-Seed           : %d msgids\n", $schemaCount);
printf("Union (mit altem POT) : %d msgids\n", $unionCount);
printf("msgfmt -c (template)  : %s\n", $rcF === 0 ? 'OK' : 'WARNUNGEN');
if ($fatal) {
    fprintf(STDERR, "%d Duplikat-Meldungen! Bitte übersetzungen prüfen.\n", count($fatal));
    exit(1);
}
echo "template.pot geschrieben: $potOld\n";