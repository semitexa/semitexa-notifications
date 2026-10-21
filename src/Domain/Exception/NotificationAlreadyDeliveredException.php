<?php

declare(strict_types=1);

namespace Semitexa\Notifications\Domain\Exception;

/**
 * Thrown by a channel that recognised an idempotent notification it had
 * already delivered to this recipient. The notifier reports it as sent, with
 * the reason `duplicate`, and does not log it as a failure.
 */
final class NotificationAlreadyDeliveredException extends \RuntimeException
{
}
