<?php

declare(strict_types=1);

namespace Semitexa\Notifications\Domain\Exception;

/**
 * The channels are declared wrongly: two classes claim one name, a name is
 * malformed, or a channel class cannot be built. A code fix, never a retry.
 */
final class NotificationChannelConfigurationException extends \LogicException
{
}
