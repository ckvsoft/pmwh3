<?php

namespace pmwh3\Utils;

/**
 * reCAPTCHA v2 + v3 glue for login / password-reset forms.
 *
 * CAPTCHA_TYPE determines whether captcha is active at all.
 * When the setting is 'none' or the required keys are missing,
 * all checks pass (fail-open — no lockout on misconfigured installs).
 *
 * Settings consumed:
 *   CAPTCHA_TYPE           none | recaptcha_v2 | recaptcha_v3
 *   RECAPTCHA_SITE_KEY     public key rendered in the HTML
 *   RECAPTCHA_SECRET_KEY   server-side key (never exposed)
 *   RECAPTCHA_SCORE        minimum score for v3 (float string, default 0.5)
 */
class CaptchaManager
{

    private const VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

    /** @return string captcha type or 'none' */
    public static function type(): string
    {
        return (string) \pmwh3\Config\LazyConfig::get('CAPTCHA_TYPE', 'none');
    }

    /** True when captcha should be enforced (type set AND keys present). */
    public static function isEnabled(): bool
    {
        $type = self::type();
        if ($type === 'none') {
            return false;
        }
        $siteKey    = self::siteKey();
        $secretKey  = self::secretKey();
        return $siteKey !== '' && $secretKey !== '';
    }

    /** Public site-key rendered in HTML. */
    public static function siteKey(): string
    {
        return (string) \pmwh3\Config\LazyConfig::get('RECAPTCHA_SITE_KEY', '');
    }

    private static function secretKey(): string
    {
        return (string) \pmwh3\Config\LazyConfig::get('RECAPTCHA_SECRET_KEY', '');
    }

    private static function scoreThreshold(): float
    {
        $raw = (string) \pmwh3\Config\LazyConfig::get('RECAPTCHA_SCORE', '0.5');
        $val = (float) $raw;
        // (float)'banana' → 0.0, which is in [0,1] — but the user
        // intended a non-numeric override.  Reject when the trimmed
        // string doesn't look like a float.
        if ($raw !== '' && !is_numeric($raw)) {
            return 0.5;
        }
        return ($val >= 0.0 && $val <= 1.0) ? $val : 0.5;
    }

    /**
     * Data required by the login view to render the captcha widget.
     *
     * Returns null when captcha is not active, an array otherwise:
     *   ['type' => 'recaptcha_v2'|'recaptcha_v3', 'siteKey' => '...']
     */
    public static function renderContext(): ?array
    {
        if (!self::isEnabled()) {
            return null;
        }
        return [
            'type'    => self::type(),
            'siteKey' => self::siteKey(),
        ];
    }

    /**
     * Server-side verification against Google.
     *
     * Fail-open: transport / parse / unexpected errors return true
     * so that a temporary Google outage never locks admins out of
     * the login page.
     *
     * @param string|null $token    $_POST['g-recaptcha-response']
     * @param string      $remoteIp client IP (optional, helps Google)
     */
    public static function verify(?string $token, string $remoteIp = ''): bool
    {
        if (!self::isEnabled()) {
            return true;
        }
        $token = trim((string) $token);
        if ($token === '') {
            return false;
        }

        $secretKey = self::secretKey();
        $verifyUrl = getenv('CAPTCHA_VERIFY_URL') ?: self::VERIFY_URL;

        $body = http_build_query([
            'secret'   => $secretKey,
            'response' => $token,
            'remoteip' => $remoteIp,
        ]);

        $ch = curl_init($verifyUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 4,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $resp   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($resp === false || $err !== '') {
            \pmwh3\Utils\ErrorHandler::trace("CaptchaManager verify curl error: {$err}");
            return true; // fail-open
        }

        $json = json_decode((string) $resp, true);
        if (!is_array($json)) {
            \pmwh3\Utils\ErrorHandler::trace('CaptchaManager verify: invalid JSON from Google');
            return true; // fail-open
        }

        if (!empty($json['error-codes'])) {
            $codes = $json['error-codes'];
            \pmwh3\Utils\ErrorHandler::trace('CaptchaManager verify error-codes: '
                    . implode(', ', $codes));
            $tokenErrors = ['invalid-input-response', 'missing-input-response'];
            $isTokenError = count(array_intersect($codes, $tokenErrors)) > 0;
            return !$isTokenError; // Token invalid → false; secret/config errors → fail-open
        }

        if (empty($json['success'])) {
            return false;
        }

        // v3: check score
        if (self::type() === 'recaptcha_v3') {
            $score = (float) ($json['score'] ?? 1.0);
            if ($score < self::scoreThreshold()) {
                \pmwh3\Utils\ErrorHandler::trace(sprintf(
                        'CaptchaManager v3 score %.2f below threshold %.2f',
                        $score, self::scoreThreshold()));
                return false;
            }
        }

        return true;
    }
}
