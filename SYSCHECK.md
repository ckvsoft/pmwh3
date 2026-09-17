# PMWH3 – Systemcheck & Abhängigkeiten

## 1. Systemvoraussetzungen

- PHP ≥ 8.0  
- PDO + PDO MySQL  
- mbstring  
- Optional: bcmath, intl, gettext  
- MariaDB/MySQL ≥ 10.x  

## 2. PHP Extensions

| Extension     | Erforderlich | Status |
|--------------|-------------|--------|
| pdo          | ja          | wird geprüft |
| pdo_mysql    | ja          | wird geprüft |
| mbstring     | ja          | wird geprüft |
| bcmath       | nein        | wird geprüft (Fallback verfügbar) |
| intl         | nein        | wird geprüft |
| gettext      | nein        | wird geprüft |

## 3. Schreibrechte

- `var/` Verzeichnisse müssen beschreibbar sein  

---

## 4. PHP-Testscript (`system_check.php`)

```php
<?php
function checkExtension(string $ext, bool $required = false) {
    $loaded = extension_loaded($ext);
    return [
        'extension' => $ext,
        'required' => $required ? 'yes' : 'no',
        'loaded' => $loaded ? 'yes' : 'no',
    ];
}

$extensions = [
    ['pdo', true],
    ['pdo_mysql', true],
    ['mbstring', true],
    ['bcmath', false],
    ['intl', false],
    ['gettext', false],
];

$results = [];
foreach ($extensions as [$ext, $req]) {
    $results[] = checkExtension($ext, $req);
}

echo "=== PMWH3 System Check ===\n\n";
echo "PHP Version: " . PHP_VERSION . "\n\n";
echo "Extension Status:\n";
foreach ($results as $r) {
    printf("%-10s Required: %-3s Loaded: %-3s\n", $r['extension'], $r['required'], $r['loaded']);
}

// Check write permissions for cache/session
$dirs = ['var', 'tmp'];
foreach ($dirs as $dir) {
    $writable = is_writable($dir) ? 'yes' : 'no';
    echo "Directory '$dir' writable: $writable\n";
}
