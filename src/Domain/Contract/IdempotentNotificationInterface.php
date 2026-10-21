<?php

declare(strict_types=1);

namespace Semitexa\Notifications\Domain\Contract;

/**
 * A notification that may be sent again safely: the same key to the same
 * recipient is delivered once.
 *
 * Give it to anything you may retry - a job that is redelivered, or a send
 * repeated after a report with failures. The `mail` channel passes a key
 * derived from it to semitexa/mail, which stores a mail once per key; the
 * `database` channel keeps one inbox row per key and reports a repeat as sent
 * (reason `duplicate`). A project channel can read it the same way.
 */
interface IdempotentNotificationInterface extends NotificationInterface
{
    /**
     * Stable for "this event": `order-42-shipped`, not a timestamp. The key is
     * combined with the type and the recipient, so it need only be unique per type.
     */
    public function idempotencyKey(): string;
}
