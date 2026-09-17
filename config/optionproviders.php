<?php

namespace pmwh3\Config;

/**
 * Dynamic option providers for select-type settings.
 *
 * Each public static method here returns an associative array
 * (value => label) and is referenced by name from SettingsSchema
 * via the 'options_fn' key.
 */
class OptionProviders
{

    /** Resolve a provider name (from SettingsSchema['options_fn']). */
    public static function resolve(string $name): array
    {
        if (!is_callable([self::class, $name])) {
            return [];
        }
        try {
            return (array) self::$name();
        } catch (\Throwable $e) {
            error_log("OptionProviders::{$name} failed: " . $e->getMessage());
            return [];
        }
    }

    public static function languages(): array
    {
        $localeDir = __DIR__ . '/../i18n/locale';
        if (!is_dir($localeDir)) {
            return ['en_GB' => 'English'];
        }
        $out = [];
        foreach (scandir($localeDir) as $entry) {
            if ($entry === '.' || $entry === '..'
                    || !preg_match('/^[a-z]{2}_[A-Z]{2}$/', $entry)) {
                continue;
            }
            $langFile = $localeDir . '/' . $entry . '/language.txt';
            $label = is_file($langFile)
                    ? trim((string) file_get_contents($langFile))
                    : $entry;
            $out[$entry] = $label !== '' ? $label : $entry;
        }
        ksort($out);
        return $out ?: ['en_GB' => 'English'];
    }

    public static function encodings(): array
    {
        return [
            'utf-8'        => 'UTF-8',
            'iso-8859-1'   => 'ISO-8859-1 (Latin-1)',
            'iso-8859-15'  => 'ISO-8859-15 (Latin-9)',
            'windows-1252' => 'Windows-1252',
        ];
    }

    public static function templates(): array
    {
        $tmplDir = __DIR__ . '/../view/templates';
        if (!is_dir($tmplDir)) {
            return ['pmwh3' => 'pmwh3'];
        }
        $out = [];
        foreach (scandir($tmplDir) as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            if (is_dir($tmplDir . '/' . $entry)) {
                $out[$entry] = $entry;
            }
        }
        return $out ?: ['pmwh3' => 'pmwh3'];
    }

    public static function iconThemes(): array
    {
        $iconsDir = __DIR__ . '/../view/images/icons';
        if (!is_dir($iconsDir)) {
            return ['16x16-CC' => '16x16-CC'];
        }
        $out = [];
        foreach (scandir($iconsDir) as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $dir = $iconsDir . '/' . $entry;
            if (!is_dir($dir)) {
                continue;
            }
            // only themes that actually contain icon files (the bare
            // .gitkeep placeholder dirs are not selectable themes --
            // the menu renderer falls back to the flat icons/ root).
            foreach (glob($dir . '/*.{png,gif,jpg,svg}', GLOB_BRACE) ?: [] as $f) {
                if (is_file($f)) {
                    $out[$entry] = $entry;
                    break;
                }
            }
        }
        return $out ?: ['16x16-CC' => '16x16-CC'];
    }

    // ===== Backend adapter discovery =================================
    //
    // These delegate to AdapterRegistry::discoverWithStatus: UNAVAILABLE
    // adapters STAY in the list, annotated with their reason, so the
    // options UI shows WHY a backend is not usable (an empty dropdown
    // or a silently missing entry would leave the admin guessing).
    // Selecting an unavailable one fails cleanly via the adapter's
    // requireTables()/guards.

    public static function dnsAdapters(): array
    {
        return self::adapterOptions(
                \pmwh3\Utils\AdapterRegistry::discoverWithStatus(
                        __DIR__ . '/../utils/dns',
                        \pmwh3\Utils\Dns\DnsAdapterInterface::class
                )
        );
    }
    public static function mailAdapters(): array
    {
        return self::adapterOptions(
                \pmwh3\Utils\AdapterRegistry::discoverWithStatus(
                        __DIR__ . '/../utils/mail',
                        \pmwh3\Utils\Mail\MailAdapterInterface::class
                )
        );
    }
    public static function fsAdapters(): array
    {
        return self::adapterOptions(
                \pmwh3\Utils\AdapterRegistry::discoverWithStatus(
                        __DIR__ . '/../utils/fs',
                        \pmwh3\Utils\Fs\FsAdapterInterface::class
                )
        );
    }
    public static function webAdapters(): array
    {
        return self::adapterOptions(
                \pmwh3\Utils\AdapterRegistry::discoverWithStatus(
                        __DIR__ . '/../utils/web',
                        \pmwh3\Utils\Web\WebAdapterInterface::class
                )
        );
    }

    /**
     * Flatten discovery status into (key => label) option labels --
     * unavailable adapters keep their place with the reason appended.
     */
    private static function adapterOptions(array $status): array
    {
        $out = [];
        foreach ($status as $key => $s) {
            $out[$key] = $s['available']
                ? $s['name']
                : $s['name'] . ' (NOT available: ' . $s['reason'] . ')';
        }
        return $out;
    }
}
