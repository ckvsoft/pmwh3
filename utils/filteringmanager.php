<?php

namespace pmwh3\Utils;

use ckvsoft\mvc\Config;
use pmwh3\Config\LazyConfig;

/**
 * Filtering / WBList — the controller layer (DNS-Adapter-Pattern).
 *
 * Source of truth is the pmwh3 DB (`pmwh3_filtering` / `pmwh3_wblist`,
 * wide tables with amavis-era inheritance @addr -> @domain -> '@.').
 *
 * The export target is pluggable via the FILTER_POLICY_TYPE setting:
 *   'rspamd' (default) -> HTTP-map endpoints served by this module;
 *                         rspamd pulls (multimap maps + settings rules)
 *                         -- no pmwh3-side Redis/file handling needed.
 *   'none'             -> no automation (UI-only).
 *
 * A SpamAssassin bridge (userprefs_sql reading this DB directly) can
 * be added later without touching the DB model.
 */
class FilteringManager
{

    /** Effective-precedence chain, most specific first. */
    public const SCOPE_CHAIN_PREFIX = ''; // kept for readability; chain built dynamically

    public static function getType(): string
    {
        return strtolower((string) LazyConfig::get('FILTER_POLICY_TYPE', 'rspamd'));
    }

    public static function supports(string $cap): bool
    {
        // Capabilities are DB-driven; the export side has no extra
        // write-path features yet.
        return match ($cap) {
            'policies', 'wblist' => self::getType() !== 'none',
        };
    }

    /**
     * Build the inheritance chain for an email/domain lookup,
     * most specific first (amavis semantics):
     *   user@sub.domain -> @sub.domain -> @domain -> @. (global)
     * For a plain domain lookup the chain starts at @domain.
     */
    public static function scopeChain(string $email = '', string $domain = ''): array
    {
        $chain = [];
        $domain = self::normDomain($domain);
        $email = trim(strtolower($email));

        if ($email !== '' && str_contains($email, '@')) {
            $localDomain = substr($email, strpos($email, '@') + 1);
            $chain[] = $email;
            $chain[] = '@' . $localDomain;
            $domain = $localDomain;
        } elseif ($domain !== '') {
            $chain[] = '@' . $domain;
        }

        $rest = explode('.', $domain);
        array_shift($rest); // drop the first label, we added it above
        while ($rest) {
            $chain[] = '@' . implode('.', $rest);
            array_shift($rest);
        }

        $chain[] = '@.'; // global
        return array_values(array_unique($chain));
    }

    private static function normDomain(string $d): string
    {
        return strtolower(trim($d, " \t\n\r\0\x0B."));
    }

    /**
     * Resolve the effective thresholds for an address (@domain catchall
     * receives the same inheritance). Rows with NULL values inherit
     * from the next coarser scope; the global '@.' row closes the chain.
     * If nothing matches at all, return null (rspamd keeps its globals).
     */
    public static function resolveThresholds(
            string $email = '',
            string $domain = ''
    ): ?array {
        if (!self::supports('policies')) {
            return null;
        }
        $chain = self::scopeChain($email, $domain);
        if (!$chain) {
            return null;
        }

        $db = Config::moduleDb();
        $ph = [];
        $bind = [];
        foreach ($chain as $i => $s) {
            $ph[] = ":s{$i}";
            $bind["s{$i}"] = $s;
        }

        $rows = $db->select(
                "SELECT scope, tag_threshold, kill_threshold
                   FROM pmwh3_filtering
                  WHERE scope IN (" . implode(',', $ph) . ")",
                $bind
        );
        $byScope = [];
        foreach ($rows as $row) {
            $byScope[strtolower((string) $row['scope'])] = $row;
        }

        $tag    = null;
        $kill   = null;
        $matched = null;
        foreach ($chain as $scope) {
            $row = $byScope[$scope] ?? null;
            if ($row === null) {
                continue;
            }
            $matched = $matched ?: $scope;
            if ($tag === null && $row['tag_threshold'] !== null) {
                $tag = (float) $row['tag_threshold'];
            }
            if ($kill === null && $row['kill_threshold'] !== null) {
                $kill = (float) $row['kill_threshold'];
            }
            if ($tag !== null && $kill !== null) {
                break;
            }
        }
        if ($tag === null && $kill === null) {
            // nothing at all matched (not even the global row) --
            // let rspamd stand on its global config.
            return null;
        }
        return [
            'scope' => $matched,
            'tag_threshold' => $tag,
            'kill_threshold' => $kill,
        ];
    }

    /**
     * Shape delivered to rspamd external_map for one message: only
     * the two threshold actions, everything else stays global on the
     * rspamd side.
     * Returns array like {"actions":{"add_header":8.0,"reject":12.0}}
     * (UCL accepts JSON).
     */
    public static function externalMapSettings(?float $tag, ?float $kill, ?string $scope = null): array
    {
        // rspamd default/global references (must MATCH the rspamd
        // global actions.conf so inherit behaves like rspamd's own):
        $globalTag = 5.5;
        $globalKill = 50.0;

        $tagEff = $tag ?? $globalTag;
        $killEff = $kill ?? $globalKill;

        // Coherence: rspamd picks the action by the highest threshold
        // that the score crosses. For reject to ever win, the tag
        // threshold must stay BELOW the kill threshold.
        if ($killEff <= $tagEff) {
            $tagEff = max(0.0, round($killEff - 0.1, 1));
        }

        if ($tag === null && $kill === null) {
            return [];
        }

        $out = ['actions' => [
            'add header' => $tagEff,
            'reject'     => $killEff,
        ]];
        if ($scope !== null) {
            // Static/self-registered settings shapes carry the id so
            // the merged settings_id shows up in rspamd tasks/logs.
            $out['id'] = $scope === '@.' ? 'pmwh3_global' : 'pmwh3_' . md5($scope);
        }
        return $out;
    }
}
