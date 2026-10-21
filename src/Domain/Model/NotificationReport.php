<?php

declare(strict_types=1);

namespace Semitexa\Notifications\Domain\Model;

/**
 * Every recipient-and-channel pair of one send, with what happened to it.
 */
final readonly class NotificationReport
{
    /** @param list<DeliveryOutcome> $outcomes */
    public function __construct(
        public string $type,
        public array $outcomes,
    ) {}

    /** @return list<DeliveryOutcome> */
    public function sent(): array
    {
        return array_values(array_filter($this->outcomes, static fn (DeliveryOutcome $o): bool => $o->isSent()));
    }

    /** @return list<DeliveryOutcome> */
    public function skipped(): array
    {
        return array_values(array_filter($this->outcomes, static fn (DeliveryOutcome $o): bool => $o->isSkipped()));
    }

    /** @return list<DeliveryOutcome> */
    public function failed(): array
    {
        return array_values(array_filter($this->outcomes, static fn (DeliveryOutcome $o): bool => $o->isFailed()));
    }

    public function hasFailures(): bool
    {
        return $this->failed() !== [];
    }

    /**
     * The outcome for one recipient on one channel, or null when that pair was
     * not in this send. The recipient is the pair (tenant, id): a null (or
     * empty) $tenantId means the recipient without a tenant, never "any tenant".
     */
    public function outcomeFor(string $recipientId, string $channel, ?string $tenantId = null): ?DeliveryOutcome
    {
        $tenantId = $tenantId === '' ? null : $tenantId;
        foreach ($this->outcomes as $outcome) {
            if ($outcome->recipientId === $recipientId
                && $outcome->channel === $channel
                && ($outcome->tenantId === '' ? null : $outcome->tenantId) === $tenantId) {
                return $outcome;
            }
        }

        return null;
    }

    /**
     * Every outcome for this recipient id on this channel, whatever its tenant.
     *
     * @return list<DeliveryOutcome>
     */
    public function outcomesInAnyTenant(string $recipientId, string $channel): array
    {
        return array_values(array_filter(
            $this->outcomes,
            static fn (DeliveryOutcome $o): bool => $o->recipientId === $recipientId && $o->channel === $channel,
        ));
    }
}
