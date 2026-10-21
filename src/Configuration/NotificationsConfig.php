<?php

declare(strict_types=1);

namespace Semitexa\Notifications\Configuration;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\Config;

/**
 * Settings of semitexa/notifications, read once per worker.
 */
#[AsService]
final class NotificationsConfig
{
    /** How many days notifications:prune keeps inbox rows; 0 keeps them for ever. */
    #[Config(env: 'NOTIFICATIONS_RETENTION_DAYS', default: 0)]
    protected int $retentionDays = 0;

    public function retentionDays(): int
    {
        return max(0, $this->retentionDays);
    }
}
