<?php

namespace pmwh3\Utils;

use ckvsoft\mvc\Config;
use pmwh3\Config\LazyConfig;

/**
 * pmwh3 error logging runtime.
 *
 * Settings live in the Options section "Errorlog"
 * (SettingsSchema GROUP_ERRORLOG, all keys ERRORLOG_*).
 *
 *   ERRORLOG_TARGET        php | file | syslog | db
 *   php_settings.error_log_path  cevian config (target "file")
 *   ERRORLOG_LEVEL         minimum severity (debug..critical)
 *   ERRORLOG_SCOPE         multi_select: php, framework, app, database
 *   ERRORLOG_INCLUDE_TRACE Y/N
 *   ERRORLOG_RETAIN_DAYS   purge window for the db table (0 = forever)
 *
 * register() hooks error handler + exception handler + shutdown.
 * Handlers are fail-safe: any exception inside the logger is
 * suppressed (re-entrancy flag) so logging can never take the app
 * down. Register from modulautoload.php.
 */
class ErrorHandler
{

    private const LEVELS = [
        'debug' => 0, 'info' => 1, 'notice' => 2, 'warning' => 3,
        'error' => 4, 'critical' => 5,
    ];

    /** php error code -> (level, scope) */
    private const PHP_LEVEL_MAP = [
            E_WARNING => [3, 'warning'],
            E_NOTICE => [2, 'notice'],
            E_USER_ERROR => [4, 'error'],
            E_USER_WARNING => [3, 'warning'],
            E_USER_NOTICE => [2, 'notice'],
            E_RECOVERABLE_ERROR => [4, 'error'],
            E_DEPRECATED => [2, 'notice'],
            E_USER_DEPRECATED => [2, 'notice'],
        ];

    private static bool $registered = false;
    private static bool $busy = false;

    // ===== register =====================================================

    public static function register(): void
    {
        if (self::$registered || PHP_SAPI === 'cli') {
            return;
        }
        self::$registered = true;

        set_error_handler([self::class, 'handlePhpError']);
        set_exception_handler(function (\Throwable $e) {
            self::logException($e);
            // Chain: restore default behaviour for real exceptions by
            // re-throwing once handlers take over (PHP prints brightly
            // otherwise). Always exit non-zero-like for uncaught ones.
            self::shutdownFatalPage($e);
        });
        register_shutdown_function([self::class, 'handleShutdown']);
    }

    // ===== handlers =====================================================

    /** @return bool true = PHP's internal handler is suppressed */
    public static function handlePhpError(int $severity, string $message, string $file, int $line): bool
    {
        // Respect @-suppression (Zend engine wires severity 0 + error_reporting)
        if (!(error_reporting() & $severity)) {
            return true;
        }
        $entry = self::PHP_LEVEL_MAP[$severity] ?? [3, 'warning'];
        self::write($message, $entry[1], 'php', $file, $line, null);

        // Do NOT return false -- operators get the unified log, the
        // doubled default handler output would only add noise.
        return true;
    }

        public static function logException(\Throwable $e): void
    {
        self::write($e->getMessage(), self::severityOf($e), 'app',
                $e->getFile(), $e->getLine(), $e);
    }

