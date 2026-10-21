<?php

declare(strict_types=1);

namespace Semitexa\Notifications\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Notifications\Application\Service\NotificationChannelRegistry;
use Semitexa\Notifications\Application\Service\NotificationPreferences;
use Semitexa\Notifications\Application\Service\Notifier;
use Semitexa\Notifications\Attribute\AsNotificationChannel;
use Semitexa\Notifications\Domain\Enum\DeliveryStatus;
use Semitexa\Notifications\Domain\Model\DeliveryOutcome;
use Semitexa\Notifications\Tests\Fixtures\ArrayContainer;
use Semitexa\Notifications\Tests\Fixtures\Channel\ChatChannel;
use Semitexa\Notifications\Tests\Fixtures\Channel\PushChannel;
use Semitexa\Notifications\Tests\Fixtures\NotificationTables;
use Semitexa\Notifications\Tests\Fixtures\OrderShipped;
use Semitexa\Notifications\Tests\Fixtures\Recipient;
use Semitexa\Notifications\Tests\Fixtures\RecordingChannel;
use Semitexa\Notifications\Tests\Fixtures\RecordingLogger;
use Semitexa\Notifications\Tests\Fixtures\SecurityAlert;
use Semitexa\Notifications\Tests\Fixtures\StubClassDiscovery;
use Semitexa\Orm\OrmManager;
use Semitexa\Testing\Traits\BuildsContainerManagedObjects;

final class NotifierTest extends TestCase
{
    use BuildsContainerManagedObjects;

    private OrmManager $orm;
    private NotificationPreferences $preferences;
    private RecordingLogger $logger;
    private RecordingChannel $chat;
    private RecordingChannel $push;

    protected function setUp(): void
    {
        $this->orm = NotificationTables::orm();
        $this->preferences = self::createWithDependencies(NotificationPreferences::class, ['orm' => $this->orm]);
        $this->logger = new RecordingLogger();
        $this->chat = new RecordingChannel();
        $this->push = new RecordingChannel(failFor: ['bob']);
    }

    protected function tearDown(): void
    {
        $this->orm->shutdown();
    }

    #[Test]
    public function every_channel_via_names_is_run_for_every_recipient(): void
    {
        $report = $this->notifier()->send(
            [new Recipient('ada', 'museum'), new Recipient('cy', 'museum')],
            new OrderShipped(7, ['chat']),
        );

        self::assertSame('orders.shipped', $report->type);
        self::assertCount(2, $report->sent());
        self::assertFalse($report->hasFailures());
        self::assertSame(['ada', 'cy'], array_column($this->chat->sent, 'recipient'));
    }

    #[Test]
    public function one_failing_channel_or_recipient_stops_nothing_else(): void
    {
        $report = $this->notifier()->send(
            [new Recipient('bob', 'museum'), new Recipient('ada', 'museum')],
            new OrderShipped(7, ['push', 'chat']),
        );

        $failed = $report->outcomeFor('bob', 'push', 'museum');
        self::assertNotNull($failed);
        self::assertSame(DeliveryStatus::Failed, $failed->status);
        self::assertSame(DeliveryOutcome::CHANNEL_FAILED, $failed->reason);
        self::assertStringContainsString('gateway refused', (string) $failed->detail);

        self::assertTrue($report->outcomeFor('bob', 'chat', 'museum')?->isSent(), 'the other channel of the failing recipient still ran');
        self::assertTrue($report->outcomeFor('ada', 'push', 'museum')?->isSent(), 'the next recipient still got the failing channel');
        self::assertSame(['bob', 'ada'], array_column($this->chat->sent, 'recipient'));
        self::assertCount(1, $report->failed());
    }

    #[Test]
    public function a_failure_is_logged_without_the_notification_s_content(): void
    {
        $this->notifier()->send(new Recipient('bob', 'museum'), new OrderShipped(7, ['push']));

        self::assertCount(1, $this->logger->records);
        $context = $this->logger->records[0]['context'];
        self::assertSame('orders.shipped', $context['notification_type']);
        self::assertSame('push', $context['channel']);
        self::assertSame('bob', $context['recipient_id']);
        self::assertSame('museum', $context['tenant_id']);
        self::assertSame(\RuntimeException::class, $context['error_class']);
        self::assertStringNotContainsString('SECRET-BODY', $this->logger->dump());
        self::assertStringNotContainsString('has shipped', $this->logger->dump());
    }

    #[Test]
    public function an_unknown_channel_is_a_reported_failure_not_an_exception(): void
    {
        $report = $this->notifier()->send(new Recipient('ada'), new OrderShipped(7, ['carrier-pigeon', 'chat']));

        $unknown = $report->outcomeFor('ada', 'carrier-pigeon');
        self::assertSame(DeliveryStatus::Failed, $unknown?->status);
        self::assertSame(DeliveryOutcome::UNKNOWN_CHANNEL, $unknown->reason);
        self::assertStringContainsString('chat, push', (string) $unknown->detail, 'the detail lists what is registered');
        self::assertTrue($report->outcomeFor('ada', 'chat')?->isSent());
    }

