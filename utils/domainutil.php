<?php

// pmwh3/Utils/DomainUtil.php

namespace pmwh3\Utils;

use ckvsoft\mvc\Config;
use pmwh3\Utils\CustomerUtil; // For customer lookup

class DomainUtil extends Config
{

    /**
     * True when $candidate is $domain itself or a subdomain of it
     * (label-suffix match, case-insensitive, no trailing-dot drama).
     */
    public static function isSubdomain(string $candidate, string $domain): bool
    {
        $c = strtolower(rtrim($candidate, '.'));
        $d = strtolower(rtrim($domain, '.'));
        if ($c === '' || $d === '') {
            return false;
        }
        return $c === $d || str_ends_with($c, '.' . $d);
    }

    /**
     * Finds the customer name who owns the given domain by looking up its CID.
     * @param string $domainValue The domain/subdomain name.
     * @return string The customer's name.
     */
    public static function getOwnerOfDomain(string $domainValue): string
    {
        // 1. Find the CID for the specific domain using the Database class's selectOne() method.
        // Replaced: $stmt->prepare(...)->execute(...)
        $query = "SELECT cid FROM pmwh3_domains WHERE domain = :domain LIMIT 1";

        // Note: The key in the binding array must match the placeholder name without the ':'
        $row = self::$moduleSharedDb->selectOne($query, ['domain' => $domainValue]);

        if (!$row) {
            return 'Domain Owner Not Found';
        }

        // 2. Use the existing CustomerUtil to get the name
        try {
            // false, because we do not want to use the domain owner's session
            return CustomerUtil::getCustomerNameById((int) $row['cid'], false);
        } catch (\Exception $e) {
            // Log this exception if needed
            error_log("Failed to retrieve customer name for CID " . $row['cid'] . ": " . $e->getMessage());
            return 'Unknown Customer';
        }
    }

    /**
     * Returns the cid (owner customer-id) for a domain, or 0 if not
     * found. Direct lookup -- needed by the quota/counting check
     * paths in email / forward / etc which carry the domain string
     * but need a cid to call CountingUtil.
     */
    public static function getCidOfDomain(string $domainValue): int
    {
        $row = self::$moduleSharedDb->selectOne(
                "SELECT cid FROM pmwh3_domains WHERE domain = :domain LIMIT 1",
                ['domain' => $domainValue]
        );
        return (int) ($row['cid'] ?? 0);
    }

    /**
     * Determines the Top-Level Domain from a subdomain or an alias.
     * @param string $domainName
     * @return string
     */
    public static function getToplevelDomain(string $domainName): string
    {
        // Implementation based on string manipulation (no DB access needed):
        $parts = explode('.', $domainName);
        if (count($parts) >= 2) {
            return $parts[count($parts) - 2] . '.' . $parts[count($parts) - 1];
        }
        return $domainName;
    }

    public static function getIpByDomain(string $domain, string $subdomain = ""): string
    {
        $ip = "";

        // TLD/SLD-Aufteilung & Suche
        $se = explode(".", $subdomain);
        $se = array_reverse($se, true);
        $one = array_shift($se);

        if (count($se) > 0 && (strlen($se[array_key_first($se)]) == 2 || preg_match("/biz|com|ltd|net|org/", $se[array_key_first($se)]))) {
            $one = array_shift($se) . "." . $one;
        }

        $se = array_reverse($se, true);
        array_push($se, $one);

        if (count($se) > 2 && $subdomain !== "") {
            $search = array_shift($se);
            try {
                $ip = DnsManager::getIpByDnsRecordLookup($domain, $search);
            } catch (\Exception $e) {
                error_log("DNS Lookup Error for {$search}.{$domain}: " . $e->getMessage());
            }
        }

        if (empty($ip)) {
            $query = "SELECT ip FROM pmwh3_domains WHERE domain = :domain";
            $result = self::$moduleSharedDb->selectOne($query, ['domain' => $domain]);
            $ip = $result['ip'] ?? '';
        }

        return $ip;
    }

