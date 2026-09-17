<?php

class Traffic_Model extends \ckvsoft\mvc\Model
{

    public function __construct()
    {
        parent::__construct();
        $this->moduleDb = $this->moduleDb();
    }

    public function getSummaryOfId($customerid, $date)
    {
        $customers = \pmwh3\Utils\CustomerUtil::getCustomersById($customerid);

        if (empty($customers)) {
            return [
                "customer" => \ckvsoft\Session::getNs('pmwh3', 'customer_name'),
                "month" => $date,
                "apache" => 0,
                "ftp" => 0,
                "mail" => 0,
                "sum" => 0,
                "apache_hr" => \pmwh3\Utils\SizeConverter::bytesToHumanReadable(0),
                "ftp_hr" => \pmwh3\Utils\SizeConverter::bytesToHumanReadable(0),
                "mail_hr" => \pmwh3\Utils\SizeConverter::bytesToHumanReadable(0),
                "sum_hr" => \pmwh3\Utils\SizeConverter::bytesToHumanReadable(0),
            ];
        }

        // Platzhalter für IN-Klausel erzeugen
        $placeholders = [];
        foreach ($customers as $cid => $i) {
            $placeholders[] = ":cid" . $i;
        }
        $inClause = implode(", ", $placeholders);

        $sql = "
        SELECT
            SUM(apache) AS apache,
            SUM(ftp) AS ftp,
            SUM(mail) AS mail
        FROM pmwh3_traffic t
        JOIN pmwh3_domains d
          ON t.domain = d.domain OR t.username = d.customer
        WHERE d.cid IN ($inClause)
          AND t.timestamp LIKE :date
    ";

        $binds = ['date' => $date . "%"];
        foreach ($customers as $cid => $i) {
            $binds["cid" . $i] = $cid;
        }

        $res = self::$moduleSharedDb->selectOne($sql, $binds) ?: [];

        $apache = (int) ($res['apache'] ?? 0);
        $ftp = (int) ($res['ftp'] ?? 0);
        $mail = (int) ($res['mail'] ?? 0);
        $sum = $apache + $ftp + $mail;

        return [
            "customer" => \pmwh3\Utils\CustomerUtil::getCustomerNameById($customerid),
            "month" => $date,
            "apache" => $apache,
            "ftp" => $ftp,
            "mail" => $mail,
            "sum" => $sum,
            "apache_hr" => \pmwh3\Utils\SizeConverter::bytesToHumanReadable($apache),
            "ftp_hr" => \pmwh3\Utils\SizeConverter::bytesToHumanReadable($ftp),
            "mail_hr" => \pmwh3\Utils\SizeConverter::bytesToHumanReadable($mail),
            "sum_hr" => \pmwh3\Utils\SizeConverter::bytesToHumanReadable($sum),
        ];
    }

