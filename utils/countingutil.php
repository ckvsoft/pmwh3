<?php

namespace pmwh3\Utils;

use ckvsoft\mvc\Config;

/**
 * Per-customer resource quotas / counters.
 *
 * Schema (existing pmwh3_countings):
 *   PRIMARY KEY (cid, type)
 *   type IN ('max', 'used', 'granted')
 *   one column per resource:
 *       webspace, traffic, domains, subdomains,
 *       emails, forwards, dbases
 *
 * Semantics of `max`:
 *   -1  -> unlimited
 *    0  -> service not part of this customer's package
 *   >0  -> hard quota
 *
 * `used`    counts what the customer has consumed himself.
 * `granted` counts what the customer has handed down to sub-customers.
 *
 * A customer is allowed to create another instance of <resource> when
 *      max == -1
 *   OR (max - used - granted) > 0
 */
class CountingUtil
{

    /**
     * Whitelist of column names that map to resources. Keeps SQL safe
     * when the resource name is interpolated (PDO can't bind identifiers).
     */
    private const RESOURCES = [
        'webspace',
        'traffic',
        'domains',
        'subdomains',
        'emails',
        'forwards',
        'dbases',
    ];

    private const TYPES = ['max', 'used', 'granted'];

    /**
     * Returns the value of one counter for a customer / resource.
     */
    public static function get(int $cid, string $resource, string $type = 'used'): int
    {
        self::assertResource($resource);
        self::assertType($type);

        $db = Config::moduleDb();

        $row = $db->selectOne(
                "SELECT `{$resource}` AS value
                   FROM pmwh3_countings
                  WHERE cid = :cid AND type = :type",
                ['cid' => $cid, 'type' => $type]
        );

        return (int) ($row['value'] ?? 0);
    }

    /**
     * Returns all three values (max/used/granted) for one resource at once.
     * Useful for limit displays in views.
     *
     * @return array{max:int, used:int, granted:int, available:int|null}
     *         `available` is null for unlimited (max == -1).
     */
    public static function getQuota(int $cid, string $resource): array
    {
        self::assertResource($resource);

        $db = Config::moduleDb();
        $rows = $db->select(
                "SELECT type, `{$resource}` AS value
                   FROM pmwh3_countings
                  WHERE cid = :cid",
                ['cid' => $cid],
                \PDO::FETCH_KEY_PAIR
        );

        $max     = (int) ($rows['max'] ?? 0);
        $used    = (int) ($rows['used'] ?? 0);
        $granted = (int) ($rows['granted'] ?? 0);

        $available = $max === -1 ? null : max(0, $max - $used - $granted);

        return [
            'max'       => $max,
            'used'      => $used,
            'granted'   => $granted,
            'available' => $available,
        ];
    }

    /**
     * Whether the customer is allowed to create another <resource>.
     */
    public static function isAllowed(int $cid, string $resource): bool
    {
        $q = self::getQuota($cid, $resource);
        if ($q['max'] === -1) {
            return true;
        }
        if ($q['max'] === 0) {
            return false; // service not in package
        }
        return $q['available'] > 0;
    }

    /**
     * Whether the resource is part of the customer's package at all.
     * (max != 0; -1 unlimited or >0 quota both count as "in package")
     */
    public static function hasService(int $cid, string $resource): bool
    {
        return self::get($cid, $resource, 'max') !== 0;
    }

    public static function increment(int $cid, string $resource, int $delta = 1): void
    {
        self::adjust($cid, $resource, 'used', $delta);
    }

    public static function decrement(int $cid, string $resource, int $delta = 1): void
    {
        self::adjust($cid, $resource, 'used', -$delta);
    }

    public static function incrementGranted(int $cid, string $resource, int $delta = 1): void
    {
        self::adjust($cid, $resource, 'granted', $delta);
    }

    public static function decrementGranted(int $cid, string $resource, int $delta = 1): void
    {
        self::adjust($cid, $resource, 'granted', -$delta);
    }

    /**
     * Generic adjustment by delta. Negative delta decrements (clamped at 0).
     * Creates the (cid,type) row if missing.
     */
    public static function adjust(int $cid, string $resource, string $type, int $delta): void
    {
        self::assertResource($resource);
        self::assertType($type);
        if ($delta === 0) {
            return;
        }

        $db = Config::moduleDb();

        // Ensure the row exists.
        $exists = $db->selectOne(
                "SELECT cid FROM pmwh3_countings WHERE cid = :cid AND type = :type",
                ['cid' => $cid, 'type' => $type]
        );
        if (!$exists) {
            $db->insert('pmwh3_countings', [
                'cid'  => $cid,
                'type' => $type,
            ]);
        }

        if ($delta > 0) {
            $abs = (int) $delta;
            $expr = new \ckvsoft\DbExpr(
                    "COALESCE(`{$resource}`, 0) + {$abs}"
            );
        } else {
            // GREATEST(... , 0) clamps at 0 so we never go negative.
            $abs = (int) abs($delta);
            $expr = new \ckvsoft\DbExpr(
                    "GREATEST(COALESCE(`{$resource}`, 0) - {$abs}, 0)"
            );
        }

        $db->update(
                'pmwh3_countings',
                [$resource => $expr],
                'cid = :cid AND type = :type',
                ['cid' => $cid, 'type' => $type]
        );
    }

    public static function getResources(): array
    {
        return self::RESOURCES;
    }

    private static function assertResource(string $resource): void
    {
        if (!in_array($resource, self::RESOURCES, true)) {
            throw new \InvalidArgumentException(
                            "CountingUtil: unknown resource '{$resource}'"
            );
        }
    }

    private static function assertType(string $type): void
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException(
                            "CountingUtil: unknown type '{$type}'"
            );
        }
    }
}
