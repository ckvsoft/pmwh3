<?php

namespace pmwh3\Utils;

use ckvsoft\mvc\Config;

/**
 * Customer-to-customer messages.
 *
 * pmwh3_messages joins on customer NAMES (sender, recipient) -- not
 * IDs -- so callers must resolve via CustomerUtil::getCustomerNameById
 * first. The View / Controller wrappers do this; the Manager itself
 * works in names so it can be called from any context.
 */
class MessageManager
{

    /**
     * Inbox for one recipient. Unread first, then newest first.
     */
    public static function inboxFor(string $recipient): array
    {
        if ($recipient === '') {
            return [];
        }
        return Config::moduleDb()->select(
                "SELECT id, sender, recipient, date, subject, text, msg_read
                   FROM pmwh3_messages
                  WHERE recipient = :r
               ORDER BY msg_read ASC, date DESC", ['r' => $recipient]
        );
    }

    /**
     * Sent items for one sender, newest first.
     */
    public static function sentBy(string $sender): array
    {
        if ($sender === '') {
            return [];
        }
        return Config::moduleDb()->select(
                "SELECT id, sender, recipient, date, subject, text, msg_read
                   FROM pmwh3_messages
                  WHERE sender = :s
               ORDER BY date DESC", ['s' => $sender]
        );
    }

    public static function getById(int $id): ?array
    {
        $row = Config::moduleDb()->selectOne(
                "SELECT * FROM pmwh3_messages WHERE id = :i",
                ['i' => $id]
        );
        return $row ?: null;
    }

    /**
     * Send a message. Returns the new message id, or false if the
     * recipient name doesn't resolve to an existing customer.
     */
    public static function send(string $sender, string $recipient, string $subject, string $text)
    {
        $sender    = trim($sender);
        $recipient = trim($recipient);
        if ($recipient === '') {
            return false;
        }
        // Verify recipient exists. Sender name we trust because it
        // comes from session.
        $exists = Config::moduleDb()->selectOne(
                "SELECT 1 FROM pmwh3_customers WHERE customer = :c",
                ['c' => $recipient]
        );
        if (!$exists) {
            return false;
        }
        Config::moduleDb()->insert('pmwh3_messages', [
            'sender'    => $sender,
            'recipient' => $recipient,
            'subject'   => substr($subject, 0, 255),
            'text'      => $text,
            'msg_read'  => 'N',
        ]);
        // No lastInsertId on this Database wrapper -- look it up.
        $row = Config::moduleDb()->selectOne(
                "SELECT id FROM pmwh3_messages
                  WHERE sender = :s AND recipient = :r
               ORDER BY id DESC LIMIT 1",
                ['s' => $sender, 'r' => $recipient]
        );
        return $row ? (int) $row['id'] : true;
    }

    /**
     * Mark a message as read. Only the recipient can do this; the
     * caller is expected to enforce that.
     */
    public static function markRead(int $id): bool
    {
        Config::moduleDb()->update(
                'pmwh3_messages',
                ['msg_read' => 'Y'],
                'id = :i',
                ['i' => $id]
        );
        return true;
    }

    /**
     * Delete a message. Both sender and recipient may delete -- the
     * caller is expected to verify the deleter is one of them.
     */
    public static function delete(int $id): bool
    {
        Config::moduleDb()->delete(
                'pmwh3_messages',
                'id = :i',
                ['i' => $id]
        );
        return true;
    }
}
