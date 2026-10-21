<?php

declare(strict_types=1);

namespace Semitexa\Notifications\Tests\Fixtures;

use Semitexa\Notifications\Domain\Contract\NotifiableInterface;
use Semitexa\Notifications\Domain\Contract\NotificationChannelInterface;
use Semitexa\Notifications\Domain\Contract\NotificationInterface;

/** Throws what it is given, every time. */
final class ThrowingChannel implements NotificationChannelInterface
{
    public function __construct(private readonly \Throwable $error) {}

    public function send(NotifiableInterface $recipient, NotificationInterface $notification): void
    {
        throw $this->error;
    }
}
