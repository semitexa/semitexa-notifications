<?php

declare(strict_types=1);

namespace Semitexa\Notifications\Application\Service;

use Semitexa\Notifications\Domain\Model\RecipientKey;

/**
 * The WHERE clause that pins a statement to one recipient.
 *
 * `recipient_key` (an exact hex hash of tenant and recipient id) is what makes
 * it exact on every database and is what the indexes lead with; the readable
 * tenant_id and recipient_id are matched as well, so the tenant boundary is
 * stated in the SQL itself, not only implied by a hash.
 */
final class RecipientScope
{
    /**
     * @return array{0: string, 1: array<string, string>} the condition and its parameters
     */
    public static function where(RecipientKey $key): array
    {
        return [
            'recipient_key = :scope_key AND tenant_id = :scope_tenant_id AND recipient_id = :scope_recipient_id',
            [
                'scope_key'          => $key->storageKey,
                'scope_tenant_id'    => $key->tenantId,
                'scope_recipient_id' => $key->recipientId,
            ],
        ];
    }
}
