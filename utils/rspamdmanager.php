<?php

namespace pmwh3\Utils;

use ckvsoft\mvc\Config;

/**
 * Rspamd HTTP API client (controller + worker normal).
 *
 * Same service pattern as DnsManager's PowerDNS API calls: a thin
 * curl wrapper so pmwh3 (php84 container, no rspamc binary) can talk
 * to the rspamd containers. Dovecot-style message learning happens
 * against the controller API (POST /learnspam, /learnham) exactly
 * like the rspamd-dovecot antispam integration does.
 *
 * Settings (pmwh3_configuration):
 *   RSPAMD_API_URL      controller API base, default 'http://rspamd:11334'
 *                       (COntroller 11334: /ping, /stat, /maps, /learn*)
 *   RSPAMD_API_PASSWORD controller password (optional; empty = none)
 *   RSPAMD_WORKER_URL   worker-normal base for message checks,
 *                       default 'http://rspamd:11333'
 */
class RspamdManager
{

    public static function apiUrl(): string
    {
        return rtrim((string) LazyConfig::get('RSPAMD_API_URL', 'http://rspamd:11334'), '/');
    }

    public static function workerUrl(): string
    {
        return rtrim((string) LazyConfig::get('RSPAMD_WORKER_URL', 'http://rspamd:11333'), '/');
    }

    /**
     * Raw HTTP call (curl). Returns ['status'=>int,'body'=>string];
     * status 0 on transport failure with the curl error as body.
     */
    public static function http(string $base, string $method, string $path, ?array $headers = null, ?string $body = null): array
    {
        $url = $base . $path;
        $ch = curl_init($url);
        $hdrs = [
            'Accept: application/json',
        ];
        foreach ((array) ($headers ?? []) as $h) {
            $hdrs[] = $h;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $hdrs,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 4,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            \pmwh3\Utils\ErrorHandler::trace("RspamdManager http error: {$err}");
            return ['status' => 0, 'body' => "curl error: {$err}"];
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => (string) $resp];
    }

    public static function apiPath(string $method, string $path, ?string $rawBody = null, array $extraHeaders = []): array
    {
        $base = self::apiUrl();
        $password = (string) (self::apiPassword());
        $headers = $extraHeaders;
        if ($password !== '') {
            $headers[] = 'Password: ' . $password;
        }
        if ($rawBody !== null) {
            $headers[] = 'Content-Type: application/octet-stream';
        }
        return self::http($base, $method, $path, $headers, $rawBody);
    }

    private static function apiPassword(): string
    {
        return (string) LazyConfig::get('RSPAMD_API_PASSWORD', '');
    }

    // -----------------------------------------------------------------
    // Read-only checks (used for verification / the email tabs)
    // -----------------------------------------------------------------

    public static function ping(): bool
    {
        $r = self::apiPath('GET', '/ping');
        return $r['status'] === 200 && str_contains($r['body'], 'pong');
    }

    /**
     * Aggregated counters (spamcount/hamcount/learned per classifier).
     * Response is JSON with 'actions' and 'stats' (learned counters).
     */
    public static function stat(): ?array
    {
        $r = self::apiPath('GET', '/stat');
        if ($r['status'] !== 200) {
            return null;
        }
        $data = json_decode($r['body'], true);
        return is_array($data) ? $data : null;
    }

    /**
     * List of maps known to rspamd (id, type, enabled...). Used to
     * verify that the pmwh3-sourced multimap entries are loaded.
     */
    public static function maps(): ?array
    {
        $r = self::apiPath('GET', '/maps');
        if ($r['status'] !== 200) {
            return null;
        }
        $data = json_decode($r['body'], true);
        return is_array($data) ? $data : null;
    }

    /**
     * Scan a raw message via the worker-normal port (same endpoint
     * rspamc uses). Returns the decoded scan response (score, action,
     * symbols) or null on failure.
     */
    public static function check(string $rawMessage, ?string $rcpt = null): ?array
    {
        $headers = ['Content-Type: application/octet-stream'];
        $qs = '';
        if ($rcpt !== null && $rcpt !== '') {
            $qs = '?rcpt=' . rawurlencode($rcpt);
        }
        $r = self::http(self::workerUrl(), 'POST', '/checkv2' . $qs, $headers, $rawMessage);
        if (($r['status'] < 200 || $r['status'] >= 300)) {
            return null;
        }
        $data = json_decode($r['body'], true);
        return is_array($data) ? $data : null;
    }

    // -----------------------------------------------------------------
    // Learning (same as the dovecot rspamd integration: controller API)
    // -----------------------------------------------------------------

    public static function learnSpam(string $rawMessage): bool
    {
        return self::learn('POST', '/learnspam', $rawMessage);
    }

    public static function learnHam(string $rawMessage): bool
    {
        return self::learn('POST', '/learnham', $rawMessage);
    }

    public static function learnForget(string $rawMessage): bool
    {
        return self::learn('POST', '/learnforget', $rawMessage);
    }

    private static function learn(string $method, string $path, string $rawMessage): bool
    {
        $r = self::apiPath($method, $path, $rawMessage);
        return $r['status'] >= 200 && $r['status'] < 300;
    }
}
