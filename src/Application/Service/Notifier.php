<?php

declare(strict_types=1);

namespace Semitexa\Notifications\Application\Service;

use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Locale\LocaleContextInterface;
use Semitexa\Core\Log\LoggerInterface;
use Semitexa\Notifications\Domain\Contract\MandatoryNotificationInterface;
use Semitexa\Notifications\Domain\Contract\NotifiableInterface;
use Semitexa\Notifications\Domain\Contract\NotificationInterface;
use Semitexa\Notifications\Domain\Contract\NotifierInterface;
use Semitexa\Notifications\Domain\Enum\DeliveryStatus;
use Semitexa\Notifications\Domain\Exception\NotificationAlreadyDeliveredException;
use Semitexa\Notifications\Domain\Model\DeliveryOutcome;
use Semitexa\Notifications\Domain\Model\NotificationReport;
use Semitexa\Notifications\Domain\Model\RecipientKey;
use Semitexa\Notifications\Domain\Service\NotificationNames;
use Semitexa\Orm\Exception\ConnectionLostException;
use Semitexa\Orm\Exception\DeadlockException;
use Semitexa\Orm\Exception\LockWaitTimeoutException;
use Semitexa\Orm\OrmManager;

/**
 * Delivers a notification to each recipient over each channel its via() names.
 *
 * Per recipient: the locale context is switched to the recipient's locale, the
 * channels they switched off are skipped (unless the notification is
 * mandatory), every other channel is run, and whatever goes wrong is caught
 * for that one recipient-and-channel pair - a failing channel or recipient
 * never stops the rest, and send() itself does not throw.
 *
 * With one exception: a deadlock, a lock-wait timeout or a lost connection
 * while the caller's database transaction is open. The server has rolled that
 * transaction back (or is about to), so reporting a failure and carrying on
 * would continue on a transaction that no longer exists. Those three are
 * rethrown, which is what lets TransactionManager::runWithRetry() replay the
 * caller's whole unit. Outside a transaction they are channel failures like
 * any other.
 *
 * A failure is logged as a warning with the type, channel, recipient and
 * tenant ids, the error's class and its message. The notifier never logs the
 * notification's content; the error message is the channel's own text.
 */
#[SatisfiesServiceContract(of: NotifierInterface::class)]
final class Notifier implements NotifierInterface
{
    private const int DETAIL_MAX_LENGTH = 300;

    #[InjectAsReadonly]
    protected NotificationChannelRegistry $channels;

    #[InjectAsReadonly]
    protected NotificationPreferences $preferences;

    #[InjectAsReadonly]
    protected LoggerInterface $logger;

    #[InjectAsReadonly]
    protected OrmManager $orm;

    /** Absent without semitexa/locale: notifications are then written in the one locale there is. */
    #[InjectAsReadonly(optional: true)]
    protected LocaleContextInterface $localeContext;

    public function send(NotifiableInterface|iterable $to, NotificationInterface $notification): NotificationReport
    {
        try {
            $type = $notification->type();
        } catch (\Throwable $e) {
            $this->rethrowIfCallerMustRetry($e);
            $this->logFailure('', '*', null, $e);

            return new NotificationReport('', [
                new DeliveryOutcome('', null, '*', DeliveryStatus::Failed, DeliveryOutcome::INVALID_NOTIFICATION, 'type() failed: ' . self::detail($e)),
            ]);
        }

        // Typed loosely on purpose: the contract says recipients, but an
        // iterable is not checked by PHP, and a stray value must be reported
        // rather than crash the whole send.
        /** @var iterable<mixed> $recipients */
        $recipients = $to instanceof NotifiableInterface ? [$to] : $to;
        $outcomes = [];

        try {
            foreach ($recipients as $recipient) {
                if (!$recipient instanceof NotifiableInterface) {
                    $outcomes[] = new DeliveryOutcome('', null, '*', DeliveryStatus::Failed, DeliveryOutcome::INVALID_RECIPIENT, sprintf(
                        'A recipient is a %s, not a %s.',
                        get_debug_type($recipient),
                        NotifiableInterface::class,
                    ));
                    continue;
                }

                array_push($outcomes, ...$this->deliverSafely($recipient, $notification, $type));
            }
        } catch (\Throwable $e) {
            $this->rethrowIfCallerMustRetry($e);
            // The iterable itself failed (a generator that threw): the
            // recipients it had not yielded yet cannot be reached.
            $this->logFailure($type, '*', null, $e);
            $outcomes[] = new DeliveryOutcome('', null, '*', DeliveryStatus::Failed, DeliveryOutcome::INVALID_RECIPIENT, 'The recipient list failed: ' . self::detail($e));
        }

        return new NotificationReport($type, $outcomes);
    }

