<?php

namespace pmwh3\Utils;

use ckvsoft\CkvException;
use pmwh3\Config\LazyConfig;
use pmwh3\Utils\Fs\FsAdapterInterface;
use pmwh3\Utils\Fs\LocalFsAdapter;

/**
 * Filesystem facade.
 *
 * High-level operations (resolve webroot, create domain directory, ...)
 * live here, low-level mkdir/rmdir is delegated to the configured adapter.
 *
 * Configurable via LazyConfig:
 *   FS_TYPE           -> 'local' (default). Future: 'daemon'.
 *   WEBROOT           -> e.g. '/vhome'
 *   STRUCTURE_WEBROOT -> e.g. '[CUSTOMER]/[DOMAIN]/htdocs'
 *   CREATE_DIRECTORIES -> 'Y' / 'N' (master switch)
 *   CREATE_INDEXFILE  -> name of index file to drop into new dirs ('' = none)
 */
class FsManager
{

    /**
     * Returns the configured adapter class.
     */
    private static function getAdapterClass(): string
    {
        $type = strtolower((string) LazyConfig::get('FS_TYPE', 'local'));
        $map = [
            'local' => LocalFsAdapter::class,
            // future: 'daemon' => DaemonFsAdapter::class,
        ];
        if (!isset($map[$type])) {
            throw new CkvException("FsManager: unknown adapter type '{$type}'");
        }
        return $map[$type];
    }

    public static function isEnabled(): bool
    {
        return strtoupper((string) LazyConfig::get('CREATE_DIRECTORIES', 'Y')) === 'Y';
    }

    /**
     * Resolve the webroot for a given customer/domain combination.
     * Returns absolute path with trailing slash stripped.
     */
    public static function resolveWebroot(string $customer, string $domain): string
    {
        $base = rtrim((string) LazyConfig::get('WEBROOT', '/vhome'), '/');
        $structure = trim((string) LazyConfig::get('STRUCTURE_WEBROOT', '[CUSTOMER]/[DOMAIN]/htdocs'), '/');

        $structure = str_replace(
                ['[CUSTOMER]', '[DOMAIN]'],
                [$customer, $domain],
                $structure
        );

        return $base . '/' . $structure;
    }

    public static function mkdir(string $path, int $mode = 0755): bool
    {
        if (!self::isEnabled()) {
            return true; // master switch off -> caller pretends success
        }
        $cls = self::getAdapterClass();
        return $cls::mkdir($path, $mode, true);
    }

    public static function rmdir(string $path): bool
    {
        if (!self::isEnabled()) {
            return true;
        }
        $cls = self::getAdapterClass();
        return $cls::rmdir($path, true);
    }

    public static function rename(string $from, string $to): bool
    {
        if (!self::isEnabled()) {
            return true;
        }
        $cls = self::getAdapterClass();
        return $cls::rename($from, $to);
    }

    public static function exists(string $path): bool
    {
        $cls = self::getAdapterClass();
        return $cls::exists($path);
    }

    /**
     * Recursive size of a path in bytes. Null when the FS adapter
     * can't compute it or the path doesn't exist.
     */
    public static function dirSize(string $path): ?int
    {
        try {
            $cls = self::getAdapterClass();
            return $cls::dirSize($path);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Create the webroot for a domain and (optionally) drop a placeholder
     * index file so a fresh install isn't an empty directory.
     */
    public static function createWebroot(string $customer, string $domain): bool
    {
        if (!self::isEnabled()) {
            return true;
        }

        $path = self::resolveWebroot($customer, $domain);
        if (!self::mkdir($path)) {
            return false;
        }

        self::writeIndexFile($path, $domain);

        return true;
    }

    /**
     * Drop the configured index file into $dir.
     *
     * Filename comes from CREATE_INDEXFILE ('' = skip).
     * Content comes from CONTENT_INDEXFILE if non-empty,
     * otherwise a built-in "under construction" fallback.
     */
    public static function writeIndexFile(string $dir, string $label = ''): void
    {
        if (!self::isEnabled()) {
            return;
        }
        $indexName = trim((string) LazyConfig::get('CREATE_INDEXFILE', ''));
        if ($indexName === '') {
            return;
        }
        $content = trim((string) LazyConfig::get('CONTENT_INDEXFILE', ''));
        if ($content === '') {
            $content = "<!doctype html><meta charset=\"utf-8\">"
                    . "<title>" . htmlspecialchars($label) . "</title>"
                    . "<h1>" . htmlspecialchars($label) . "</h1>"
                    . "<p>This site is under construction.</p>";
        }
        $cls = self::getAdapterClass();
        $cls::writeFile(rtrim($dir, '/') . '/' . $indexName, $content);
    }

    public static function deleteWebroot(string $customer, string $domain): bool
    {
        if (!self::isEnabled()) {
            return true;
        }
        $path = self::resolveWebroot($customer, $domain);
        return self::rmdir($path);
    }
}
