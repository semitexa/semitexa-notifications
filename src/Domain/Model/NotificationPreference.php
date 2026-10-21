<?php

declare(strict_types=1);

namespace Semitexa\Notifications\Domain\Model;

/** One stored switch: notifications of $type on $channel are on or off ('*' = every type). */
final readonly class NotificationPreference
{
    public function __construct(
        public string $type,
        public string $channel,
        public bool $enabled,
    ) {}
}
