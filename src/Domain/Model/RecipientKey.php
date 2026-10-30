<?php

declare(strict_types=1);

namespace Semitexa\Notifications\Domain\Model;

use Semitexa\Notifications\Domain\Contract\NotifiableInterface;

/**
 * The identity every stored row of a recipient carries: tenant AND recipient.
 *
 * `storageKey` is the SHA-256 (hex) of both. Rows are found and kept unique by
 * it, because it compares exactly on every database: MySQL's default collation
 * treats `abc` and `ABC`, or `josé` and `jose`, as one value, and a plain
 * comparison of ids would hand one recipient another's inbox. The readable
 * tenant_id and recipient_id columns are stored beside it.
 *
 * A recipient without a tenant is stored under the empty tenant id: one bucket
 * shared by every tenantless recipient with that id. Multi-tenant code must
 * always give the tenant.
 */
final readonly class RecipientKey
{
    public const int MAX_LENGTH = 64;

    private function __construct(
        public string $tenantId,
        public string $recipientId,
        public string $storageKey,
    ) {}

    /** @throws \InvalidArgumentException when the recipient has no usable id */
    public static function of(NotifiableInterface $recipient): self
    {
        return self::from($recipient->notificationTenantId(), $recipient->notificationRecipientId());
    }

    /** @throws \InvalidArgumentException */
    public static function from(?string $tenantId, string $recipientId): self
    {
        $tenantId ??= '';

        if ($recipientId === '' || trim($recipientId) !== $recipientId) {
            throw new \InvalidArgumentException('A notification recipient needs a non-empty id without surrounding spaces.');
        }
        // MySQL ignores trailing spaces when it compares: 'acme ' would be 'acme'.
        if (trim($tenantId) !== $tenantId) {
            throw new \InvalidArgumentException('A notification recipient\'s tenant id cannot start or end with a space.');
        }
        // Characters, as the VARCHAR(64) columns count them: a 33-letter
        // Cyrillic id is 66 bytes and fits.
        if (mb_strlen($recipientId) > self::MAX_LENGTH || mb_strlen($tenantId) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException('A notification recipient id and tenant id are at most ' . self::MAX_LENGTH . ' characters.');
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $tenantId . $recipientId) === 1) {
            throw new \InvalidArgumentException('A notification recipient id and tenant id cannot contain control characters.');
        }

        // Length-prefixed, so no two (tenant, recipient) pairs hash the same
        // input: ('ab', 'c') and ('a', 'bc') differ in the prefix.
        return new self($tenantId, $recipientId, hash('sha256', strlen($tenantId) . ':' . $tenantId . $recipientId));
    }
}
