<?php

declare(strict_types=1);

namespace Semitexa\Notifications\Application\Service;

use Semitexa\Notifications\Domain\Contract\NotifiableInterface;
use Semitexa\Notifications\Domain\Contract\NotificationInterface;
use Semitexa\Notifications\Domain\Contract\NotifierInterface;
use Semitexa\Notifications\Domain\Enum\DeliveryStatus;
use Semitexa\Notifications\Domain\Model\DeliveryOutcome;
use Semitexa\Notifications\Domain\Model\NotificationReport;
use Semitexa\Notifications\Domain\Model\RecipientKey;
use Semitexa\Notifications\Domain\Service\NotificationNames;

/**
 * A NotifierInterface for tests: it records every notification and recipient
 * and runs no channel, so a test can say what was sent without mail or tables.
 *
 *     $notifier = new FakeNotifier();
 *     // hand $notifier to the code where it expects NotifierInterface, run it, then:
 *     $notifier->assertSentTo($customer, OrderShipped::class, fn (OrderShipped $n) => $n->orderId === 42);
 *     $notifier->assertNotSent(OrderRefunded::class);
 *
 * The report checks what the real notifier checks, without running anything,
 * and like it never throws: a type() or via() that throws, a recipient that is
 * not one or whose id the real notifier refuses, a malformed type or channel
 * name, or a channel not in the list given to the constructor (by default the
 * built-in `mail` and `database`) is reported as failed; every other channel
 * via() names as sent. Preferences are not consulted. A send is recorded for
 * every recipient the real notifier would have tried - a valid recipient whose
 * via() answered - whatever its channels' outcome. A failed assertion throws
 * \LogicException.
 */
final class FakeNotifier implements NotifierInterface
{
    /** @var list<array{recipient: NotifiableInterface, notification: NotificationInterface, channels: list<string>}> */
    private array $sent = [];

    /** @param list<string> $channels the channel names this fake treats as registered */
    public function __construct(
        private readonly array $channels = ['mail', 'database'],
    ) {}

    public function send(NotifiableInterface|iterable $to, NotificationInterface $notification): NotificationReport
    {
        try {
            $type = $notification->type();
        } catch (\Throwable $e) {
            return new NotificationReport('', [self::failure('', null, '*', DeliveryOutcome::INVALID_NOTIFICATION, 'type() failed: ' . $e->getMessage())]);
        }

        $outcomes = [];
        /** @var iterable<mixed> $recipients */
        $recipients = $to instanceof NotifiableInterface ? [$to] : $to;
        try {
            foreach ($recipients as $recipient) {
                array_push($outcomes, ...$this->record($recipient, $notification, $type));
            }
        } catch (\Throwable $e) {
            $outcomes[] = self::failure('', null, '*', DeliveryOutcome::INVALID_RECIPIENT, 'The recipient list failed: ' . $e->getMessage());
        }

        return new NotificationReport($type, $outcomes);
    }

    /**
     * @template T of NotificationInterface
     * @param class-string<T> $notificationClass
     * @return list<T>
     */
    public function sentOf(string $notificationClass): array
    {
        $found = [];
        foreach ($this->sent as $entry) {
            if ($entry['notification'] instanceof $notificationClass) {
                $found[] = $entry['notification'];
            }
        }

        return $found;
    }

    /**
     * Every recorded send: one entry per recipient.
     *
     * @return list<array{recipient: NotifiableInterface, notification: NotificationInterface, channels: list<string>}>
     */
    public function sent(): array
    {
        return $this->sent;
    }

    /**
     * @template T of NotificationInterface
     * @param class-string<T> $notificationClass
     * @param (\Closure(T, NotifiableInterface): bool)|null $matches
     */
    public function assertSent(string $notificationClass, ?\Closure $matches = null): void
    {
        foreach ($this->sent as $entry) {
            if ($entry['notification'] instanceof $notificationClass
                && ($matches === null || $matches($entry['notification'], $entry['recipient']))) {
                return;
            }
        }

        throw new \LogicException(sprintf(
            '%s was not sent%s; sent: %s',
            $notificationClass,
            $matches === null ? '' : ' with what the test expected',
            $this->summary(),
        ));
    }

    /**
     * Sent to this recipient - the same tenant and recipient id, not merely an equal object.
     *
     * @template T of NotificationInterface
     * @param class-string<T> $notificationClass
     * @param (\Closure(T): bool)|null $matches
     */
    public function assertSentTo(NotifiableInterface $recipient, string $notificationClass, ?\Closure $matches = null): void
    {
        foreach ($this->sent as $entry) {
            if ($entry['notification'] instanceof $notificationClass
                && self::sameRecipient($entry['recipient'], $recipient)
                && ($matches === null || $matches($entry['notification']))) {
                return;
            }
        }

        throw new \LogicException(sprintf(
            '%s was not sent to %s%s; sent: %s',
            $notificationClass,
            self::label($recipient),
            $matches === null ? '' : ' with what the test expected',
            $this->summary(),
        ));
    }

