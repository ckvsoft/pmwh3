<?php

namespace pmwh3\Utils;

use ckvsoft\Database;
use ckvsoft\mvc\Config;
use pmwh3\Config\LazyConfig;

/**
 * PHP-side database dump/restore for pmwh3 (no mysqldump in the
 * php84_fpm container). Writes to a SHADOW directory (BACKUP_DIR
 * setting; never inside the source tree per AGENTS.md).
 *
 * Dump format = plain SQL (CREATE IF NOT EXISTS + INSERT batches),
 * restore replays the statements. File name: pmwh3_full_<date>.sql.
 */
class BackupUtil
{

    public static function dir(): string
    {
        return rtrim((string) LazyConfig::get(
                        'BACKUP_DIR',
                        '/srv/docker/pmwh3-backup'
                ), '/\\');
    }

    private static function ensureDir(): string
    {
        $dir = self::dir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            throw new \RuntimeException("BACKUP_DIR '{$dir}' is not writable");
        }
        return $dir;
    }

    /** @return array rows name/date/size sorted desc */
    public static function listFiles(): array
    {
        $dir = self::dir();
        if (!is_dir($dir)) {
            return [];
        }
        $files = glob($dir . '/*.sql') ?: [];
        usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
        $out = [];
        foreach ($files as $f) {
            $out[] = [
                'name' => basename($f),
                'date' => date('Y-m-d H:i', filemtime($f)),
                'size' => (int) (filesize($f) / 1024),
            ];
        }
        return $out;
    }

    /**
     * Dump the whole module database (structure per table with
     * SHOW CREATE + data INSERTs). Returns file name or throws.
     */
    public static function backup(): string
    {
        $dir = self::ensureDir();
        $db  = Config::moduleDb();
        $tables = $db->showTables();
        if (!$tables) {
            throw new \RuntimeException('No tables found to back up');
        }

        $file = $dir . '/pmwh3_full_' . date('Ymd-His') . '.sql';
        $fh = fopen($file, 'w');
        fwrite($fh, "-- pmwh3 backup " . date('c') . "\n");
        foreach ($tables as $table) {
            $table = (string) $table;
            if ($table === '') {
                continue;
            }
            $escaped = '`' . str_replace('`', '``', $table) . '`';
            fwrite($fh, "\n-- table {$table}\n");

            $ddl = $db->showCreateTable($table);
            if ($ddl !== null) {
                $ddl = str_replace('CREATE TABLE', 'CREATE TABLE IF NOT EXISTS', $ddl);
                fwrite($fh, "DROP TABLE IF EXISTS `{$table}`;\n{$ddl};\n");
            }
            foreach ($db->select("SELECT * FROM `{$table}`", []) as $row) {
                $values = [];
                foreach ($row as $v) {
                    $values[] = $v === null ? 'NULL' : $db->quote((string) $v);
                }
                fwrite($fh, "INSERT INTO `{$table}` VALUES (" . implode(', ', $values) . ");\n");
            }
        }
        fclose($fh);
        return basename($file);
    }

    /**
     * Restore a stored dump (statement replay lives entirely in the
     * Database class: DROPs -> CREATEs -> INSERTs). Only files inside
     * BACKUP_DIR with a pmwh3_ name are accepted.
     */
    public static function restore(string $name): void
    {
        $dir  = self::dir();
        $real = realpath($dir . '/' . basename($name));
        if ($real === false || !str_starts_with($real, $dir)
                || basename($real) !== $name || !str_starts_with($name, 'pmwh3_')) {
            throw new \RuntimeException('Invalid backup file');
        }
        Config::moduleDb()->executeSqlFile($real);
    }

    public static function delete(string $name): bool
    {
        $name = basename(trim($name));
        if ($name === '' || !str_starts_with($name, 'pmwh3_')) {
            return false;
        }
        $real = realpath(self::dir() . '/' . $name);
        if ($real === false || !str_starts_with($real, self::dir())) {
            return false;
        }
        return @unlink($real);
    }
}
