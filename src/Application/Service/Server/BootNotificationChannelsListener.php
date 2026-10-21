<?php

declare(strict_types=1);

namespace Semitexa\Notifications\Application\Service\Server;

use Semitexa\Core\Attribute\AsServerLifecycleListener;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleContext;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleListenerInterface;
use Semitexa\Core\Server\Lifecycle\ServerLifecyclePhase;
use Semitexa\Notifications\Application\Service\NotificationChannelRegistry;

/**
 * Discovers the `#[AsNotificationChannel]` classes when a worker starts, so a
 * malformed or duplicated channel name stops the worker at boot instead of
 * failing the first notification a visitor triggers.
 *
 * Only the names are read here; each channel is built by the container on its
 * first use, so a channel with a broken dependency fails its own deliveries
 * without keeping the worker from starting.
 */
#[AsServerLifecycleListener(
    phase: ServerLifecyclePhase::WorkerStartAfterContainer->value,
    priority: -4,
    requiresContainer: true,
)]
final class BootNotificationChannelsListener implements ServerLifecycleListenerInterface
{
    #[InjectAsReadonly]
    protected NotificationChannelRegistry $channels;

    public function handle(ServerLifecycleContext $context): void
    {
        $this->channels->assertValid();
    }
}
