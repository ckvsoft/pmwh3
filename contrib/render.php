#!/usr/bin/env php
<?php

/**
 * pmwh3 contrib template renderer.
 *
 * Fills the @VAR@ placeholders of the contrib/ templates with the
 * values from a vars file and writes ready-to-copy files into an
 * output directory OUTSIDE this repository (shadow-dir rule).
 *
 * Usage:
 *   php contrib/render.php --vars render.vars --out ~/pmwh3-contrib-out
 *   php contrib/render.php --check --vars render.vars   (validate only)
 *
 * Vars file format (see render.vars.example):
 *   # comment
 *   KEY="value"
 *
 * Rules:
 *   - every *.example file loses the .example suffix in the output
 *   - *.sh output keeps the executable bit
 *   - a template that still contains an unresolved @VAR@ after the
 *     substitution is a hard error (missing or empty variable)
 *   - contrib/sql/ is skipped (DNS schemas belong to the install
 *     wizard, not to the daemon deploy)
 *   - markdown docs and this script itself are skipped
 *   - the output directory must live outside the cevian tree
 *
 * Exit codes: 0 = ok, 1 = validation/render errors, 2 = usage error.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(2);
}

// ----------------------------------------------------------------
// CLI arguments
// ----------------------------------------------------------------

function usage(): string
{
    return <<<TXT
pmwh3 contrib renderer

  --vars <file>   vars file (default: render.vars next to this script)
  --out <dir>     output directory (default: ~/pmwh3-contrib-out)
  --check         validate only, write nothing
  --help          this text

TXT;
}

$opt = ['vars' => null, 'out' => null, 'check' => false, 'help' => false];
$args = $_SERVER['argv'] ?? [];
for ($i = 1; $i < count($args); $i++) {
    switch ($args[$i]) {
        case '--vars':
        case '--out':
            $key = substr($args[$i], 2);
            if (!isset($args[$i + 1])) {
                fwrite(STDERR, "missing value for {$args[$i]}\n" . usage());
                exit(2);
            }
            $opt[$key] = $args[++$i];
            break;
        case '--check':
            $opt['check'] = true;
            break;
        case '--help':
        case '-h':
            echo usage();
            exit(0);
        default:
            fwrite(STDERR, "unknown argument {$args[$i]}\n" . usage());
            exit(2);
    }
}

$contribDir = __DIR__;
$varsFile = $opt['vars'] !== null
        ? $opt['vars']
        : $contribDir . '/render.vars';

if (!is_file($varsFile)) {
    fwrite(STDERR, "vars file not found: {$varsFile}\n"
            . "copy contrib/render.vars.example to render.vars and fill it in\n");
    exit(1);
}

// ----------------------------------------------------------------
// Vars file
// ----------------------------------------------------------------

/** @return array<string,string> */
function parseVarsFile(string $path): array
{
    $vars = [];
    $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];
    foreach ($lines as $n => $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $eq = strpos($line, '=');
        if ($eq === false) {
            fwrite(STDERR, "vars file syntax error (" . basename($path)
                    . ' line ' . ($n + 1) . "): expected KEY=\"value\"\n");
            exit(1);
        }
        $key = trim(substr($line, 0, $eq));
        $val = trim(substr($line, $eq + 1));
        // strip one pair of surrounding quotes
        if (strlen($val) >= 2 && $val[0] === $val[strlen($val) - 1]
                && ($val[0] === '"' || $val[0] === "'")) {
            $val = substr($val, 1, -1);
        }
        if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
            fwrite(STDERR, "invalid variable name '{$key}' (" . basename($path)
                    . ' line ' . ($n + 1) . "): [A-Z][A-Z0-9_]* only\n");
            exit(1);
        }
        $vars[$key] = $val;
    }
    return $vars;
}

$vars = parseVarsFile($varsFile);

// ----------------------------------------------------------------
// Template discovery
// ----------------------------------------------------------------

/** Skip rules: installer-owned sql/, docs, the renderer itself. */
function isTemplate(string $rel): bool
{
    if (str_starts_with($rel, 'sql/')) {
        return false;
    }
    if (str_ends_with($rel, '.md')) {
        return false;
    }
    if ($rel === 'render.php' || $rel === 'render.vars'
            || $rel === 'render.vars.example') {
        return false;
    }
    if (str_contains($rel, '.bak')) {
        return false;
    }
    return true;
}

/** @return list<string> relative template paths */
function collectTemplates(string $dir): array
{
    $out = [];
    $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir,
                    FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile()) {
            continue;
        }
        $rel = ltrim(substr($f->getPathname(), strlen($dir)), '/');
        if (isTemplate($rel)) {
            $out[] = $rel;
        }
    }
    sort($out);
    return $out;
}

$templates = collectTemplates($contribDir);
if (!$templates) {
    fwrite(STDERR, "no templates found in {$contribDir}\n");
    exit(1);
}

// ----------------------------------------------------------------
// Rendering
// ----------------------------------------------------------------

