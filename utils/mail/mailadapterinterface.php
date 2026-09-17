<?php

namespace pmwh3\Utils\Mail;

/**
 * Mail backend operations.
 *
 * Currently the only backend is Postfix + Dovecot via shared SQL
 * tables (pmwh3_mail_accounts, pmwh3_mail_forwardings, postfix_transport,
 * dovecot_quota). Other adapters can plug in by implementing this
 * interface and dropping the file into utils/mail/.
 */
interface MailAdapterInterface
{

    // ===== Discovery (for AdapterRegistry / option_providers) ===========

    /** Short identifier persisted in the MAIL_TYPE setting. */
    public static function getKey(): string;

    /** Human-readable name shown in the options dropdown. */
    public static function getName(): string;

    /**
     * Runtime availability check. Adapters returning false are filtered
     * out of the dropdown.
     */
    public static function isAvailable(): bool;

    // ---- Mailboxes ----

    /** @return list<array> Each row enriched with at least 'email', 'login', 'quota_bytes'. */
    public static function listMailboxes(string $domain, string $orderBy = 'email'): array;

    public static function getMailbox(string $email): ?array;

    public static function createMailbox(array $row, string $plainPassword): bool;

    public static function updateMailbox(string $email, array $row): bool;

    public static function changePassword(string $email, string $plainPassword): bool;

    /**
     * Compare a plaintext password against the stored mailbox row.
     * Must honor the same ENCRYPT_EMAIL_PASS option chain as
     * createMailbox / changePassword. Returns false for unknown
     * mailboxes / empty passwords.
     */
    public static function verifyPassword(string $email, string $plainPassword): bool;

    public static function deleteMailbox(string $email): bool;

    public static function countMailboxes(string $domain): int;

    // ---- Forwards (source = 'user@domain') ----

    /** @return list<array> Rows with 'source', 'destination'. */
    public static function listForwards(string $domain, string $orderBy = 'source'): array;

    public static function getForward(string $source): ?array;

    /** @param string|array $destinations Comma-separated string or list. */
    public static function setForward(string $source, $destinations): bool;

    public static function deleteForward(string $source): bool;

    public static function countForwards(string $domain): int;

    // ---- Catchalls (source = '@domain') ----

    /** @return list<array> */
    public static function listCatchalls(string $domain): array;

    public static function setCatchall(string $domain, string $destination): bool;

    public static function deleteCatchall(string $domain): bool;

    public static function countCatchalls(string $domain): int;
}
