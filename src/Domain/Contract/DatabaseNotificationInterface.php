<?php

declare(strict_types=1);

namespace Semitexa\Notifications\Domain\Contract;

/** A notification the `database` channel keeps in the recipient's inbox. */
interface DatabaseNotificationInterface extends NotificationInterface
{
    /**
     * What the inbox row holds: JSON-plain values only (scalars, null, lists
     * and string-keyed arrays of them). It is stored as JSON and handed back
     * as the same array by the inbox.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(NotifiableInterface $recipient): array;
}
