<?php

namespace pmwh3\Utils\Fs;

/**
 * Filesystem operations needed by pmwh3 (web roots, customer dirs, ...).
 *
 * The current implementation is a direct local-fs adapter; later a
 * daemon-backed adapter (running as root via a unix socket) can plug
 * in here without touching call sites.
 */
interface FsAdapterInterface
{

    // ===== Discovery (for AdapterRegistry / option_providers) ===========

    /** Short identifier persisted in the FS_TYPE setting. */
    public static function getKey(): string;

    /** Human-readable name shown in the options dropdown. */
    public static function getName(): string;

    /**
     * Runtime availability check. Adapters returning false are filtered
     * out of the dropdown.
     */
    public static function isAvailable(): bool;

    // ===== FS operations ===============================================

    public static function mkdir(string $path, int $mode = 0755, bool $recursive = true): bool;

    public static function rmdir(string $path, bool $recursive = true): bool;

    public static function rename(string $from, string $to): bool;

    public static function exists(string $path): bool;

    /**
     * Optional ownership change. May be a no-op if the process can't chown.
     */
    public static function chown(string $path, ?string $user, ?string $group, bool $recursive = false): bool;

    /**
     * Optional: recursive size of a directory in bytes (du equivalent).
     * Return null when the adapter can't compute it (backend-managed FS).
     */
    public static function dirSize(string $path): ?int;

    /**
     * Write a file (used e.g. for placeholder index.html).
     */
    public static function writeFile(string $path, string $content, int $mode = 0644): bool;
}