    /**
     * Retrieves the count of direct and (optionally) recursive subdomains for a given domain.
     *
     * @param string $domainValue The domain (TLD or subdomain) to search under (can be UTF-8).
     * @param string $without_aliases 'Y' to exclude aliases (aliases_of IS NULL), 'N' otherwise.
     * @param string $recursive 'Y' to recursively count sub-sub-domains, 'N' otherwise.
     * @return int The total number of subdomains found.
     */
    public static function getSubdomainCountByDomain(string $domainValue, string $without_aliases = "N", string $recursive = "N"): int
    {
        // Convert the input domain to Punycode (ASCII) for reliable database searching
        $asciiDomain = self::domainToAscii($domainValue);

        // 1. Prepare query pattern and base query
        // The pattern filters for direct subdomains: 'something.domain.tld' but not 'something.something.domain.tld'
        $pattern = "%.{$asciiDomain}";
        $notPattern = "%.%.{$asciiDomain}";

        $query = "
            SELECT subdomain
            FROM pmwh3_web_subdomains
            WHERE subdomain LIKE :pattern
              AND subdomain NOT LIKE :notPattern
        ";
        $bindings = [
            'pattern' => $pattern,
            'notPattern' => $notPattern,
        ];

        if ($without_aliases === "Y") {
            $query .= " AND alias_of IS NULL";
        }

        $resultArray = self::$moduleSharedDb->select($query, $bindings);

        $count = count($resultArray);

        // 4. Handle recursion
        if ($recursive === "Y") {
            foreach ($resultArray as $row) {
                $count += self::getSubdomainCountByDomain(
                        $row['subdomain'],
                        $without_aliases,
                        $recursive
                );
            }
        }

        return $count;
    }

    public static function getAliasCountBySubDomain(string $domainValue): int
    {
        $sql = "SELECT COUNT(*) as cnt FROM pmwh3_web_subdomains WHERE alias_of = :subdomain";
        $row = self::$moduleSharedDb->selectOne($sql, ['subdomain' => $domainValue]);
        return (int) ($row['cnt'] ?? 0);
    }

    /**
     * Converts a domain name from UTF-8 (human-readable) to Punycode (ASCII) for database storage or DNS lookup.
     * Provides a fallback to simple lowercasing if the intl extension is not loaded.
     * * @param string $domain The domain name.
     * @return string The Punycode domain name, or the lowercase version if IDN is unavailable.
     */
    public static function domainToAscii(string $domain): string
    {
        // Use idn_to_ascii if available for correct Punycode conversion
        if (function_exists('idn_to_ascii')) {
            // IDNA_DEFAULT is generally safe, but IDNA_NONTRANSITIONAL might be better
            // depending on strictness requirements. Stick to default for broader compatibility.
            return idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        }

        // Fallback: If IDN functions are not available, simply lowercase the domain.
        // This is a minimal conversion to avoid errors in the database context,
        // but it will fail for actual non-ASCII characters (like umlauts).
        return strtolower($domain);
    }

    /**
     * Inverse of domainToAscii: takes a Punycode (xn--) domain or
     * email and returns its UTF-8 representation. For an email
     * "user@xn--bcher-kva.example", returns "user@bücher.example".
     *
     * Falls back to the input unchanged when the intl extension is
     * not loaded -- safe default since the input is at minimum a
     * valid ASCII string.
     */
    public static function domainToUtf8(string $value): string
    {
        if (!function_exists('idn_to_utf8')) {
            return $value;
        }
        // Email -> split, convert host part, re-join.
        if (str_contains($value, '@')) {
            [$local, $host] = explode('@', $value, 2);
            $hostUtf8 = idn_to_utf8($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            return $local . '@' . ($hostUtf8 !== false ? $hostUtf8 : $host);
        }
        $r = idn_to_utf8($value, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        return $r !== false ? $r : $value;
    }
}
