<?php

namespace pmwh3\Config;

class Version
{

    // Statische Konstante
    public const VERSION = '3.0.82-260917';

    // Optional: Getter-Methode
    public static function getVersion(): string
    {
        return self::VERSION;
    }
}