    /** @return list<DeliveryOutcome> */
    private function deliverSafely(NotifiableInterface $recipient, NotificationInterface $notification, string $type): array
    {
        try {
            $key = RecipientKey::of($recipient);
        } catch (\Throwable $e) {
            $this->rethrowIfCallerMustRetry($e);
            return [new DeliveryOutcome(self::safeId($recipient), null, '*', DeliveryStatus::Failed, DeliveryOutcome::INVALID_RECIPIENT, self::detail($e))];
        }

        try {
            return $this->inRecipientLocale($recipient, fn (): array => $this->deliver($key, $recipient, $notification, $type));
        } catch (\Throwable $e) {
            $this->rethrowIfCallerMustRetry($e);
            // Nothing below is meant to throw; this keeps the promise if it does.
            $this->logFailure($type, '*', $key, $e);

            return [self::failed($key, '*', DeliveryOutcome::INVALID_NOTIFICATION, self::detail($e))];
        }
    }

    /** @return list<DeliveryOutcome> */
    private function deliver(RecipientKey $key, NotifiableInterface $recipient, NotificationInterface $notification, string $type): array
    {
        try {
            /** @var array<mixed> $via PHP does not check the elements of the list via() returns */
            $via = $notification->via($recipient);
        } catch (\Throwable $e) {
            $this->rethrowIfCallerMustRetry($e);
            $this->logFailure($type, '*', $key, $e);

            return [self::failed($key, '*', DeliveryOutcome::INVALID_NOTIFICATION, 'via() failed: ' . self::detail($e))];
        }

        $outcomes = [];
        $channels = [];
        foreach ($via as $name) {
            if (!is_string($name) || !NotificationNames::isValidChannel($name)) {
                $label = is_string($name) ? NotificationNames::excerpt($name) : get_debug_type($name);
                $outcomes[] = self::failed($key, $label, DeliveryOutcome::INVALID_CHANNEL, sprintf("'%s' is not a valid channel name.", $label));
                continue;
            }
            // A channel named twice delivers once.
            if (!in_array($name, $channels, true)) {
                $channels[] = $name;
            }
        }
        if ($channels === []) {
            return $outcomes;
        }

        if (!NotificationNames::isValidType($type)) {
            foreach ($channels as $name) {
                $outcomes[] = self::failed($key, $name, DeliveryOutcome::INVALID_NOTIFICATION, sprintf("%s has an invalid type() '%s'.", $notification::class, NotificationNames::excerpt($type)));
            }

            return $outcomes;
        }

        $allowed = [];
        if (!$notification instanceof MandatoryNotificationInterface) {
            try {
                $allowed = $this->preferences->allowedChannels($recipient, $type, $channels);
            } catch (\Throwable $e) {
                $this->rethrowIfCallerMustRetry($e);
                // Fail closed: sending what the recipient may have switched off
                // is worse than a visible failure the caller can retry.
                $this->logFailure($type, '*', $key, $e);
                foreach ($channels as $name) {
                    $outcomes[] = self::failed($key, $name, DeliveryOutcome::PREFERENCES_UNAVAILABLE, self::detail($e));
                }

                return $outcomes;
            }
        }

        foreach ($channels as $name) {
            $outcomes[] = ($allowed[$name] ?? true)
                ? $this->deliverOn($name, $key, $recipient, $notification, $type)
                : new DeliveryOutcome($key->recipientId, self::tenantOf($key), $name, DeliveryStatus::Skipped, DeliveryOutcome::OPTED_OUT);
        }

        return $outcomes;
    }

