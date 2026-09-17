<?php

namespace pmwh3\Utils;

use ckvsoft\mvc\Config;

/**
 * Hosting package CRUD.
 *
 * Packages are stored in pmwh3_packages with composite PK
 * (package_name, creator). creator=0 means "system package",
 * visible to everyone; creator>0 means a specific reseller's
 * package, visible to that reseller and to the customers they
 * create.
 *
 * Permissions checked at the controller level
 * (view_packages / create_package / edit_package / delete_package).
 */
class PackageManager
{

    /** Whitelist of writable columns. */
    private const FIELDS = [
        'webspace', 'traffic', 'domains', 'subdomains',
        'emails', 'forwards', 'dbases', 'php', 'cgi',
    ];

    /**
     * Lookup a package by name+creator. Returns null if not found.
     */
    public static function getByName(string $name, int $creator): ?array
    {
        $row = Config::moduleDb()->selectOne(
                "SELECT * FROM pmwh3_packages
                  WHERE package_name = :n AND creator = :c",
                ['n' => $name, 'c' => $creator]
        );
        return $row ?: null;
    }

    /**
     * Visible packages for a creator. Includes their own + system
     * packages (creator=0). Admin (cid=1) sees everything.
     */
    public static function listVisible(int $viewerCid): array
    {
        if ($viewerCid === 1) {
            return Config::moduleDb()->select(
                    "SELECT * FROM pmwh3_packages ORDER BY creator, package_name", []
            );
        }
        return Config::moduleDb()->select(
                "SELECT * FROM pmwh3_packages
                  WHERE creator = :c OR creator = 0
               ORDER BY creator, package_name",
                ['c' => $viewerCid]
        );
    }

    /**
     * Create a package.
     *
     * @param string $name      package name (PK part 1)
     * @param int    $creator   owning customer cid (PK part 2)
     * @param array  $fields    only FIELDS keys are written
     * @return bool             true on insert success
     */
    public static function create(string $name, int $creator, array $fields): bool
    {
        $name = trim($name);
        if ($name === '') {
            return false;
        }
        if (self::getByName($name, $creator) !== null) {
            return false;  // already exists
        }
        $row = self::sanitize($fields);
        $row['package_name'] = $name;
        $row['creator']      = $creator;
        Config::moduleDb()->insert('pmwh3_packages', $row);
        return true;
    }

    /**
     * Update an existing package's fields. The composite PK
     * (name, creator) is the lookup; package_name itself isn't
     * editable here -- rename is delete+create.
     */
    public static function update(string $name, int $creator, array $fields): bool
    {
        if (self::getByName($name, $creator) === null) {
            return false;
        }
        $row = self::sanitize($fields);
        Config::moduleDb()->update(
                'pmwh3_packages',
                $row,
                'package_name = :n AND creator = :c',
                ['n' => $name, 'c' => $creator]
        );
        return true;
    }

    /**
     * Delete a package. Customers that referenced it keep the name
     * in their `package` column but lookups will return null --
     * that's intentional, deleting a package shouldn't silently
     * change customer limits.
     */
    public static function delete(string $name, int $creator): bool
    {
        Config::moduleDb()->delete(
                'pmwh3_packages',
                'package_name = :n AND creator = :c',
                ['n' => $name, 'c' => $creator]
        );
        return true;
    }

    /**
     * Filter input to whitelisted columns + correct types.
     * Returns an array safe to pass to insert/update.
     */
    private static function sanitize(array $in): array
    {
        $out = [];
        foreach (['webspace', 'traffic', 'domains', 'subdomains',
                  'emails', 'forwards', 'dbases'] as $k) {
            $out[$k] = (int) ($in[$k] ?? 0);
        }
        foreach (['php', 'cgi'] as $k) {
            $v = $in[$k] ?? 'N';
            $out[$k] = ($v === 'Y' || $v === true || $v === 1 || $v === '1') ? 'Y' : 'N';
        }
        return $out;
    }
}
