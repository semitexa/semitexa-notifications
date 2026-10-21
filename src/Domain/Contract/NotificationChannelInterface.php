<?php

declare(strict_types=1);

namespace Semitexa\Notifications\Domain\Contract;

/**
 * One way of reaching a recipient. Register it with
 * {@see \Semitexa\Notifications\Attribute\AsNotificationChannel} on an
 * `#[AsService]` class; notifications then name it in via().
 */
interface NotificationChannelInterface
{
    /**
     * Deliver, or throw. Returning normally means the channel took the
     * notification (sent it, queued it or stored it); any exception is
     * reported as this channel's failure for this recipient only.
     */
    public function send(NotifiableInterface $recipient, NotificationInterface $notification): void;
}