    #[Test]
    public function a_channel_the_recipient_switched_off_is_skipped(): void
    {
        $ada = new Recipient('ada', 'museum');
        $this->preferences->set($ada, 'orders.shipped', 'chat', false);

        $report = $this->notifier()->send($ada, new OrderShipped(7, ['chat', 'push']));

        $skipped = $report->outcomeFor('ada', 'chat', 'museum');
        self::assertSame(DeliveryStatus::Skipped, $skipped?->status);
        self::assertSame(DeliveryOutcome::OPTED_OUT, $skipped->reason);
        self::assertSame([], $this->chat->sent);
        self::assertTrue($report->outcomeFor('ada', 'push', 'museum')?->isSent());
    }

    #[Test]
    public function an_opt_out_in_one_tenant_does_not_silence_the_same_id_in_another(): void
    {
        $this->preferences->set(new Recipient('ada', 'museum'), '*', 'chat', false);

        $report = $this->notifier()->send(
            [new Recipient('ada', 'museum'), new Recipient('ada', 'clinic')],
            new OrderShipped(7, ['chat']),
        );

        self::assertSame(DeliveryStatus::Skipped, $report->outcomeFor('ada', 'chat', 'museum')?->status);
        self::assertSame(DeliveryStatus::Sent, $report->outcomeFor('ada', 'chat', 'clinic')?->status);
        self::assertNull($report->outcomeFor('ada', 'chat'), 'no tenant means the tenantless ada, not any ada');
        self::assertCount(2, $report->outcomesInAnyTenant('ada', 'chat'));
    }

    #[Test]
    public function a_mandatory_notification_ignores_opt_outs(): void
    {
        $ada = new Recipient('ada', 'museum');
        $this->preferences->set($ada, '*', 'chat', false);
        $this->preferences->set($ada, 'security.password_changed', 'chat', false);

        $report = $this->notifier()->send($ada, new SecurityAlert(['chat']));

        self::assertTrue($report->outcomeFor('ada', 'chat', 'museum')?->isSent());
        self::assertCount(1, $this->chat->sent);
    }

    #[Test]
    public function a_channel_named_twice_delivers_once(): void
    {
        $report = $this->notifier()->send(new Recipient('ada'), new OrderShipped(7, ['chat', 'chat']));

        self::assertCount(1, $report->outcomes);
        self::assertCount(1, $this->chat->sent);
    }

    #[Test]
    public function unreadable_preferences_fail_closed_for_that_recipient(): void
    {
        $this->orm->getAdapter()->execute('DROP TABLE notifications_preferences');

        $report = $this->notifier()->send(new Recipient('ada'), new OrderShipped(7, ['chat']));

        self::assertSame(DeliveryOutcome::PREFERENCES_UNAVAILABLE, $report->outcomeFor('ada', 'chat')?->reason);
        self::assertSame([], $this->chat->sent, 'nothing is sent that the recipient may have switched off');

        $mandatory = $this->notifier()->send(new Recipient('ada'), new SecurityAlert(['chat']));
        self::assertTrue($mandatory->outcomeFor('ada', 'chat')?->isSent(), 'a mandatory notification never asks');
    }

    #[Test]
    public function a_recipient_without_a_usable_id_fails_alone(): void
    {
        $report = $this->notifier()->send(
            [new Recipient(''), new Recipient(str_repeat('x', 65)), new Recipient('ada')],
            new OrderShipped(7, ['chat']),
        );

        self::assertSame(DeliveryOutcome::INVALID_RECIPIENT, $report->outcomes[0]->reason);
        self::assertSame(DeliveryOutcome::INVALID_RECIPIENT, $report->outcomes[1]->reason);
        self::assertTrue($report->outcomeFor('ada', 'chat')?->isSent());
        self::assertSame(['ada'], array_column($this->chat->sent, 'recipient'));
    }

    #[Test]
    public function something_that_is_not_a_recipient_is_reported_and_skipped(): void
    {
        // What PHP does not check: an iterable promised to hold recipients that holds something else.
        /** @var iterable<Recipient> $recipients */
        $recipients = (static fn () => yield from ['not a recipient', new Recipient('ada')])();
        $report = $this->notifier()->send($recipients, new OrderShipped(7, ['chat']));

        self::assertSame(DeliveryOutcome::INVALID_RECIPIENT, $report->outcomes[0]->reason);
        self::assertTrue($report->outcomeFor('ada', 'chat')?->isSent());
    }

    #[Test]
    public function a_channel_that_cannot_be_built_fails_its_own_deliveries_only(): void
    {
        $notifier = $this->notifier(container: new ArrayContainer([ChatChannel::class => $this->chat]));

        $report = $notifier->send(new Recipient('ada'), new OrderShipped(7, ['push', 'chat']));

        self::assertSame(DeliveryOutcome::CHANNEL_UNAVAILABLE, $report->outcomeFor('ada', 'push')?->reason);
        self::assertTrue($report->outcomeFor('ada', 'chat')?->isSent());
    }

    private function notifier(?ArrayContainer $container = null): Notifier
    {
        $registry = self::createWithDependencies(NotificationChannelRegistry::class, [
            'classDiscovery' => new StubClassDiscovery([AsNotificationChannel::class => [ChatChannel::class, PushChannel::class]]),
            'container'      => $container ?? new ArrayContainer([ChatChannel::class => $this->chat, PushChannel::class => $this->push]),
        ]);

        return self::createWithDependencies(Notifier::class, [
            'channels'    => $registry,
            'preferences' => $this->preferences,
            'logger'      => $this->logger,
            'orm'         => $this->orm,
        ]);
    }
}
