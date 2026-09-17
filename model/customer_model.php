<?php

// modules/pmwh3/model/customer_model.php

use ckvsoft\mvc\Model;
use pmwh3\Utils\CustomerManager;

/**
 * Customer view model.
 *
 * Responsibility split:
 *   CustomerManager (utils/customermanager.php)
 *       Business operations: CRUD, hierarchy queries, password updates,
 *       quota readouts. Stateless, callable from anywhere -- scripts,
 *       APIs, other modules' controllers (e.g. multilogin/UserProvider).
 *   CustomerUtil (utils/customerutil.php)
 *       Permission/ACL checks, hierarchy traversal helpers.
 *   Customer_Model (this file)
 *       View-shaped data the customer controller needs to render its
 *       pages: getAll() with creator_name self-join (admin overview),
 *       getGroups() / getPackagesForSelect() / getPackageLimits() for
 *       the edit form. The thin wrappers around CustomerManager
 *       methods exist so controllers can stay in $model->X() style
 *       across all pages without mixing ::Manager and ->model calls.
 */
class Customer_Model extends Model
{

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Get one customer row by id.
     *
     * Note: this is named getById, NOT get -- the inherited
     * ckvsoft\mvc\Config::get(string $key) is static and we cannot
     * shadow it with a non-static get() of a different signature.
     */
    public function getById(int $cid): ?array
    {
        return CustomerManager::getById($cid);
    }

    public function getByName(string $name): ?array
    {
        return CustomerManager::getByName($name);
    }

    /** Returns ALL customers (used by ultimate_admin / cid=1 view).
     *  Includes a `creator_name` column resolved by the manager via
     *  self-join. NULL for creator=0 (system-created accounts). */
    public function getAll(): array
    {
        $rows = CustomerManager::listAllWithCreator();
        // Annotate level=0 so the overview view's indent code works
        // unchanged.
        foreach ($rows as &$row) {
            $row['level'] = 0;
        }
        unset($row);
        return $rows;
    }

    /**
     * Customers list scoped to a creator.
     *  $recursive=true   -> all customers reachable transitively via creator
     *  $recursive=false  -> only direct descendants
     */
    public function getList(int $creatorCid, bool $recursive): array
    {
        return CustomerManager::getList($creatorCid, $recursive);
    }

    public function getLimits(int $cid): array
    {
        return CustomerManager::getLimits($cid);
    }

    /**
     * List of pmwh3 customer-groups for the customer-edit dropdown.
     * Reads from framework `roles` table (module='pmwh3'); the
     * old pmwh3_groups table is gone post-4b.
     *
     * Return shape: [role_id => roleName, ...]
     */
    public function getGroups(): array
    {
        $rows = \ckvsoft\mvc\Config::db()->select(
                "SELECT id, roleName
                   FROM roles
                  WHERE module = 'pmwh3'
                    AND depth = 1
                  ORDER BY lft"
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['id']] = (string) $r['roleName'];
        }
        return $out;
    }

    public function getPackagesForSelect(int $creatorCid): array
    {
        $db = $this->moduleDb();
        $rows = $db->select(
                "SELECT package_name FROM pmwh3_packages
                  WHERE creator = :c OR creator = 0
               ORDER BY package_name",
                ['c' => $creatorCid]
        );
        $out = ['' => '— ' . __('No package') . ' —'];
        foreach ($rows as $r) {
            $out[$r['package_name']] = $r['package_name'];
        }
        return $out;
    }

    public function getPackageLimits(string $packageName): ?array
    {
        $db = $this->moduleDb();
        return $db->selectOne(
                "SELECT * FROM pmwh3_packages WHERE package_name = :n",
                ['n' => $packageName]
        ) ?: null;
    }

    /**
     * All packages visible to a creator, full rows. Used by the edit
     * view to auto-fill limit fields when a package is picked from
     * the dropdown (the JS reads this map and copies the relevant
     * keys into the form's number inputs).
     *
     * @return array<string, array>  package_name => row
     */
    public function getAllPackages(int $creatorCid): array
    {
        $db = $this->moduleDb();
        $rows = $db->select(
                "SELECT * FROM pmwh3_packages
                  WHERE creator = :c OR creator = 0
               ORDER BY package_name",
                ['c' => $creatorCid]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[$r['package_name']] = $r;
        }
        return $out;
    }
}