/** Where the rendered file belongs (hint printed at the end). */
function destinationHint(string $rel): string
{
    $top = explode('/', $rel)[0];
    return [
        'apache'  => '/etc/apache2/sites-enabled/ (mod_perl; restart apache after pmwh3 vhost changes)',
        'postfix' => str_ends_with($rel, '.sql')
                ? 'apply ONCE to the pmwh3 database (routing table)'
                : '/etc/postfix/sql/ (postfix reload)',
        'dovecot' => $rel === 'dovecot/rspamc_learn.sh'
                ? 'where your sieve rules call it (exec bit is kept)'
                : 'merge into dovecot.conf (restart dovecot)',
        'proftpd' => 'merge into proftpd.conf / conf.d (restart proftpd)',
        'rspamd'  => '/etc/rspamd/local.d/ (+APPEND to the existing file, restart rspamd)',
    ][$top] ?? 'see contrib/INSTALL.md';
}

$errors = [];
$results = []; // [rel, outRel, hint, usedVars]

foreach ($templates as $rel) {
    $content = (string) file_get_contents($contribDir . '/' . $rel);

    // which placeholders does this template use?
    preg_match_all('/@([A-Z][A-Z0-9_]*)@/', $content, $m);
    $used = array_values(array_unique($m[1]));

    $missing = [];
    foreach ($used as $name) {
        if (!array_key_exists($name, $vars) || trim($vars[$name]) === '') {
            $missing[] = '@' . $name . '@';
        } else {
            $content = str_replace('@' . $name . '@', $vars[$name], $content);
        }
    }
    if ($missing) {
        $errors[] = $rel . ': missing/empty ' . implode(', ', $missing);
        continue;
    }

    // safety net: values that themselves look like placeholders
    if (preg_match('/@([A-Z][A-Z0-9_]*)@/', $content, $m2)
            && !in_array($m2[1], $used)) {
        $errors[] = $rel . ': unresolved @' . $m2[1]
                . '@ after substitution (value contains a placeholder?)';
        continue;
    }

    $outRel = str_ends_with($rel, '.example')
            ? substr($rel, 0, -strlen('.example')) : $rel;
    $results[] = [$outRel, destinationHint($outRel), $content];
}

if ($errors) {
    fwrite(STDERR, "RENDER FAILED -- fill these in render.vars and retry:\n");
    foreach ($errors as $e) {
        fwrite(STDERR, "  {$e}\n");
    }
    exit(1);
}

if ($opt['check']) {
    echo "OK -- all placeholders resolvable with {$varsFile} "
            . '(' . count($results) . " templates, nothing written)\n";
    exit(0);
}

// ----------------------------------------------------------------
// Output
// ----------------------------------------------------------------

$home = $_SERVER['HOME'] ?? null;
$outDir = $opt['out'] !== null
        ? $opt['out']
        : ($home !== null ? $home . '/pmwh3-contrib-out' : null);
if ($outDir === null) {
    fwrite(STDERR, "no --out given and \$HOME is unset\n" . usage());
    exit(2);
}

// shadow-dir rule: never write into the cevian tree
$realOut = rtrim($outDir, '/');
if (!str_starts_with($realOut, '/')) {
    $realOut = rtrim((string) getcwd(), '/') . '/' . $realOut;
}
$cevianRoot = dirname($contribDir, 3); // contrib -> pmwh3 -> modules -> cevian
foreach ([dirname($contribDir), $cevianRoot] as $guard) {
    if (str_starts_with($realOut, rtrim($guard, '/') . '/')) {
        fwrite(STDERR, "refusing to write inside the source tree ({$realOut})\n"
                . "use a directory outside the cevian tree, e.g. ~/pmwh3-contrib-out\n");
        exit(1);
    }
}

if (!is_dir($realOut) && !@mkdir($realOut, 0775, true)) {
    fwrite(STDERR, "cannot create output directory {$realOut}\n");
    exit(1);
}

foreach ($results as [$outRel, $hint, $content]) {
    $target = $realOut . '/' . $outRel;
    if (!is_dir(dirname($target))
            && !@mkdir(dirname($target), 0775, true)) {
        fwrite(STDERR, "cannot create " . dirname($target) . "\n");
        exit(1);
    }
    if (@file_put_contents($target, $content) === false) {
        fwrite(STDERR, "cannot write {$target}\n");
        exit(1);
    }
    chmod($target, str_ends_with($outRel, '.sh') ? 0755 : 0644);
}

echo "Rendered " . count($results) . " files into {$realOut}\n";
echo "from vars: {$varsFile}\n\n";
foreach ($results as [$outRel, $hint, $content]) {
    echo "  {$outRel}\n      -> {$hint}\n";
}
echo "\nCopy the files to their destinations (see contrib/INSTALL.md for\n"
    . "the full walkthrough incl. health checks). Files marked +APPEND\n"
    . "must be appended to the existing local.d file, not replace it.\n";
exit(0);
