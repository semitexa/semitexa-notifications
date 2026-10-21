<?php

declare(strict_types=1);

namespace Semitexa\Notifications\Domain\Contract;

/**
 * Something worth telling a recipient about, once, over the channels it picks.
 *
 * What each channel shows comes from a small per-channel interface the
 * notification also implements: {@see MailNotificationInterface} for `mail`,
 * {@see DatabaseNotificationInterface} for `database`. A project channel
 * defines its own.
 */
interface NotificationInterface
{
    /**
     * A stable key such as `orders.shipped`: stored with every inbox row and
     * matched by the recipient's preferences, so renaming it is a data change.
     * Lower-case letters, digits and `. _ : -`, at most 120 characters.
     */
    public function type(): string;

    /**
     * The channel names to deliver over for this recipient, e.g. `['mail', 'database']`.
     *
     * @return list<string>
     */
    public function via(NotifiableInterface $recipient): array;
}
