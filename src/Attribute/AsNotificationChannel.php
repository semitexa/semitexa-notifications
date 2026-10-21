<?php

declare(strict_types=1);

namespace Semitexa\Notifications\Attribute;

use Attribute;
use Semitexa\Core\Attribute\Capability;

/**
 * Registers a notification channel under the name notifications use in via():
 *
 *     #[AsService]
 *     #[AsNotificationChannel('chat')]
 *     final class ChatNotificationChannel implements NotificationChannelInterface { ... }
 *
 * The class must also be `#[AsService]`: the channel is built by the container,
 * so it takes its own dependencies through `#[InjectAsReadonly]` like any other
 * service. Channel names are unique; a project class may take over a built-in
 * name (`mail`, `database`), and two project classes with one name stop the
 * worker at boot.
 */
#[Capability(
    id: 'notifications.channel',
    summary: 'A new way of reaching a notification\'s recipients - a chat bot, a push service, an SMS gateway - that every notification can name in via().',
    useWhen: 'Recipients should hear about something somewhere the built-in mail and inbox channels do not reach.',
    avoidWhen: 'Only one place in the code ever sends there - call that client directly.',
    replaces: [
        'a chat or SMS client call pasted next to every mail send',
        'an if-per-channel block deciding where each event goes',
    ],
)]
#[Attribute(Attribute::TARGET_CLASS)]
final class AsNotificationChannel
{
    public function __construct(
        public readonly string $name,
    ) {
    }
}
