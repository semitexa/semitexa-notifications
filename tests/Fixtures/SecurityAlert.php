<?php

declare(strict_types=1);

namespace Semitexa\Notifications\Tests\Fixtures;

use Semitexa\Notifications\Domain\Contract\DatabaseNotificationInterface;
use Semitexa\Notifications\Domain\Contract\MandatoryNotificationInterface;
use Semitexa\Notifications\Domain\Contract\NotifiableInterface;

final class SecurityAlert implements MandatoryNotificationInterface, DatabaseNotificationInterface
{
    /** @param list<string> $channels */
    public function __construct(private readonly array $channels = ['database']) {}

    public function type(): string
    {
        return 'security.password_changed';
    }

    public function via(NotifiableInterface $recipient): array
    {
        return $this->channels;
    }

    public function toDatabase(NotifiableInterface $recipient): array
    {
        return ['title' => 'Your password was changed'];
    }
}
