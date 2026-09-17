<?php

namespace pmwh3\Utils\MultiLogin;

use ckvsoft\MultiLogin\UserProviderInterface;
use pmwh3\Utils\CustomerManager;

/**
 * Exposes pmwh3 customers to the framework's MultiLogin mapping UI.
 *
 * All DB access is delegated to CustomerManager so we don't duplicate
 * SQL or hierarchy logic. CustomerManager uses Config::moduleDb()
 * which now correctly identifies pmwh3 even when called from another
 * module's controller (Cevian config.php was patched to pass the
 * resolved module name down through getModuleDbInstance).
 */
class UserProvider implements UserProviderInterface
{

    public static function getModuleKey(): string
    {
        return 'pmwh3';
    }

    public static function getModuleLabel(): string
    {
        return 'pmwh3';
    }

    private static function format(array $row): array
    {
        return [
            'id'        => (int) $row['cid'],
            'label'     => (string) $row['customer'],
            'secondary' => trim(((string) ($row['realname'] ?? ''))
                    . (empty($row['email']) ? '' : ' <' . $row['email'] . '>')),
        ];
    }

    public static function listUsers(): array
    {
        $out = [];
        foreach (CustomerManager::listAll() as $r) {
            $out[] = self::format($r);
        }
        return $out;
    }

    public static function getUser(int $id): ?array
    {
        $r = CustomerManager::getById($id);
        return $r ? self::format($r) : null;
    }

    public static function searchUsers(string $term): array
    {
        $term = trim($term);
        if ($term === '') {
            return self::listUsers();
        }
        // Simple in-memory filter -- pmwh3 customer counts are small;
        // if this grows we can push the filter into CustomerManager.
        $needle = mb_strtolower($term);
        $out = [];
        foreach (CustomerManager::listAll() as $r) {
            $hay = mb_strtolower(($r['customer'] ?? '') . ' '
                    . ($r['realname'] ?? '') . ' ' . ($r['email'] ?? ''));
            if (str_contains($hay, $needle)) {
                $out[] = self::format($r);
            }
        }
        return $out;
    }
}
