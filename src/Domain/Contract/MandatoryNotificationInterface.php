<?php

declare(strict_types=1);

namespace Semitexa\Notifications\Domain\Contract;

/**
 * A notification the recipient cannot switch off - a password change, a new
 * sign-in. Preferences are not consulted for it on any channel.
 *
 * Use it for what the recipient must hear for their own safety, not for what
 * the business would rather they did not miss.
 */
interface MandatoryNotificationInterface extends NotificationInterface
{
}