    /** @param class-string<NotificationInterface> $notificationClass */
    public function assertSentTimes(string $notificationClass, int $times): void
    {
        $count = 0;
        foreach ($this->sent as $entry) {
            if ($entry['notification'] instanceof $notificationClass) {
                $count++;
            }
        }
        if ($count !== $times) {
            throw new \LogicException(sprintf('%s was sent to %d recipients, expected %d.', $notificationClass, $count, $times));
        }
    }

    /** @param class-string<NotificationInterface> $notificationClass */
    public function assertNotSent(string $notificationClass): void
    {
        if ($this->sentOf($notificationClass) !== []) {
            throw new \LogicException(sprintf('%s was sent; sent: %s', $notificationClass, $this->summary()));
        }
    }

    public function assertNothingSent(): void
    {
        if ($this->sent !== []) {
            throw new \LogicException('Notifications were sent: ' . $this->summary());
        }
    }

    public function reset(): void
    {
        $this->sent = [];
    }

    /** @return list<DeliveryOutcome> */
    private function record(mixed $recipient, NotificationInterface $notification, string $type): array
    {
        if (!$recipient instanceof NotifiableInterface) {
            return [self::failure('', null, '*', DeliveryOutcome::INVALID_RECIPIENT, sprintf('A recipient is a %s, not a %s.', get_debug_type($recipient), NotifiableInterface::class))];
        }

        try {
            $key = RecipientKey::of($recipient);
        } catch (\Throwable $e) {
            return [self::failure('', null, '*', DeliveryOutcome::INVALID_RECIPIENT, $e->getMessage())];
        }
        $tenant = $key->tenantId === '' ? null : $key->tenantId;

        try {
            /** @var array<mixed> $via PHP does not check the elements */
            $via = $notification->via($recipient);
        } catch (\Throwable $e) {
            return [self::failure($key->recipientId, $tenant, '*', DeliveryOutcome::INVALID_NOTIFICATION, 'via() failed: ' . $e->getMessage())];
        }

        $outcomes = [];
        $channels = [];
        foreach ($via as $name) {
            if (!is_string($name) || !NotificationNames::isValidChannel($name)) {
                $label = is_string($name) ? NotificationNames::excerpt($name) : get_debug_type($name);
                $outcomes[] = self::failure($key->recipientId, $tenant, $label, DeliveryOutcome::INVALID_CHANNEL, "'{$label}' is not a valid channel name.");
                continue;
            }
            if (!in_array($name, $channels, true)) {
                $channels[] = $name;
            }
        }
        $this->sent[] = ['recipient' => $recipient, 'notification' => $notification, 'channels' => $channels];

        foreach ($channels as $channel) {
            $outcomes[] = match (true) {
                !NotificationNames::isValidType($type) => self::failure($key->recipientId, $tenant, $channel, DeliveryOutcome::INVALID_NOTIFICATION, sprintf("Invalid type '%s'.", NotificationNames::excerpt($type))),
                !in_array($channel, $this->channels, true) => self::failure($key->recipientId, $tenant, $channel, DeliveryOutcome::UNKNOWN_CHANNEL, "No channel is registered as '{$channel}'."),
                default => new DeliveryOutcome($key->recipientId, $tenant, $channel, DeliveryStatus::Sent),
            };
        }

        return $outcomes;
    }

    private static function failure(string $recipientId, ?string $tenantId, string $channel, string $reason, string $detail): DeliveryOutcome
    {
        return new DeliveryOutcome($recipientId, $tenantId, $channel, DeliveryStatus::Failed, $reason, $detail);
    }

    private static function sameRecipient(NotifiableInterface $a, NotifiableInterface $b): bool
    {
        return $a->notificationRecipientId() === $b->notificationRecipientId()
            && ($a->notificationTenantId() ?? '') === ($b->notificationTenantId() ?? '');
    }

    private static function label(NotifiableInterface $recipient): string
    {
        $tenant = $recipient->notificationTenantId();

        return ($tenant === null || $tenant === '' ? '' : $tenant . '/') . $recipient->notificationRecipientId();
    }

    private function summary(): string
    {
        if ($this->sent === []) {
            return 'nothing';
        }

        return implode(', ', array_map(
            static fn (array $entry): string => $entry['notification']::class . ' to ' . self::label($entry['recipient']),
            $this->sent,
        ));
    }
}
