<?php

declare(strict_types=1);

namespace Semitexa\Notifications\Tests\Fixtures\Channel;

use Semitexa\Notifications\Attribute\AsNotificationChannel;
use Semitexa\Notifications\Domain\Contract\NotifiableInterface;
use Semitexa\Notifications\Domain\Contract\NotificationChannelInterface;
use Semitexa\Notifications\Domain\Contract\NotificationInterface;

/** A discovery key only: tests map the class to a RecordingChannel in their container. */
#[AsNotificationChannel('mail')]
final class ProjectMailChannel implements NotificationChannelInterface
{
    public function send(NotifiableInterface $recipient, NotificationInterface $notification): void
    {
    }
}
