<?php

declare(strict_types=1);

namespace Semitexa\Notifications\Domain\Model;

use Semitexa\Notifications\Domain\Enum\DeliveryStatus;

/**
 * What happened to one recipient on one channel.
 *
 * `reason` is a stable code for skipped and failed outcomes, and `duplicate`
 * for a sent one that had been delivered before; `detail` is the human text
 * behind a failure - the exception message of whatever failed.
 */
final readonly class DeliveryOutcome
{
    public const string OPTED_OUT = 'opted_out';
    public const string DUPLICATE = 'duplicate';
    public const string INVALID_CHANNEL = 'invalid_channel';
    public const string UNKNOWN_CHANNEL = 'unknown_channel';
    public const string CHANNEL_UNAVAILABLE = 'channel_unavailable';
    public const string CHANNEL_FAILED = 'channel_failed';
    public const string INVALID_RECIPIENT = 'invalid_recipient';
    public const string INVALID_NOTIFICATION = 'invalid_notification';
    public const string PREFERENCES_UNAVAILABLE = 'preferences_unavailable';

    public function __construct(
        public string $recipientId,
        public ?string $tenantId,
        public string $channel,
        public DeliveryStatus $status,
        public ?string $reason = null,
        public ?string $detail = null,
    ) {}

    public function isSent(): bool
    {
        return $this->status === DeliveryStatus::Sent;
    }

    public function isSkipped(): bool
    {
        return $this->status === DeliveryStatus::Skipped;
    }

    public function isFailed(): bool
    {
        return $this->status === DeliveryStatus::Failed;
    }
}
