<?php

declare(strict_types=1);

namespace Semitexa\Notifications\Domain\Contract;

use Semitexa\Notifications\Domain\Model\MailContent;

/** A notification the `mail` channel can send: it says what the mail reads. */
interface MailNotificationInterface extends NotificationInterface
{
    public function toMail(NotifiableInterface $recipient): MailContent;
}