    public function getTraffic($customer, $date = null)
    {
        $date = $date ?? date('Y-m');

        $row = $this->moduleDb->selectOne("
        SELECT SUM(apache) AS apache, SUM(ftp) AS ftp, SUM(mail) AS mail
        FROM pmwh3_traffic t
        JOIN pmwh3_domains d ON t.domain = d.domain
        WHERE d.customer = :customer
          AND t.timestamp LIKE :date
    ", ['customer' => $customer, 'date' => $date . '%']) ?: [];

        $apache = (int) ($row['apache'] ?? 0);
        $ftp = (int) ($row['ftp'] ?? 0);
        $mail = (int) ($row['mail'] ?? 0);
        $sum = $apache + $ftp + $mail;

        return [
            'customer' => $customer,
            'month' => $date,
            'apache' => $apache,
            'ftp' => $ftp,
            'mail' => $mail,
            'sum' => $sum,
            'apache_hr' => \pmwh3\Utils\SizeConverter::bytesToHumanReadable($apache),
            'ftp_hr' => \pmwh3\Utils\SizeConverter::bytesToHumanReadable($ftp),
            'mail_hr' => \pmwh3\Utils\SizeConverter::bytesToHumanReadable($mail),
            'sum_hr' => \pmwh3\Utils\SizeConverter::bytesToHumanReadable($sum),
        ];
    }

    public function getTrafficById($customerid, $date = null)
    {
        $date = $date ?? date('Y-m');

        $row = $this->moduleDb->selectOne("
        SELECT SUM(apache) AS apache, SUM(ftp) AS ftp, SUM(mail) AS mail
        FROM pmwh3_traffic t
        JOIN pmwh3_domains d
          ON t.domain = d.domain OR t.username = d.customer
        WHERE d.cid = :cid
          AND t.timestamp LIKE :date
    ", ['cid' => $customerid, 'date' => $date . '%']) ?: [];

        $apache = (int) ($row['apache'] ?? 0);
        $ftp = (int) ($row['ftp'] ?? 0);
        $mail = (int) ($row['mail'] ?? 0);
        $sum = $apache + $ftp + $mail;
        $customerName = \pmwh3\Utils\CustomerUtil::getCustomerNameById($customerid);

        return [
            'customer' => $customerid,
            'name' => $customerName,
            'month' => $date,
            'apache' => $apache,
            'ftp' => $ftp,
            'mail' => $mail,
            'sum' => $sum,
            // human readable
            'apache_hr' => \pmwh3\Utils\SizeConverter::bytesToHumanReadable($apache),
            'ftp_hr' => \pmwh3\Utils\SizeConverter::bytesToHumanReadable($ftp),
            'mail_hr' => \pmwh3\Utils\SizeConverter::bytesToHumanReadable($mail),
            'sum_hr' => \pmwh3\Utils\SizeConverter::bytesToHumanReadable($sum),
        ];
    }

    public function getTrafficByCustomerId($customerid, $date = null, $traffic = [], $add = 0)
    {
        $date = $date ?? date('Y-m');
        $customer = \pmwh3\Utils\CustomerUtil::getCustomersById($customerid, "one");
        if (is_array($customer) && count($customer) > 1) {
            foreach ($customer as $c => $level) {
                if ($add == 1) {
                    if ($c != $customerid) {
                        $val = $this->getTrafficById($c, $date);
                        $traffic[$customerid]["apache"] += $val["apache"];
                        $traffic[$customerid]["ftp"] += $val["ftp"];
                        $traffic[$customerid]["mail"] += $val["mail"];
                        $traffic[$customerid]["sum"] += $val["sum"];
                    }
                } else {
                    $traffic[$c] = $this->getTrafficById($c, $date);
                }
                if ($c != $customerid) {
                    $traffic = $this->getTrafficByCustomerId($c, $date, $traffic, 1);
                }
            }
        }
        return $traffic;
    }

    public function getTrafficByCustomer($customerId, $date = null, $traffic = [], $add = 0)
    {
        $date = $date ?? date('Y-m');

        // Parent + Unterkunden abrufen
        $customers = \pmwh3\Utils\CustomerUtil::getCustomerHierarchy($customerId, "one");

        if (is_array($customers) && count($customers) > 0) {
            foreach ($customers as $cid => $level) {

                // Traffic für diesen Kunden abrufen
                $val = $this->getTraffic($cid, $date);

                if ($add == 1 && $cid != $customerId) {
                    if (!isset($traffic[$customerId])) {
                        $traffic[$customerId] = [
                            "apache" => 0,
                            "ftp" => 0,
                            "mail" => 0,
                            "sum" => 0,
                            "apache_hr" => "0 B",
                            "ftp_hr" => "0 B",
                            "mail_hr" => "0 B",
                            "sum_hr" => "0 B",
                        ];
                    }

                    // Summieren
                    $traffic[$customerId]["apache"] += $val["apache"];
                    $traffic[$customerId]["ftp"] += $val["ftp"];
                    $traffic[$customerId]["mail"] += $val["mail"];
                    $traffic[$customerId]["sum"] += $val["sum"];

                    // human-readable aktualisieren
                    $traffic[$customerId]["apache_hr"] = \pmwh3\Utils\SizeConverter::bytesToHumanReadable($traffic[$customerId]["apache"]);
                    $traffic[$customerId]["ftp_hr"] = \pmwh3\Utils\SizeConverter::bytesToHumanReadable($traffic[$customerId]["ftp"]);
                    $traffic[$customerId]["mail_hr"] = \pmwh3\Utils\SizeConverter::bytesToHumanReadable($traffic[$customerId]["mail"]);
                    $traffic[$customerId]["sum_hr"] = \pmwh3\Utils\SizeConverter::bytesToHumanReadable($traffic[$customerId]["sum"]);
                } else {
                    // Einzelner Kunde
                    $traffic[$cid] = [
                        "apache" => $val["apache"],
                        "ftp" => $val["ftp"],
                        "mail" => $val["mail"],
                        "sum" => $val["sum"],
                        "apache_hr" => $val["apache_hr"],
                        "ftp_hr" => $val["ftp_hr"],
                        "mail_hr" => $val["mail_hr"],
                        "sum_hr" => $val["sum_hr"],
                    ];
                }

                // Rekursion für Unterkunden
                if ($cid != $customerId) {
                    $traffic = $this->getTrafficByCustomer($cid, $date, $traffic, 1);
                }
            }
        }

        return $traffic;
    }

    public function getTrafficByDomain($customer, $date = null)
    {
        $date = $date ?? date('Y-m');
        $domains = [];

        $rows = $this->moduleDb->select("
        SELECT t.domain AS domain, SUM(apache) AS apache, SUM(ftp) AS ftp, SUM(mail) AS mail
        FROM pmwh3_traffic t
        JOIN pmwh3_domains d ON t.domain = d.domain
        WHERE d.customer = :customer
          AND t.timestamp LIKE :date
        GROUP BY t.domain
    ", ['customer' => $customer, 'date' => $date . '%']);

        foreach ($rows as $row) {
            $apache = (int) ($row['apache'] ?? 0);
            $ftp = (int) ($row['ftp'] ?? 0);
            $mail = (int) ($row['mail'] ?? 0);
            $sum = $apache + $ftp + $mail;

            $domains[] = [
                'domain' => $row['domain'],
                'month' => $date,
                'apache' => $apache,
                'ftp' => $ftp,
                'mail' => $mail,
                'sum' => $sum,
                'apache_hr' => \pmwh3\Utils\SizeConverter::bytesToHumanReadable($apache),
                'ftp_hr' => \pmwh3\Utils\SizeConverter::bytesToHumanReadable($ftp),
                'mail_hr' => \pmwh3\Utils\SizeConverter::bytesToHumanReadable($mail),
                'sum_hr' => \pmwh3\Utils\SizeConverter::bytesToHumanReadable($sum),
            ];
        }

        return $domains;
    }

    public function getTrafficByDomainId(int $customerId, ?string $date = null): array
    {
        $date = $date ?? date('Y-m');
        $domains = [];

        // ADMIN_LEVEL prüfen
        $adminLevel = \pmwh3\Config\LazyConfig::get('ADMIN_LEVEL', 'ALL');
        if ($adminLevel === "0") {
            return $domains;
        }

        // Kunden holen (liefert [cid => level])
        $customers = \pmwh3\Utils\CustomerUtil::getCustomersById($customerId, "one");

        if (is_array($customers) && count($customers) > 0) {
            // Placeholder bauen (über Keys!)
            $placeholders = [];
            $params = [];
            $i = 0;
            foreach (array_keys($customers) as $cid) {
                $ph = ":cid" . $i++;
                $placeholders[] = $ph;
                $params[$ph] = (int) $cid;
            }
            $inClause = implode(", ", $placeholders);

            $sql = "
            SELECT t.domain AS domain,
                   SUM(apache) AS apache,
                   SUM(ftp) AS ftp,
                   SUM(mail) AS mail
            FROM pmwh3_traffic t
            JOIN pmwh3_domains d ON t.domain = d.domain
            WHERE d.cid IN ($inClause)
              AND t.timestamp LIKE :date
            GROUP BY t.domain
        ";

            $binds = ['date' => $date . "%"];
            foreach ($params as $ph => $cid) {
                $binds[ltrim((string) $ph, ':')] = $cid;
            }

            $rows = $this->moduleDb->select($sql, $binds);

            foreach ($rows as $row) {
                $apache = (int) ($row['apache'] ?? 0);
                $ftp = (int) ($row['ftp'] ?? 0);
                $mail = (int) ($row['mail'] ?? 0);
                $sum = $apache + $ftp + $mail;

                // Domain IDN → UTF-8 konvertieren, falls intl verfügbar
                if (function_exists('idn_to_utf8')) {
                    $domain = idn_to_utf8($row['domain'], IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $row['domain'];
                } else {
                    $domain = $row['domain'];
                }

                $domains[] = [
                    'domain' => $domain,
                    'month' => $date,
                    'apache' => $apache,
                    'ftp' => $ftp,
                    'mail' => $mail,
                    'sum' => $sum,
                    'apache_hr' => \pmwh3\Utils\SizeConverter::bytesToHumanReadable($apache),
                    'ftp_hr' => \pmwh3\Utils\SizeConverter::bytesToHumanReadable($ftp),
                    'mail_hr' => \pmwh3\Utils\SizeConverter::bytesToHumanReadable($mail),
                    'sum_hr' => \pmwh3\Utils\SizeConverter::bytesToHumanReadable($sum),
                ];
            }
        }

        // Sortierung wie im Original
        usort($domains, fn($a, $b) => strcmp($a['domain'], $b['domain']));

        return $domains;
    }

    public function getTrafficDates()
    {
        $dates = [];
        foreach ($this->moduleDb->select("SELECT DATE_FORMAT(timestamp, '%Y-%m') AS t FROM pmwh3_traffic GROUP BY t ORDER BY t DESC") as $row) {
            $dates[] = $row['t'];
        }
        return $dates;
    }

    public function getTrafficDetails($domain, $date = null)
    {
        $date = $date ?? date('Y-m');
        $details = [];

        $rows = $this->moduleDb->select("
        SELECT timestamp, SUM(apache) AS apache, SUM(ftp) AS ftp, SUM(mail) AS mail
        FROM pmwh3_traffic
        WHERE domain = :domain AND timestamp LIKE :date
        GROUP BY timestamp
    ", ['domain' => $domain, 'date' => $date . '%']);

        foreach ($rows as $row) {
            $apache = (int) ($row['apache'] ?? 0);
            $ftp = (int) ($row['ftp'] ?? 0);
            $mail = (int) ($row['mail'] ?? 0);
            $sum = $apache + $ftp + $mail;

            $details[] = [
                'timestamp' => $row['timestamp'],
                'apache' => $apache,
                'ftp' => $ftp,
                'mail' => $mail,
                'sum' => $sum,
                'apache_hr' => \pmwh3\Utils\SizeConverter::bytesToHumanReadable($apache),
                'ftp_hr' => \pmwh3\Utils\SizeConverter::bytesToHumanReadable($ftp),
                'mail_hr' => \pmwh3\Utils\SizeConverter::bytesToHumanReadable($mail),
                'sum_hr' => \pmwh3\Utils\SizeConverter::bytesToHumanReadable($sum),
            ];
        }

        return $details;
    }
}
