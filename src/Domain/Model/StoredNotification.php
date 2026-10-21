<?php

declare(strict_types=1);

namespace Semitexa\Notifications\Domain\Model;

/** One row of a recipient's inbox, as the `database` channel stored it. */
final readonly class StoredNotification
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public string $id,
        public string $type,
        public array $data,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $readAt = null,
    ) {}

    public function isRead(): bool
    {
        return $this->readAt !== null;
    }
}
