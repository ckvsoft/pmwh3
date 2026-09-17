<?php

$module = $argv[1] ?? null;
$controllerName = $argv[2] ?? null;
$pathOption = null;
$onlyView = false;
$onlyModel = false;

// Optionen prüfen
foreach ($argv as $arg) {
    if (strpos($arg, '--path=') === 0) {
        $pathOption = substr($arg, 7);
    }
    if ($arg === '--only-view') {
        $onlyView = true;
    }
    if ($arg === '--only-model') {
        $onlyModel = true;
    }
}

if (!$module || !$controllerName) {
    die("Usage: php create_modul.php <module> <Controller> [methods...] [--path=../] [--only-view|--only-model]\n");
}

$basePath = rtrim($pathOption ?? __DIR__, '/');
$controllerFile = "{$basePath}/controller/" . strtolower($controllerName) . ".php";
$modelDir = "{$basePath}/model";
$viewDir = "{$basePath}/view/" . strtolower($controllerName);

$controllerNameLower = strtolower($controllerName);

@mkdir(dirname($controllerFile), 0777, true);
@mkdir($modelDir, 0777, true);
@mkdir($viewDir, 0777, true);

// Methoden (ohne Optionen sammeln)
$methods = [];
for ($i = 3; $i < count($argv); $i++) {
    if (strpos($argv[$i], '--') === 0)
        continue;
    $methods[] = $argv[$i];
}
if (empty($methods))
    $methods = ['index'];

/**
 * --- Nur Model ---
 * Erstellt ein Model <method>_model.php
 * und erweitert Controller mit loadModel-Aufruf
 */
if ($onlyModel) {
    foreach ($methods as $method) {
        $method = strtolower($method);
        $modelFile = "$modelDir/{$method}_model.php";

        // Model erzeugen
        if (!file_exists($modelFile)) {
            $modelContent = <<<PHP
<?php

class {$method}_model extends ckvsoft\mvc\Model
{

    public function __construct()
    {
        parent::__construct();
    }

    public function getAll()
    {
        return [];
    }
}
PHP;
            file_put_contents($modelFile, $modelContent);
            echo "Model erstellt: $modelFile\n";
        }

        // Controller erweitern
        if (file_exists($controllerFile)) {
            $controllerContent = file_get_contents($controllerFile);

            if (!preg_match('/function\s+' . preg_quote($method, '/') . '\s*\(/', $controllerContent)) {
                // Neue Methode einfügen
                $newMethod = <<<PHP

    public function {$method}()
    {
        \$model = \$this->loadModel('{$method}');
        \$this->renderPage('{$method}');
    }
PHP;
                $controllerContent = preg_replace('/}\s*$/', $newMethod . "\n}\n", $controllerContent);
                file_put_contents($controllerFile, $controllerContent);
                echo "Methode '{$method}' zum Controller hinzugefügt.\n";
            } else {
                // Methode existiert → loadModel einfügen falls fehlt
                if (!str_contains($controllerContent, "\$this->loadModel('{$method}')")) {
                    $controllerContent = preg_replace(
                            '/(public function ' . preg_quote($method, '/') . '\s*\(\)\s*\{)/',
                            "$1\n        \$model = \$this->loadModel('{$method}');",
                            $controllerContent
                    );
                    file_put_contents($controllerFile, $controllerContent);
                    echo "Methode '{$method}' im Controller um loadModel erweitert.\n";
                }
            }
        }
    }

    echo "Update abgeschlossen für Controller '$controllerName' im Modul '$module'.\n";
    exit;
}

/**
 * --- Normalfall (Controller + Model + Views) ---
 */
// Controller erstellen, falls noch nicht vorhanden
if (!file_exists($controllerFile)) {
    $controllerContent = <<<PHP
<?php

class {$controllerName} extends ckvsoft\mvc\BaseController
{

   public function __construct()
    {
        parent::__construct();
    }

    private function render(\$view, \$data = null)
    {
        // Menü laden
        \$pmwh3menuHelper = \$this->loadHelper("pmwh3/pmwh3menu");
        \$pmwh3menu = \$pmwh3menuHelper->getMenu(\$data['activeBox'] ?? null);

        \$this->renderPage([
            ['view' => '/inc/header', 'data' => ['title' => 'Events']],
            ['view' => 'pmwh3/inc/navigation', 'data' => ['menu' => \$pmwh3menu]],
            ['view' => \$view, 'data' => ['data' => \$data]],
            ['view' => '/inc/footer'],
                ],
                "<style>" . \$this->loadHelper("css", ['method' => 'getCss', 'args' => ['inc/css/pmwh3.css']]) . "</style>",
                "<script>" . \$this->loadScript("inc/js/pmwh3.js") . "</script>"
        );
    }

    public function index()
    {
        \$this->render('{$controllerNameLower}/index');
    }
}
PHP;

    file_put_contents($controllerFile, $controllerContent);
    echo "Controller erstellt: $controllerFile\n";
}

// Model erstellen, falls nicht nur View
if (!$onlyView) {
    $modelFile = "{$modelDir}/" . strtolower($controllerName) . "_model.php";
    if (!file_exists($modelFile)) {
        $modelContent = <<<PHP
<?php

class {$controllerName}_model extends ckvsoft\mvc\Model
{

   public function __construct()
    {
        parent::__construct();
    }

    public function getAll()
    {
        return [];
    }
}
PHP;
        file_put_contents($modelFile, $modelContent);
        echo "Model erstellt: $modelFile\n";
    }
}

// Views + Methoden
foreach ($methods as $method) {
    $method = strtolower($method);

    // View erstellen
    $viewFile = "$viewDir/$method.php";
    if (!file_exists($viewFile)) {
        $viewContent = <<<HTML
<div class="pmwh3-content">
    <div class="widget">
        <h2>{$controllerName} / {$method} View</h2>
        <table class="widget-table">
            <tr>
                <td class="widget-key">Key</td>
                <td class="widget-value">Value</td>
            </tr>
        </table>
    </div>
</div>
HTML;
        file_put_contents($viewFile, $viewContent);
        echo "View erstellt: $viewFile\n";
    }

    // Controller um Methode erweitern
    $controllerContent = file_get_contents($controllerFile);
    if (!preg_match('/function\s+' . preg_quote($method, '/') . '\s*\(/', $controllerContent)) {
        $newMethod = <<<PHP

    public function {$method}()
    {
        \$this->render('{$method}');
    }
PHP;
        $controllerContent = preg_replace('/}\s*$/', $newMethod . "\n}\n", $controllerContent);
        file_put_contents($controllerFile, $controllerContent);
        echo "Methode '{$method}' zum Controller hinzugefügt.\n";
    }
}

echo "Update abgeschlossen für Controller '$controllerName' im Modul '$module'.\n";
