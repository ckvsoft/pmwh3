<?php

// modules/pmwh3/model/email_model.php

use ckvsoft\mvc\Model;
use pmwh3\Utils\MailManager;
use pmwh3\Utils\CustomerUtil;

class Email_Model extends Model
{

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Returns the domains the given customer can manage email for.
     * A domain is mail-capable when its services SET column contains
     * 'mail'. We use FIND_IN_SET so we don't need to decode the SET
     * client-side.
     */
    public function getDomainsForCustomer(int $cid): array
    {
        $db = $this->moduleDb();

        // ultimate_admin (cid=1) sees all domains; otherwise scope to
        // the customer hierarchy of the viewer.
        if (CustomerUtil::hasAccess('view_all_customers')) {
            $rows = $db->select(
                    "SELECT domain, customer, cid, services
                       FROM pmwh3_domains
                      WHERE FIND_IN_SET('mail', services) > 0
                      ORDER BY domain", []
            );
        } else {
            $hier = CustomerUtil::getCustomerHierarchy($cid, 'all');
            $cids = array_keys($hier);
            if (empty($cids)) return [];
            $ph = []; $bind = [];
            foreach ($cids as $i => $c) {
                $ph[]         = ":c{$i}";
                $bind["c{$i}"] = (int) $c;
            }
            $sql = "SELECT domain, customer, cid, services
                      FROM pmwh3_domains
                     WHERE FIND_IN_SET('mail', services) > 0
                       AND cid IN (" . implode(',', $ph) . ")
                  ORDER BY domain";
            $rows = $db->select($sql, $bind);
        }

        // Annotate each row with mailbox / forward / catchall counts.
        foreach ($rows as &$row) {
            $domain = (string) $row['domain'];
            $row['mailbox_count']  = MailManager::countMailboxes($domain);
            $row['forward_count']  = MailManager::countForwards($domain);
            $catchalls             = MailManager::listCatchalls($domain);
            $row['catchall']       = $catchalls[0]['destination'] ?? null;
        }
        unset($row);

        return $rows;
    }

    /** Get rows for the "View email" tab. */
    public function getMailboxes(string $domain): array
    {
        return MailManager::listMailboxes($domain);
    }

    /** Get rows for the "View forward" tab. */
    public function getForwards(string $domain): array
    {
        return MailManager::listForwards($domain);
    }

    /** Get the catchall row (or empty) for the "View catchall" tab. */
    public function getCatchall(string $domain): array
    {
        return MailManager::listCatchalls($domain);
    }

    public function getMailbox(string $email): ?array
    {
        return MailManager::getMailbox($email);
    }
}
