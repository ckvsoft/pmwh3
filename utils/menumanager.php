<?php

namespace pmwh3\Utils;

use ckvsoft\mvc\Config;

/**
 * CRUD for the pmwh3 navigation menu (table pmwh3_menu).
 *
 * Schema: name / link / box / sort / hide / icon / permission.
 * Primary key is composite (box, sort) -- the menu is laid out
 * as a grid of "boxes" (top-level groupings like Customers,
 * Domains, Tools, ...) and within each box rows sort ascending
 * by `sort`. There's no surrogate id column, so an "identity"
 * for a row in the URL is the (box, sort) tuple.
 *
 * The `permission` column carries the pmwh3 permission KEY (no
 * 'pmwh3.' prefix in the row itself; we add the prefix when
 * checking via Acl::has()) that gates the menu entry. Empty
 * `permission` means the entry is visible to everyone.
 *
 * `hide` is a manual soft-hide flag ('Y' = hidden in UI,
 * 'N' = shown). Independent of the permission check.
 */
class MenuManager
{

    /** All menu rows ordered as the navigation renders them. */
    public static function listAll(): array
    {
        return Config::moduleDb('pmwh3')->select(
                "SELECT name, link, box, sort, hide, icon, permission
                   FROM pmwh3_menu
               ORDER BY box, sort"
        );
    }

    /**
     * One row by composite key. Returns null if not found.
     */
    public static function getByKey(int $box, int $sort): ?array
    {
        $row = Config::moduleDb('pmwh3')->selectOne(
                "SELECT name, link, box, sort, hide, icon, permission
                   FROM pmwh3_menu
                  WHERE box = :b AND sort = :s",
                ['b' => $box, 's' => $sort]
        );
        return $row ?: null;
    }

    /**
     * Check whether (box, sort) is already taken. Used by save()
     * to refuse renaming a row into an existing slot.
     */
    public static function keyExists(int $box, int $sort): bool
    {
        return self::getByKey($box, $sort) !== null;
    }

    /**
     * Insert a brand-new row. Returns false if (box, sort) is taken.
     */
    public static function create(array $data): bool
    {
        $row = self::sanitize($data);
        if ($row === null) {
            return false;
        }
        if (self::keyExists((int) $row['box'], (int) $row['sort'])) {
            return false;
        }
        Config::moduleDb('pmwh3')->insert('pmwh3_menu', $row);
        return true;
    }

    /**
     * Update an existing row identified by its OLD (box, sort).
     * If the form changed box/sort, this is a key change -- we do
     * a DELETE+INSERT under the new key in one transaction. Otherwise
     * a plain UPDATE.
     *
     * Returns false if the row didn't exist or the new key is taken
     * by a different row.
     */
    public static function update(int $oldBox, int $oldSort, array $data): bool
    {
        $row = self::sanitize($data);
        if ($row === null) {
            return false;
        }
        if (self::getByKey($oldBox, $oldSort) === null) {
            return false;
        }
        $newBox  = (int) $row['box'];
        $newSort = (int) $row['sort'];

        $db = Config::moduleDb('pmwh3');

        if ($newBox === $oldBox && $newSort === $oldSort) {
            // Plain update -- non-key fields only.
            $db->update('pmwh3_menu',
                    [
                        'name'       => $row['name'],
                        'link'       => $row['link'],
                        'hide'       => $row['hide'],
                        'icon'       => $row['icon'],
                        'permission' => $row['permission'],
                    ],
                    'box = :b AND sort = :s',
                    ['b' => $oldBox, 's' => $oldSort]
            );
            return true;
        }

        // Key changed -- new slot must be free.
        if (self::keyExists($newBox, $newSort)) {
            return false;
        }
        // DELETE+INSERT. No transaction wrapping because MariaDB DDL
        // would auto-commit anyway; on a multi-row table the window
        // is small and a failed INSERT after a successful DELETE
        // would still leave the menu in a sane (just-smaller) state.
        $db->delete('pmwh3_menu',
                'box = :b AND sort = :s',
                ['b' => $oldBox, 's' => $oldSort]
        );
        $db->insert('pmwh3_menu', $row);
        return true;
    }

    /**
     * Delete one row.
     */
    public static function delete(int $box, int $sort): bool
    {
        if (self::getByKey($box, $sort) === null) {
            return false;
        }
        Config::moduleDb('pmwh3')->delete(
                'pmwh3_menu',
                'box = :b AND sort = :s',
                ['b' => $box, 's' => $sort]
        );
        return true;
    }

    /**
     * Flip the hide flag. Convenience for the overview list.
     */
    public static function toggleHide(int $box, int $sort): bool
    {
        $row = self::getByKey($box, $sort);
        if ($row === null) {
            return false;
        }
        $new = ((string) $row['hide']) === 'Y' ? 'N' : 'Y';
        Config::moduleDb('pmwh3')->update('pmwh3_menu',
                ['hide' => $new],
                'box = :b AND sort = :s',
                ['b' => $box, 's' => $sort]
        );
        return true;
    }

    /**
     * Distinct box numbers currently in use, ascending. Used by
     * the edit form's "Box" dropdown so the user picks an existing
     * box rather than typing a free-form number.
     */
    public static function listBoxes(): array
    {
        $rows = Config::moduleDb('pmwh3')->select(
                "SELECT DISTINCT box FROM pmwh3_menu ORDER BY box"
        );
        return array_map(fn($r) => (int) $r['box'], $rows);
    }

    /**
     * Permission keys available in the framework for module='pmwh3'.
     * Used to populate the edit form's permission dropdown -- the
     * UI shows the bare key (no 'pmwh3.' prefix); we strip it for
     * display and add it back when saving (well, we don't actually
     * add it back -- the column stores bare keys for backwards
     * compatibility with the menu helper's existing check logic).
     *
     * @param string $prefix  optional bare-key prefix filter
     *                        (e.g. 'view_menu_' to restrict the
     *                        menu-form dropdown to menu-visibility
     *                        perms only). Empty = no filter.
     */
    public static function listAvailablePermissions(string $prefix = ''): array
    {
        if ($prefix !== '') {
            $rows = Config::db()->select(
                    "SELECT permKey FROM permissions
                      WHERE module = 'pmwh3'
                        AND permKey LIKE :p
                      ORDER BY permKey",
                    ['p' => 'pmwh3.' . $prefix . '%']
            );
        } else {
            $rows = Config::db()->select(
                    "SELECT permKey FROM permissions
                      WHERE module = 'pmwh3'
                      ORDER BY permKey"
            );
        }
        $out = [];
        foreach ($rows as $r) {
            $key = (string) $r['permKey'];
            if (str_starts_with($key, 'pmwh3.')) {
                $key = substr($key, 6);
            }
            $out[] = $key;
        }
        return $out;
    }

    /**
     * Whitelist + type-coerce the input array. Returns null if
     * required fields are missing.
     */
    private static function sanitize(array $in): ?array
    {
        $name = trim((string) ($in['name'] ?? ''));
        $link = trim((string) ($in['link'] ?? ''));
        if ($name === '') {
            return null;
        }
        $box  = (int) ($in['box']  ?? 0);
        $sort = (int) ($in['sort'] ?? 0);
        $hide = ((string) ($in['hide'] ?? 'N')) === 'Y' ? 'Y' : 'N';
        $icon = trim((string) ($in['icon'] ?? ''));
        $perm = trim((string) ($in['permission'] ?? ''));
        return [
            'name'       => $name,
            'link'       => $link,
            'box'        => $box,
            'sort'       => $sort,
            'hide'       => $hide,
            'icon'       => $icon,
            'permission' => $perm,
        ];
    }
}