    /** Throwable -> log level via its hierarchy. */
    public static function severityOf(\Throwable $e): string
    {
        if ($e instanceof \Error) {
            return 'critical';
        }
        if ($e instanceof \RuntimeException) {
            return 'error';
        }
        if ($e instanceof \LogicException || $e instanceof \DomainException) {
            return 'error';
        }
        if ($e instanceof \InvalidArgumentException) {
            return 'warning';
        }
        return 'warning';
    }
    /** Catches the last error (typically fatal ones PHP can't route). */
    public static function handleShutdown(): void
    {
        $e = error_get_last();
        if ($e === null || !in_array($e['type'],
                        [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            return;
        }
        self::write((string) $e['message'], 'critical', 'php',
                (string) ($e['file'] ?? ''), (int) ($e['line'] ?? 0), null);
    }

    private static function shutdownFatalPage(\Throwable $e): void
    {
        if (ob_get_level() > 0) {
            @ob_end_clean();
        }
        http_response_code(500);
        echo '<p style="font-family:sans-serif">'
                . htmlspecialchars('Internal error: ' . $e->getMessage(), ENT_QUOTES)
                . '</p>';
        exit(1);
    }

    // ===== public API for app/framework scopes ==========================

    /**
     * Framework/app flow trace entry point (replaces
     * Config::logDebug inside pmwh3: same debug semantics, but routed
     * through the configurable ERRORLOG_* pipeline so the info is
     * visible in the Errorlog viewer instead of vanishing in
     * var/log/error.log). Level/min + scope filtering apply.
     */
    public static function trace(string $message): void
    {
        self::log('debug', $message, 'framework');
    }

    public static function log(
            string $level,
            string $message,
            string $scope = 'app',
            ?\Throwable $e = null
    ): void {
        $frame = $e !== null ? $e : null;
        self::write($message, $level, $scope, $frame?->getFile() ?? '',
                $frame?->getLine() ?? 0, $frame);
    }

    // ===== write + storage ==============================================

    private static function write(
            string $message, string $level, string $scope,
            string $file, int $line, ?\Throwable $e
    ): void {
        if (self::$busy) {
            return;
        }
        self::$busy = true;
        try {
            $min = (int) (self::LEVELS[(string) LazyConfig::get('ERRORLOG_LEVEL', 'warning')] ?? 3);
            if ((int) (self::LEVELS[$level] ?? 3) < $min) {
                return;
            }
            $scopes = preg_split('/\s*,\s*/',
                    (string) LazyConfig::get('ERRORLOG_SCOPE', 'app,framework,php')) ?: [];
            if (!in_array($scope, $scopes, true)) {
                return;
            }

            $trace = strtoupper((string) LazyConfig::get('ERRORLOG_INCLUDE_TRACE', 'Y')) === 'Y'
                    && $e !== null ? "\n" . $e->getTraceAsString() : '';
            $text = '[' . date('Y-m-d H:i:s') . '] [' . strtoupper($level) . '] ['
                    . $scope . '] '
                    . rtrim($message, "\n")
                    . ($file !== '' ? " (" . $file . ':' . $line . ")" : '')
                    . $trace . "\n";

            switch ((string) LazyConfig::get('ERRORLOG_TARGET', 'file')) {
                case 'php':
                    @error_log(rtrim($text, "\n"));
                    break;
                case 'syslog':
                    @openlog('pmwh3', LOG_ODELAY, LOG_USER);
                    @syslog(self::syslogLevel($level), rtrim($text, "\n"));
                    @closelog();
                    break;
                case 'db':
                    self::writeDb($level, $message, $file, $line, trim($trace), $scope);
                    break;
                case 'file':
                default:
                    self::writeFile($text);
                    break;
            }
        } catch (\Throwable $ignored) {
            // never let the logger break the app
        } finally {
            self::$busy = false;
        }
    }

    /**
     * Test hook: when set, the "file" target writes/reads/tails THIS
     * path instead of the cevian-configured log file (isolation of
     * the tests -- no ENV / DB writes).
     */
    public static ?string $fileOverride = null;

    /**
     * The "file" target path is NOT a pmwh3 setting: it comes from the
     * cevian deployment config (config/app.json / app_defaults.json ->
     * php_settings.error_log_path, default "var/log/error.log"),
     * relative paths resolve against the cevian deployment root --
     * exactly like index.php does for the framework's own log.
     * pmwh3 errorlog entries therefore land in the same file the
     * framework uses.
     */
    public static function logFilePath(): string
    {
        if (self::$fileOverride !== null && self::$fileOverride !== '') {
            return self::$fileOverride;
        }
        $path = (string) (\ckvsoft\mvc\Config::get('php_settings.error_log_path') ?? '');
        return self::resolvePath($path !== '' ? $path : 'var/log/error.log');
    }

    private static function writeFile(string $text): void
    {
        $path = self::logFilePath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($path, $text, FILE_APPEND | LOCK_EX);
    }

    /**
     * Mirrors index.php exactly: the framework builds its error log
     * path as __DIR__ . $phpSettings['error_log_path'] (concats even
     * when the configured path starts with '/'). Relative paths
     * therefore resolve against the cevian deployment root either way.
     */
    public static function resolvePath(string $path): string
    {
        if ($path === '') {
            $path = 'var/log/error.log';
        }
        // __DIR__ = <root>/modules/pmwh3/utils -> root is 3 levels up
        return dirname(__DIR__, 3) . '/' . ltrim($path, '/');
    }

    private static function writeDb(
            string $level, string $message, string $file, int $line, string $trace, string $scope = 'php'
    ): void {
        $db = Config::moduleDb();
        if (!$db->tableExists('pmwh3_errorlog')) {
            $db->execDdl(<<<SQL
CREATE TABLE IF NOT EXISTS `pmwh3_errorlog` (
    `id`       BIGINT      NOT NULL AUTO_INCREMENT,
    `ts`       DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `level`    VARCHAR(16) NOT NULL DEFAULT '',
    `message`  TEXT        NOT NULL,
    `file`     VARCHAR(255)         DEFAULT '',
    `line`     INT         NOT NULL DEFAULT 0,
    `scope`    VARCHAR(32) NOT NULL DEFAULT 'php',
    `trace`    MEDIUMTEXT  DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY idx_level_ts (`level`, `ts`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci

SQL);
        }
        $db->insert('pmwh3_errorlog', [
            'level'   => $level,
            'message' => substr($message, 0, 6000),
            'file'    => substr($file, 0, 250),
            'line'    => $line,
            'scope'   => $scope,
            'trace'   => $trace !== '' ? substr($trace, 0, 6000) : null,
        ]);
    }

    private static function syslogLevel(string $level): int
    {
        return match ($level) {
            'critical' => LOG_CRIT,
            'error'    => LOG_ERR,
            'notice'   => LOG_NOTICE,
            'info'     => LOG_INFO,
            'debug'    => LOG_DEBUG,
            default    => LOG_WARNING,
        };
    }

    // ===== viewer helpers (errorlog section) ============================

    /** Recent entries for the viewer when the target is the database. */
    public static function latest(int $limit = 50): array
    {
        $db = Config::moduleDb();
        if (!$db->tableExists('pmwh3_errorlog')) {
            return [];
        }
        return $db->select(
                "SELECT id, ts, level, message, file, line, scope
                   FROM pmwh3_errorlog
               ORDER BY id DESC LIMIT "
                . max(1, min(500, $limit)));
    }

    /** Last $lines entries of the errorlog file (viewer, target=file). */
    public static function tail(int $lines = 100): string
    {
        $path = self::logFilePath();
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            return '';
        }
        // Read a bounded window from the end (no shell_exec dependency).
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return '';
        }
        $size = filesize($path);
        $offset = $size > $lines * 600 ? $size - $lines * 600 : 0;
        @fseek($fh, $offset, SEEK_SET);
        $chunk = (string) @stream_get_contents($fh);
        @fclose($fh);
        if ($chunk === '') {
            return '';
        }
        // Skip up to the next line break only when we cut into a line
        // at the front (offset > 0). At offset 0 the first line is
        // complete already.
        $start = $offset > 0 ? strpos($chunk, "\n") + 1 : 0;
        $body = rtrim(substr($chunk, $start));
        if ($body === '') {
            return '';
        }
        $lines = array_slice(explode("\n", $body), -$lines);
        return implode("\n", $lines);
    }

    /**
     * Purge old entries. Target db: deletes rows older than
     * ERRORLOG_RETAIN_DAYS. Target file: rewrites the file keeping
     * only lines within the retention window (every entry line starts
     * with a "[YYYY-mm-dd HH:ii:ss]" timestamp; lines without a
     * parsable stamp -- e.g. stack-trace continuations -- are kept
     * iff the entry directly above them was kept). Other targets:
     * no-op.
     */
    public static function purgeOld(): int
    {
        $target = (string) LazyConfig::get('ERRORLOG_TARGET', 'file');
        if ($target === 'file') {
            $path = self::logFilePath();
            if ($path === '' || !is_file($path) || !is_writable($path)) {
                return 0;
            }
            $days = (int) LazyConfig::get('ERRORLOG_RETAIN_DAYS', '30');
            if ($days <= 0) {
                return 0;
            }
            $cutoff = new \DateTime('-' . $days . ' days');
            $fh = @fopen($path, 'r+');
            if ($fh === false) {
                return 0;
            }
            @flock($fh, LOCK_EX);
            $lines  = (array) explode("\n", (string) @stream_get_contents($fh));
            $dropped = false;
            $kept   = [];
            $out    = 0;
            foreach ($lines as $line) {
                if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $m)) {
                    try {
                        $ts = new \DateTime($m[1]);
                        $dropped = $ts < $cutoff;
                    } catch (\Throwable $ignored) {
                        // undated header lines survive as-is
                    }
                }
                if ($dropped) {
                    $out++;
                } else {
                    $kept[] = $line;
                }
            }
            if ($out > 0) {
                @ftruncate($fh, 0);
                @rewind($fh);
                @fwrite($fh, rtrim(implode("\n", $kept))
                        . (count($kept) > 0 ? "\n" : ''));
            }
            @flock($fh, LOCK_UN);
            @fclose($fh);
            return $out;
        }
        if ($target !== 'db') {
            return 0;
        }
        $db = Config::moduleDb();
        if (!$db->tableExists('pmwh3_errorlog')) {
            return 0;
        }
        $days = (int) LazyConfig::get('ERRORLOG_RETAIN_DAYS', '30');
        if ($days <= 0) {
            return 0;
        }
        return $db->delete('pmwh3_errorlog',
                'ts < NOW() - INTERVAL :d DAY', ['d' => $days]);
    }
}