    private function deliverOn(string $name, RecipientKey $key, NotifiableInterface $recipient, NotificationInterface $notification, string $type): DeliveryOutcome
    {
        $tenantId = self::tenantOf($key);

        try {
            $channel = $this->channels->find($name);
        } catch (\Throwable $e) {
            $this->rethrowIfCallerMustRetry($e);
            $this->logFailure($type, $name, $key, $e);

            return self::failed($key, $name, DeliveryOutcome::CHANNEL_UNAVAILABLE, self::detail($e));
        }

        if ($channel === null) {
            $this->log('Notification names an unknown channel', [
                'notification_type' => $type,
                'channel'           => $name,
                'recipient_id'      => $key->recipientId,
                'tenant_id'         => $key->tenantId,
            ]);

            return self::failed($key, $name, DeliveryOutcome::UNKNOWN_CHANNEL, sprintf(
                "No channel is registered as '%s'. Registered: %s.",
                $name,
                implode(', ', $this->channels->names()) ?: 'none',
            ));
        }

        try {
            $channel->send($recipient, $notification);
        } catch (NotificationAlreadyDeliveredException) {
            return new DeliveryOutcome($key->recipientId, $tenantId, $name, DeliveryStatus::Sent, DeliveryOutcome::DUPLICATE);
        } catch (\Throwable $e) {
            $this->rethrowIfCallerMustRetry($e);
            $this->logFailure($type, $name, $key, $e);

            return self::failed($key, $name, DeliveryOutcome::CHANNEL_FAILED, self::detail($e));
        }

        return new DeliveryOutcome($key->recipientId, $tenantId, $name, DeliveryStatus::Sent);
    }

    /**
     * Run $work with the locale context set to the recipient's locale, and put
     * the sender's back afterwards whatever happens. The locale is set as the
     * recipient gives it: semitexa/mail also maps uk_UA or UK onto the site's
     * spelling, which needs semitexa/locale, and this package does not depend on it.
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    private function inRecipientLocale(NotifiableInterface $recipient, \Closure $work): mixed
    {
        $locale = trim((string) $recipient->notificationLocale());
        if ($locale === '' || !isset($this->localeContext)) {
            return $work();
        }

        $sendersLocale = $this->localeContext->getLocale();
        $this->localeContext->setLocale($locale);
        try {
            return $work();
        } finally {
            $this->localeContext->setLocale($sendersLocale);
        }
    }

    private static function failed(RecipientKey $key, string $channel, string $reason, string $detail): DeliveryOutcome
    {
        return new DeliveryOutcome($key->recipientId, self::tenantOf($key), $channel, DeliveryStatus::Failed, $reason, $detail);
    }

    /** The tenant as the report gives it: null for a tenantless recipient. */
    private static function tenantOf(RecipientKey $key): ?string
    {
        return $key->tenantId === '' ? null : $key->tenantId;
    }

    private static function safeId(NotifiableInterface $recipient): string
    {
        try {
            return NotificationNames::excerpt($recipient->notificationRecipientId());
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Rethrow a transient transaction failure while the caller's transaction is
     * open (see the class docblock), wherever in the chain it sits: a channel
     * may have wrapped it.
     */
    private function rethrowIfCallerMustRetry(\Throwable $e): void
    {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if (!$cause instanceof DeadlockException && !$cause instanceof LockWaitTimeoutException && !$cause instanceof ConnectionLostException) {
                continue;
            }

            try {
                $inTransaction = $this->orm->getTransactionManager()->isActive();
            } catch (\Throwable) {
                $inTransaction = false;
            }
            if ($inTransaction) {
                throw $cause;
            }

            return;
        }
    }

    private function logFailure(string $type, string $channel, ?RecipientKey $key, \Throwable $e): void
    {
        $this->log('Notification delivery failed', [
            'notification_type' => $type,
            'channel'           => $channel,
            'recipient_id'      => $key?->recipientId,
            'tenant_id'         => $key?->tenantId,
            'error_class'       => $e::class,
            'error'             => self::detail($e),
        ]);
    }

    /**
     * A logger that throws (a full disk, a broken sink) must not turn a
     * reported failure into an exception out of send().
     *
     * @param array<string, mixed> $context
     */
    private function log(string $message, array $context): void
    {
        try {
            $this->logger->warning($message, $context);
        } catch (\Throwable) {
            // Nowhere left to say it; the report still carries the failure.
        }
    }

    private static function detail(\Throwable $e): string
    {
        return mb_substr($e->getMessage(), 0, self::DETAIL_MAX_LENGTH);
    }
}
