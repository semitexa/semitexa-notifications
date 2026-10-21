<?php

declare(strict_types=1);

namespace Semitexa\Notifications\Domain\Contract;

/**
 * Whoever receives a notification: a user, a customer, a team mailbox.
 *
 * The pair (tenant id, recipient id) is the recipient's identity everywhere
 * this package stores something - the inbox and the preferences are keyed by
 * BOTH, because a recipient id alone is not unique across tenants.
 */
interface NotifiableInterface
{
    /** A stable id, at most 64 characters (a user's UUID, for example). */
    public function notificationRecipientId(): string;

    /**
     * The tenant the recipient belongs to. null is one shared bucket for every
     * tenantless recipient with this id: in a multi-tenant project, always give it.
     */
    public function notificationTenantId(): ?string;

    /** Where the mail channel sends; null when the recipient has no address. */
    public function notificationEmail(): ?string;

    /** The display name the mail channel puts next to the address. */
    public function notificationName(): ?string;

    /**
     * The locale the notification is written in (`uk`, `en`), as the site spells
     * it. The notifier switches the locale context to it while via(), toMail(),
     * toDatabase() and every channel run for this recipient, so trans() answers
     * in the recipient's language; null keeps the sender's.
     */
    public function notificationLocale(): ?string;
}
