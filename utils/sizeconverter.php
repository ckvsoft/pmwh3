<?php

namespace pmwh3\Utils;

class SizeConverter
{

    /**
     * Convert bytes to human-readable string
     *
     * @param int|null $bytes
     * @param bool|null $si true = 1000-based units, false = 1024-based units
     * @param int $decimals Number of decimals
     * @return string
     */
    public static function bytesToHumanReadable(?int $bytes, ?bool $si = null, int $decimals = 2): string
    {
        if (!$bytes || $bytes < 0) {
            return '0 B';
        }

        // Load SI standardization from config if not explicitly provided
        if ($si === null) {
            $si = (\pmwh3\Config\LazyConfig::get('SI_STANDARDIZATION', 'N') === 'Y');
        }

        // Select units and base
        if ($si) {
            $units = ['B', 'kB', 'MB', 'GB', 'TB', 'PB'];
            $mod = 1000;
        } else {
            $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'];
            $mod = 1024;
        }

        $factor = 0;
        $maxFactor = count($units) - 1;

        // Use bcmath if available for large numbers
        if (extension_loaded('bcmath')) {
            while ($bytes >= $mod && $factor < $maxFactor) {
                $bytes = bcdiv((string) $bytes, (string) $mod, $decimals + 2);
                $factor++;
            }
        } else {
            while ($bytes >= $mod && $factor < $maxFactor) {
                $bytes /= $mod;
                $factor++;
            }
        }

        return sprintf("%.{$decimals}f %s", $bytes, $units[$factor]);
    }

    /**
     * Convert human-readable string back to bytes
     *
     * @param string $size
     * @return int
     * @throws \InvalidArgumentException
     */
    public static function humanReadableToBytes(string $size): int
    {
        $size = trim($size);
        if ($size === '')
            return 0;

        // Map units to exponent
        $units = [
            'B' => 0, 'kB' => 1, 'MB' => 2, 'GB' => 3, 'TB' => 4, 'PB' => 5,
            'KiB' => 1, 'MiB' => 2, 'GiB' => 3, 'TiB' => 4, 'PiB' => 5
        ];

        if (!preg_match('/([\d\.]+)\s*([KMGTPE]i?B)/i', $size, $matches)) {
            throw new \InvalidArgumentException("Invalid size: $size");
        }

        $value = (float) $matches[1];
        $unit = $matches[2];

        if (!isset($units[$unit])) {
            throw new \InvalidArgumentException("Unknown unit: $unit");
        }

        $exp = $units[$unit];
        $mod = (strpos($unit, 'i') !== false) ? 1024 : 1000;

        if (extension_loaded('bcmath')) {
            return (int) bcmul((string) $value, bcpow((string) $mod, (string) $exp));
        }

        return (int) round($value * ($mod ** $exp));
    }
}
