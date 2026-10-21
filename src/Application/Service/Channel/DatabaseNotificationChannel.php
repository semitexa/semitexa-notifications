<?php

declare(strict_types=1);

namespace Semitexa\Notifications\Application\Service\Channel;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Notifications\Application\Service\NotificationInbox;
use Semitexa\Notifications\Attribute\AsNotificationChannel;
use Semitexa\Notifications\Domain\Contract\DatabaseNotificationInterface;
use Semitexa\Notifications\Domain\Contract\NotifiableInterface;
use Semitexa\Notifications\Domain\Contract\NotificationChannelInterface;
use Semitexa\Notifications\Domain\Contract\NotificationInterface;
use Semitexa\Notifications\Domain\Exception\NotificationDeliveryException;
use Semitexa\Notifications\Domain\Model\RecipientKey;
use Semitexa\Notifications\Domain\Service\IdempotencyKeys;

/**
 * `database`: stores the notification in the recipient's inbox (the
 * `notifications_inbox` table), where NotificationInbox reads it back.
 *
 * An IdempotentNotificationInterface is stored once per key and recipient; a
 * repeat throws NotificationAlreadyDeliveredException, which the notifier
 * reports as sent with the reason `duplicate`. The row joins the caller's
 * transaction.
 */
#[AsService]
#[AsNotificationChannel('database')]
final class DatabaseNotificationChannel implements NotificationChannelInterface
{
    #[InjectAsReadonly]
    protected NotificationInbox $inbox;

    public function send(NotifiableInterface $recipient, NotificationInterface $notification): void
    {
        if (!$notification instanceof DatabaseNotificationInterface) {
            throw new NotificationDeliveryException(sprintf(
                "%s names the 'database' channel but does not implement %s.",
                $notification::class,
                DatabaseNotificationInterface::class,
            ));
        }

        $this->inbox->store(
            RecipientKey::of($recipient),
            $notification->type(),
            $notification->toDatabase($recipient),
            IdempotencyKeys::forInbox($notification),
        );
    }
}
