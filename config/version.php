<?php

namespace pmwh3\Config;

class Version
{

    // Statische Konstante
    public const VERSION = '3.0.84-261004';

    // Optional: Getter-Methode
    public static function getVersion(): string
    {
        return self::VERSION;
    }
}
