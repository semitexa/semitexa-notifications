<?php

declare(strict_types=1);

namespace Semitexa\Notifications;

use Semitexa\Core\Attribute\Capability;

/**
 * What this package offers, for the capability catalog.
 *
 * Nothing reads this at runtime.
 */
#[Capability(
    id: 'notifications.send',
    summary: 'One notification delivered to its recipients over several channels (mail, a database inbox, your own), honouring each recipient\'s opt-outs and reporting what was sent, skipped or failed.',
    useWhen: 'Something happened that a person should hear about, and where they hear it depends on the event and on their own settings.',
    avoidWhen: 'The message is a one-off transactional mail with no inbox copy and no opt-out - send it through MailServiceInterface.',
    replaces: [
        'a mail send plus a hand-written inbox INSERT in each handler that reports an event',
        'a per-user settings column checked before every send',
    ],
)]
#[Capability(
    id: 'notifications.inbox',
    summary: 'A per-recipient, tenant-scoped inbox of stored notifications with unread counts and mark-as-read.',
    useWhen: 'The UI shows a bell, a notification list or an unread badge.',
    avoidWhen: 'The record is an audit trail rather than something a person reads - keep it in its own table.',
    replaces: [
        'a hand-made notifications table with read flags and its own queries',
    ],
)]
final class Capabilities
{
}
