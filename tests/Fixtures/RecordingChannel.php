<?php

declare(strict_types=1);

namespace Semitexa\Notifications\Tests\Fixtures;

use Semitexa\Notifications\Domain\Contract\NotifiableInterface;
use Semitexa\Notifications\Domain\Contract\NotificationChannelInterface;
use Semitexa\Notifications\Domain\Contract\NotificationInterface;

/** Records what it was given; fails for the recipient ids it is told to. */
final class RecordingChannel implements NotificationChannelInterface
{
    /** @var list<array{recipient: string, type: string}> */
    public array $sent = [];

    /** @param list<string> $failFor */
    public function __construct(private readonly array $failFor = []) {}

    public function send(NotifiableInterface $recipient, NotificationInterface $notification): void
    {
        if (in_array($recipient->notificationRecipientId(), $this->failFor, true)) {
            throw new \RuntimeException('gateway refused recipient ' . $recipient->notificationRecipientId());
        }
        $this->sent[] = ['recipient' => $recipient->notificationRecipientId(), 'type' => $notification->type()];
    }
}
