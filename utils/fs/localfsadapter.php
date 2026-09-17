<?php

namespace pmwh3\Utils\Fs;

/**
 * Direct local filesystem adapter. Runs as the PHP process user.
 *
 * Limitation: cannot chown to arbitrary users unless PHP runs as root.
 * Failures are returned as `false` (and logged), they do not throw,
 * so the caller can decide how strict to be.
 */
class LocalFsAdapter implements FsAdapterInterface
{

    public static function getKey(): string  { return 'local'; }
    public static function getName(): string { return 'Local filesystem'; }
    public static function isAvailable(): bool { return true; } // always available

    public static function mkdir(string $path, int $mode = 0755, bool $recursive = true): bool
    {
        if (is_dir($path)) {
            return true;
        }

        $old = umask(0);
        try {
            $ok = @mkdir($path, $mode, $recursive);
        } finally {
            umask($old);
        }

        if (!$ok && !is_dir($path)) {
            error_log("LocalFsAdapter::mkdir failed for {$path}");
            return false;
        }
        return true;
    }

    public static function rmdir(string $path, bool $recursive = true): bool
    {
        if (!file_exists($path)) {
            return true;
        }
        if (!is_dir($path)) {
            return false;
        }

        if (!$recursive) {
            return @rmdir($path);
        }

        $items = @scandir($path);
        if ($items === false) {
            return false;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $sub = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($sub) && !is_link($sub)) {
                if (!self::rmdir($sub, true)) {
                    return false;
                }
            } else {
                if (!@unlink($sub)) {
                    error_log("LocalFsAdapter::rmdir unlink failed for {$sub}");
                    return false;
                }
            }
        }

        if (!@rmdir($path)) {
            error_log("LocalFsAdapter::rmdir failed for {$path}");
            return false;
        }
        return true;
    }

    public static function rename(string $from, string $to): bool
    {
        if (!file_exists($from)) {
            return false;
        }
        // Ensure parent of $to exists.
        $parent = dirname($to);
        if (!is_dir($parent)) {
            self::mkdir($parent);
        }
        return @rename($from, $to);
    }

    public static function exists(string $path): bool
    {
        return file_exists($path);
    }

    public static function chown(string $path, ?string $user, ?string $group, bool $recursive = false): bool
    {
        if (!file_exists($path)) {
            return false;
        }

        $ok = true;
        if ($user !== null) {
            $ok = @chown($path, $user) && $ok;
        }
        if ($group !== null) {
            $ok = @chgrp($path, $group) && $ok;
        }

        if ($recursive && is_dir($path)) {
            $items = @scandir($path);
            if ($items !== false) {
                foreach ($items as $item) {
                    if ($item === '.' || $item === '..') {
                        continue;
                    }
                    self::chown($path . DIRECTORY_SEPARATOR . $item, $user, $group, true);
                }
            }
        }
        return $ok;
    }

    public static function writeFile(string $path, string $content, int $mode = 0644): bool
    {
        $parent = dirname($path);
        if (!is_dir($parent)) {
            if (!self::mkdir($parent)) {
                return false;
            }
        }
        if (@file_put_contents($path, $content) === false) {
            error_log("LocalFsAdapter::writeFile failed for {$path}");
            return false;
        }
        @chmod($path, $mode);
        return true;
    }

    /** Recursive du in bytes. Dirs count their entry sizes (4096-ish). */
    public static function dirSize(string $path): ?int
    {
        if (!is_dir($path)) {
            return null;
        }
        $total = 0;
        $items = @scandir($path);
        if ($items === false) {
            return null;
        }
        try {
            $it = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($path,
                            \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($it as $f) {
                $s = @filesize($f->getPathname());
                if ($s !== false) {
                    $total += $s;
                }
            }
        } catch (\Throwable $e) {
            return null;
        }
        return $total;
    }
}
