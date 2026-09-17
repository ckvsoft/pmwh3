<?php

namespace pmwh3\Utils;

use ckvsoft\CkvException;
use pmwh3\Config\LazyConfig;
use pmwh3\Utils\Mail\MailAdapterInterface;
use pmwh3\Utils\Mail\PostfixAdapter;

/**
 * Facade for the configured mail backend.
 *
 * Selectable via LazyConfig 'MAIL_TYPE' (default 'postfix'). Today
 * only PostfixAdapter exists; future adapters (e.g. Dovecot-only,
 * external API) plug in here without controllers/models changing.
 */
class MailManager
{

    private static function adapterClass(): string
    {
        $type = strtolower((string) LazyConfig::get('MAIL_TYPE', 'postfix'));
        $map = [
            'postfix' => PostfixAdapter::class,
        ];
        if (!isset($map[$type])) {
            throw new CkvException("MailManager: unknown adapter type '{$type}'");
        }
        return $map[$type];
    }

    private static function call(string $method, array $args = [])
    {
        $cls = self::adapterClass();
        $cls::initDb();
        return $cls::$method(...$args);
    }

    // Mailboxes
    public static function listMailboxes(string $domain, string $orderBy = 'email'): array
    { return self::call('listMailboxes', [$domain, $orderBy]); }

    public static function getMailbox(string $email): ?array
    { return self::call('getMailbox', [$email]); }

    public static function createMailbox(array $row, string $plainPassword): bool
    { return self::call('createMailbox', [$row, $plainPassword]); }

    public static function updateMailbox(string $email, array $row): bool
    { return self::call('updateMailbox', [$email, $row]); }

    public static function changePassword(string $email, string $plainPassword): bool
    { return self::call('changePassword', [$email, $plainPassword]); }

    public static function verifyPassword(string $email, string $plainPassword): bool
    { return self::call('verifyPassword', [$email, $plainPassword]); }

    public static function deleteMailbox(string $email): bool
    { return self::call('deleteMailbox', [$email]); }

    public static function countMailboxes(string $domain): int
    { return self::call('countMailboxes', [$domain]); }

    /**
     * Live mailbox quota from the backend, where the adapter supports
     * it (i.e. the postfix adapter via the dovecot doveadm HTTP API).
     * Returns null for adapters without such a lookup or when the
     * endpoint is not configured / unreachable -- callers fall back
     * to the row's used_bytes/used_messages dict-quota numbers.
     */
    public static function liveQuota(string $email): ?array
    {
        $cls = self::adapterClass();
        if (!is_callable([$cls, 'liveQuota'])) {
            return null;
        }
        self::call('initDb');
        return $cls::liveQuota($email);
    }

    /**
     * Sync the backend-specific domain transport row (currently the
     * postfix pmwh3_mail_transport table). Soft capability: adapters
     * without syncDomainTransport() simply ignore the call -- callers
     * (DomainManager) must not hard-depend on a specific backend.
     */
    public static function syncDomainTransport(string $domain, bool $enabled): void
    {
        $cls = self::adapterClass();
        if (!is_callable([$cls, 'syncDomainTransport'])) {
            return;
        }
        self::call('syncDomainTransport', [$domain, $enabled]);
    }

    // Forwards
    public static function listForwards(string $domain, string $orderBy = 'source'): array
    { return self::call('listForwards', [$domain, $orderBy]); }

    public static function getForward(string $source): ?array
    { return self::call('getForward', [$source]); }

    public static function setForward(string $source, $destinations): bool
    { return self::call('setForward', [$source, $destinations]); }

    public static function deleteForward(string $source): bool
    { return self::call('deleteForward', [$source]); }

    public static function countForwards(string $domain): int
    { return self::call('countForwards', [$domain]); }

    // Catchalls
    public static function listCatchalls(string $domain): array
    { return self::call('listCatchalls', [$domain]); }

    public static function setCatchall(string $domain, string $destination): bool
    { return self::call('setCatchall', [$domain, $destination]); }

    public static function deleteCatchall(string $domain): bool
    { return self::call('deleteCatchall', [$domain]); }

    public static function countCatchalls(string $domain): int
    { return self::call('countCatchalls', [$domain]); }
}
