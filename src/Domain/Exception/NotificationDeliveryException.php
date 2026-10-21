<?php

declare(strict_types=1);

namespace Semitexa\Notifications\Domain\Exception;

/** A channel could not deliver a notification to one recipient. */
final class NotificationDeliveryException extends \RuntimeException
{
}
