<?php

// modules/pmwh3/model/domain_model.php

use ckvsoft\mvc\Model;
use pmwh3\Utils\CustomerUtil;
use pmwh3\Utils\DomainUtil;

class Domain_Model extends Model
{

    public function __construct()
    {
        parent::__construct();
        $this->moduleDb = $this->moduleDb();
    }

    /**
     * Returns enriched top-level domains for a customer.
     * Includes IP, owner, subdomain and alias counts.
     */
    public function getDomains(int $customerId): array
    {
        $domains = CustomerUtil::getDomainsByCustomerId($customerId);
        $result = [];

        foreach ($domains as $dom) {
            $domName = $dom['domain'];
            $tld = $this->getToplevelDomain($domName);

            $result[] = [
                "domain" => $domName,
                "idn" => $dom['idn'],
                "ip" => $this->getIpByDomain($tld, $domName),
                "customer" => $this->getOwnerOfDomain($domName),
                "subdomain" => $this->getSubdomainCount($domName, "N"),
                "aliases" => $this->getSubdomainCount($domName, "Y"),
                "alias" => DomainUtil::getAliasCountBySubDomain($domName),
            ];
        }

        return $result;
    }

    /**
     * Returns enriched subdomains for a given domain.
     */
    public function getSubdomains(string $domain, string $withAliases = "Y"): array
    {
        $subdomains = $this->getSubdomainsByDomain($domain, $withAliases);
        $result = [];

        foreach ($subdomains as $sub) {
            $subName = $sub['subdomain'];
            $tld = $this->getToplevelDomain($subName);

            $result[] = [
                "subdomain" => $subName,
                "idn" => $sub['idn'],
                "ip" => $this->getIpByDomain($tld, $subName),
                "customer" => $sub['customer'],
                "sub_subdomain" => $this->getSubdomainCount($subName, "N"),
                "alias" => $this->getAliasCount($subName),
            ];
        }

        return $result;
    }

    /**
     * Returns enriched aliases of a subdomain.
     */
    public function getAliasesOfSubdomain(string $subdomain): array
    {
        $aliases = $this->getAliasRowsBySubdomain($subdomain);
        $result = [];

        foreach ($aliases as $al) {
            $alName = $al['subdomain'];
            $tld = $this->getToplevelDomain($alName);

            $result[] = [
                "alias" => $alName,
                "idn" => $al['idn'],
                "ip" => $this->getIpByDomain($tld, $alName),
                "customer" => $al['customer'],
            ];
        }

        return $result;
    }

    public function getSubdomainsByDomain(string $domain, string $withAliases = "Y"): array
    {
        $params = ['pattern' => "%.$domain", 'exclude' => "%.%.$domain"];
        $sql = "
            SELECT customer, subdomain, alias_of
            FROM pmwh3_web_subdomains
            WHERE subdomain LIKE :pattern
              AND subdomain NOT LIKE :exclude
        ";
        if ($withAliases !== "Y") {
            $sql .= " AND alias_of IS NULL";
        }
        $sql .= " ORDER BY subdomain";

        $rows = $this->moduleDb->select($sql, $params);
        foreach ($rows as &$row) {
            $row['idn'] = DomainUtil::domainToAscii($row['subdomain']);
        }
        return $rows;
    }

    public function getToplevelDomain(string $domain): string
    {
        return DomainUtil::getToplevelDomain($domain);
    }

    private function getAliasRowsBySubdomain(string $subdomain): array
    {
        $sql = "SELECT customer, subdomain FROM pmwh3_web_subdomains WHERE alias_of = :subdomain ORDER BY subdomain";
        $rows = $this->moduleDb->select($sql, ['subdomain' => $subdomain]);
        foreach ($rows as &$row) {
            $row['idn'] = DomainUtil::domainToAscii($row['subdomain']);
        }
        return $rows;
    }

    private function getSubdomainCount(string $domain, string $alias = "N"): int
    {
        $sql = "SELECT COUNT(*) as cnt FROM pmwh3_web_subdomains WHERE subdomain LIKE :pattern";
        if ($alias === "N") {
            $sql .= " AND alias_of IS NULL";
        }
        $row = $this->moduleDb->selectOne($sql, ['pattern' => "%.$domain"]);
        return (int) ($row['cnt'] ?? 0);
    }

    private function getIpByDomain(string $domain, string $subdomain = ""): string
    {
        return DomainUtil::getIpByDomain($domain, $subdomain);
    }

    private function getOwnerOfDomain(string $domain): string
    {
        return DomainUtil::getOwnerOfDomain($domain);
    }

    /**
     * Apache vhost rows for one top-level domain: the apex itself,
     * subdomains under it, and aliases pointing at any of those.
     * Apex comes first; subdomains/aliases sorted by subdomain name.
     */
    public function getApacheRowsForDomain(string $domain): array
    {
        return $this->moduleDb->select(
                "SELECT * FROM pmwh3_web_subdomains
                  WHERE subdomain = :exact
                     OR subdomain LIKE :pattern
                     OR alias_of  = :exact
                     OR alias_of LIKE :pattern
               ORDER BY (subdomain = :exact) DESC, subdomain",
                ['exact' => $domain, 'pattern' => "%.{$domain}"]
        );
    }

    /**
     * Sessions that touched this domain in the last N minutes. A
     * row counts if the user's current URL OR their previous URL
     * mentions the domain.
     */
    public function getLiveSessionsForDomain(string $domain, int $minutes = 5, int $limit = 20): array
    {
        return $this->moduleDb->select(
                "SELECT cid, customer, ip, last_url, prev_url,
                        session_start, updated_at, user_agent
                   FROM pmwh3_activity
                  WHERE (last_url LIKE :p OR prev_url LIKE :p)
                    AND updated_at > NOW() - INTERVAL {$minutes} MINUTE
               ORDER BY updated_at DESC
                  LIMIT {$limit}",
                ['p' => "%{$domain}%"]
        );
    }

    /**
     * Sessions that touched this domain between $minMinutes and
     * $maxHours ago. Used to show "recent but not live" activity.
     */
    public function getRecentSessionsForDomain(string $domain, int $minMinutes = 5, int $maxHours = 24, int $limit = 20): array
    {
        return $this->moduleDb->select(
                "SELECT cid, customer, ip, last_url, prev_url,
                        session_start, updated_at
                   FROM pmwh3_activity
                  WHERE (last_url LIKE :p OR prev_url LIKE :p)
                    AND updated_at <= NOW() - INTERVAL {$minMinutes} MINUTE
                    AND updated_at >  NOW() - INTERVAL {$maxHours} HOUR
               ORDER BY updated_at DESC
                  LIMIT {$limit}",
                ['p' => "%{$domain}%"]
        );
    }

    /**
     * Audit-trail rows from pmwh3_activity_log for one domain.
     * Append-only history of write operations against this target.
     */
    public function getChangeHistoryForDomain(string $domain, int $limit = 50): array
    {
        return $this->moduleDb->select(
                "SELECT cid, customer, ip, action, detail, created_at
                   FROM pmwh3_activity_log
                  WHERE target = :t
               ORDER BY created_at DESC
                  LIMIT {$limit}",
                ['t' => $domain]
        );
    }
}
