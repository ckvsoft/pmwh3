<?php

namespace pmwh3\Utils;

/**
 * Central password policies.
 *
 * PASSWORD_LENGTH (default 8) drives both the length of generated
 * passwords and the minimum length enforced for passwords chosen in
 * forms (never below 6).
 */
class PasswordUtil
{

    /** Hard lower bound, so a misconfigured setting can't shorten below 6. */
    public const MIN_FLOOR = 6;

    /**
     * Effective minimum password length: max(6, PASSWORD_LENGTH).
     */
    public static function minLength(): int
    {
        return max(self::MIN_FLOOR, (int) \pmwh3\Config\LazyConfig::get('PASSWORD_LENGTH', 8));
    }

    /**
     * The configured generated-password length (PASSWORD_LENGTH,
     * fallback 8). Never below MIN_FLOOR.
     */
    public static function defaultLength(): int
    {
        return max(self::MIN_FLOOR, (int) \pmwh3\Config\LazyConfig::get('PASSWORD_LENGTH', 8));
    }

    /**
     * True when the supplied password meets the configured minimum length.
     */
    public static function isValidLength(string $password): bool
    {
        return strlen($password) >= self::minLength();
    }

    /**
     * Generate a strong random password (CSPRNG).
     *
     * @param int|null $length Bytes / characters; null = PASSWORD_LENGTH.
     */
    public static function generate(?int $length = null): string
    {
        $length = $length !== null ? max(self::MIN_FLOOR, $length) : self::defaultLength();
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()';
        $password = '';
        $max = strlen($chars) - 1;
        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, $max)];
        }
        return $password;
    }
}