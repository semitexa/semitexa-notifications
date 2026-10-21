<?php

declare(strict_types=1);

namespace Semitexa\Notifications\Tests\Fixtures;

use Semitexa\Notifications\Domain\Contract\DatabaseNotificationInterface;
use Semitexa\Notifications\Domain\Contract\NotifiableInterface;

/** A notification whose type() and via() a test scripts - including ones that misbehave. */
final class ScriptedNotification implements DatabaseNotificationInterface
{
    /**
     * @param \Closure(): string $type
     * @param \Closure(NotifiableInterface): array<mixed> $via
     */
    public function __construct(
        private readonly \Closure $type,
        private readonly \Closure $via,
    ) {}

    /** @param array<mixed> $channels */
    public static function of(string $type, array $channels): self
    {
        return new self(static fn (): string => $type, static fn (): array => $channels);
    }

    public function type(): string
    {
        return ($this->type)();
    }

    public function via(NotifiableInterface $recipient): array
    {
        /** @var list<string> PHP does not check the elements; the tests rely on that */
        return ($this->via)($recipient);
    }

    public function toDatabase(NotifiableInterface $recipient): array
    {
        return ['title' => 'scripted'];
    }
}
