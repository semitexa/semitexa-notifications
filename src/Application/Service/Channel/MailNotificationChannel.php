<?php

declare(strict_types=1);

namespace Semitexa\Notifications\Application\Service\Channel;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Mail\Domain\Contract\MailServiceInterface;
use Semitexa\Mail\Domain\Model\MailEnvelope;
use Semitexa\Mail\Domain\Model\MailRecipient;
use Semitexa\Notifications\Attribute\AsNotificationChannel;
use Semitexa\Notifications\Domain\Contract\MailNotificationInterface;
use Semitexa\Notifications\Domain\Contract\NotifiableInterface;
use Semitexa\Notifications\Domain\Contract\NotificationChannelInterface;
use Semitexa\Notifications\Domain\Contract\NotificationInterface;
use Semitexa\Notifications\Domain\Exception\NotificationDeliveryException;
use Semitexa\Notifications\Domain\Model\RecipientKey;
use Semitexa\Notifications\Domain\Service\IdempotencyKeys;

/**
 * `mail`: hands the notification's MailContent to semitexa/mail.
 *
 * No send options are passed, so the mail goes the way MailService sends by
 * default - stored and queued for `mail:work`. "Sent" here therefore means
 * "accepted by the mail service"; delivery itself is the mail worker's, with
 * its own retries and its own record in mail_messages / mail_attempts.
 *
 * An IdempotentNotificationInterface gets an idempotency key per recipient
 * (IdempotencyKeys::forMail): semitexa/mail stores one mail per key, so a
 * repeated send does not mail the recipient twice.
 */
#[AsService]
#[AsNotificationChannel('mail')]
final class MailNotificationChannel implements NotificationChannelInterface
{
    #[InjectAsReadonly]
    protected MailServiceInterface $mail;

    public function send(NotifiableInterface $recipient, NotificationInterface $notification): void
    {
        if (!$notification instanceof MailNotificationInterface) {
            throw new NotificationDeliveryException(sprintf(
                "%s names the 'mail' channel but does not implement %s.",
                $notification::class,
                MailNotificationInterface::class,
            ));
        }

        $email = trim((string) $recipient->notificationEmail());
        if ($email === '') {
            throw new NotificationDeliveryException('The recipient has no email address.');
        }

        $key = RecipientKey::of($recipient);
        $content = $notification->toMail($recipient);

        $envelope = new MailEnvelope();
        // The empty tenant is this package's "no tenant"; mail's is null.
        $envelope->tenantId = $key->tenantId === '' ? null : $key->tenantId;
        $envelope->idempotencyKey = IdempotencyKeys::forMail($notification, $key);
        $envelope->locale = $recipient->notificationLocale();
        $envelope->to = [new MailRecipient($email, $recipient->notificationName())];
        $envelope->subject = $content->subject;
        $envelope->textBody = $content->text;
        $envelope->htmlBody = $content->html;
        $envelope->metadata = ['notification_type' => $notification->type()];
        $envelope->tags = ['notification'];

        $result = $this->mail->send($envelope);
        if (!$result->succeeded()) {
            throw new NotificationDeliveryException(sprintf(
                'The mail service did not accept the mail (%s%s).',
                $result->status->value,
                $result->errorCode !== null ? ', ' . $result->errorCode : '',
            ));
        }
    }
}
