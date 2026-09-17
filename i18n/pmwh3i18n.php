<?php

namespace pmwh3\I18n;

class Pmwh3I18n extends \ckvsoft\mvc\Config
{

    protected static ?string $currentLang = null;

    public static function init(?string $lang = null)
    {
        // -------------------------
        // 1. Sprache bestimmen
        // -------------------------
        if ($lang === null) {
            $language = \ckvsoft\Session::getNS('pmwh3', 'customer_language');
            error_log('DEBUG pmwh3 lang: ' . $lang);
            error_log('DEBUG pmwh3 user lang: ' . $language);
            if (session_status() === PHP_SESSION_ACTIVE && $language) {
                $lang = $language;
            } elseif (!empty(self::$sharedDb)) {
                try {
                    // NO db-name prefix: the sharedDb connection already
                    // points at the pmwh3 module database -- a hardcoded
                    // 'pmwh3.' schema name fails on every deployment
                    // whose module DB is not literally named 'pmwh3'.
                    $row = self::$sharedDb->selectOne(
                            "SELECT configuration_value FROM pmwh3_configuration WHERE configuration_key = :key LIMIT 1",
                            ['key' => 'DEFAULT_LANGUAGE']
                    );
                    $lang = ($row['configuration_value'] ?? null) ?: null;
                } catch (\Exception $e) {
                    error_log('WARNING pmwh3 database: ' . $e->getMessage());
                    $lang = null;
                }
            }

            if ($lang === null) {
                $lang = 'en_GB';
            }
        }

        self::$currentLang = $lang;

        // -------------------------
        // 2. Locale-Verzeichnis
        // -------------------------
        $localeDir = realpath(__DIR__ . '/locale');
        $domain = 'pmwh3';
        error_log('Debug: pmwh3 localeDir: ' . $localeDir);

        // -------------------------
        // 3. Locale plattformabhängig setzen
        // -------------------------
        $localeSet = false;
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // Windows-Locale
            $windowsLocales = [
                'de_DE' => 'German_Germany.1252',
                'en_US' => 'English_United States.1252',
                'en_GB' => 'English_United Kingdom.1252',
                'fi_FI' => 'Finnish_Finland.1252',
                'it_IT' => 'Italian_Italy.1252',
                'nl_NL' => 'Dutch_Netherlands.1252',
                'pl_PL' => 'Polish_Poland.1250'
            ];
            $winLocale = $windowsLocales[$lang] ?? 'English_United States.1252';
            $localeSet = setlocale(LC_ALL, $winLocale) !== false;
        } else {
            // Linux/Unix
            $localesToTry = [
                $lang . '.UTF-8',
                $lang . '.utf8',
                $lang
            ];
            foreach ($localesToTry as $l) {
                if (setlocale(LC_ALL, $l) !== false) {
                    $localeSet = true;
                    error_log('Info: pmwh3 set locale: ' . $l);
                    break;
                }
            }
        }

        // Fallback auf Englisch, falls Locale nicht gesetzt werden konnte
        if (!$localeSet) {
            $fallback = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? 'English_United States.1252' : 'en_US.UTF-8';
            setlocale(LC_ALL, $fallback);
            error_log('Error: pmwh3 fallback locale: ' . $fallback);
        }

        // -------------------------
        // 4. Gettext konfigurieren
        // -------------------------
        $activeLocale = setlocale(LC_ALL, 0);
        putenv("LC_ALL=$activeLocale");
        putenv("LANG=$activeLocale");
        putenv("LANGUAGE=$activeLocale");

        bindtextdomain($domain, $localeDir);
        bind_textdomain_codeset($domain, "UTF-8");
        textdomain($domain);

        // -------------------------
        // Optional: Debug
        // -------------------------
        /*
          var_dump('Active Locale:', setlocale(LC_ALL, 0));
          var_dump('Domain path:', bindtextdomain($domain, $localeDir));
          var_dump('Current textdomain:', textdomain(null));
         */
    }

    // -------------------------
    // Gibt die tatsächlich aktive Sprache zurück
    // -------------------------
    public static function getCurrentLang(): string
    {
        if (self::$currentLang === null) {
            return 'en_GB';
        }

        // Aktuell aktive Locale vom System holen
        $locale = setlocale(LC_ALL, 0);

        // Encoding entfernen, nur Sprache_Land zurückgeben
        $locale = preg_replace('/(\.UTF-8|\.utf8|\.1252|\.1250)$/i', '', $locale);

        return $locale ?: self::$currentLang;
    }
}
