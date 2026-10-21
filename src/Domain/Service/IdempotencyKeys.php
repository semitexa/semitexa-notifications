<?php

declare(strict_types=1);

namespace Semitexa\Notifications\Domain\Service;

use Semitexa\Notifications\Domain\Contract\IdempotentNotificationInterface;
use Semitexa\Notifications\Domain\Contract\NotificationInterface;
use Semitexa\Notifications\Domain\Model\RecipientKey;

/**
 * The keys an idempotent notification is stored and sent under.
 *
 * Hashed, so a key of any length and alphabet fits the columns and compares
 * exactly; combined with the type, so two types may use the same key.
 */
final class IdempotencyKeys
{
    /**
     * The inbox key: unique per recipient by the table's index, so the
     * recipient is not part of it. null for a notification that is not idempotent.
     */
    public static function forInbox(NotificationInterface $notification): ?string
    {
        $key = self::keyOf($notification);

        return $key === null ? null : hash('sha256', $notification->type() . "\0" . $key);
    }

    /**
     * The mail key: semitexa/mail keeps one mail per key and tenant, so the
     * recipient is part of it - one notification to two people is two mails.
     */
    public static function forMail(NotificationInterface $notification, RecipientKey $recipient): ?string
    {
        $key = self::keyOf($notification);

        return $key === null
            ? null
            : 'notification:' . hash('sha256', $notification->type() . "\0" . $key . "\0" . $recipient->storageKey);
    }

    private static function keyOf(NotificationInterface $notification): ?string
    {
        if (!$notification instanceof IdempotentNotificationInterface) {
            return null;
        }

        $key = $notification->idempotencyKey();
        if (trim($key) === '') {
            throw new \InvalidArgumentException(sprintf('%s::idempotencyKey() returned an empty key.', $notification::class));
        }

        return $key;
    }
}
