<?php

declare(strict_types=1);

namespace Semitexa\Notifications\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Notifications\Application\Service\Channel\DatabaseNotificationChannel;
use Semitexa\Notifications\Application\Service\NotificationChannelRegistry;
use Semitexa\Notifications\Application\Service\NotificationInbox;
use Semitexa\Notifications\Application\Service\NotificationPreferences;
use Semitexa\Notifications\Application\Service\Notifier;
use Semitexa\Notifications\Attribute\AsNotificationChannel;
use Semitexa\Notifications\Domain\Enum\DeliveryStatus;
use Semitexa\Notifications\Domain\Model\DeliveryOutcome;
use Semitexa\Notifications\Tests\Fixtures\ArrayContainer;
use Semitexa\Notifications\Tests\Fixtures\BrokenRecipient;
use Semitexa\Notifications\Tests\Fixtures\Channel\ChatChannel;
use Semitexa\Notifications\Tests\Fixtures\Channel\PushChannel;
use Semitexa\Notifications\Tests\Fixtures\IdempotentOrderShipped;
use Semitexa\Notifications\Tests\Fixtures\LocaleRecordingChannel;
use Semitexa\Notifications\Tests\Fixtures\NotificationTables;
use Semitexa\Notifications\Tests\Fixtures\Recipient;
use Semitexa\Notifications\Tests\Fixtures\RecordingChannel;
use Semitexa\Notifications\Tests\Fixtures\RecordingLocaleContext;
use Semitexa\Notifications\Tests\Fixtures\RecordingLogger;
use Semitexa\Notifications\Tests\Fixtures\ScriptedNotification;
use Semitexa\Notifications\Tests\Fixtures\StubClassDiscovery;
use Semitexa\Orm\OrmManager;
use Semitexa\Testing\Traits\BuildsContainerManagedObjects;

/**
 * send() never throws, whatever the notification, recipient or channel does;
 * it writes in the recipient's locale; and an idempotent notification sent
 * again is not delivered twice.
 */
final class NotifierRobustnessTest extends TestCase
{
    use BuildsContainerManagedObjects;

    private OrmManager $orm;
    private RecordingChannel $chat;
    private RecordingLocaleContext $locale;

    protected function setUp(): void
    {
        $this->orm = NotificationTables::orm();
        $this->chat = new RecordingChannel();
        $this->locale = new RecordingLocaleContext('en');
    }

    protected function tearDown(): void
    {
        $this->orm->shutdown();
    }

    #[Test]
    public function a_numeric_or_non_string_channel_name_is_a_reported_failure(): void
    {
        $report = $this->notifier()->send(new Recipient('ada'), ScriptedNotification::of('orders.shipped', ['42', 42, null, 'Chat', 'chat']));

        $reasons = array_map(static fn (DeliveryOutcome $o): ?string => $o->reason, $report->failed());
        self::assertSame(array_fill(0, 4, DeliveryOutcome::INVALID_CHANNEL), $reasons);
        self::assertTrue($report->outcomeFor('ada', 'chat')?->isSent(), 'the valid channel still ran');
    }

    #[Test]
    public function a_type_with_a_trailing_newline_is_invalid(): void
    {
        $report = $this->notifier()->send(new Recipient('ada'), ScriptedNotification::of("orders.shipped\n", ['chat']));

        self::assertSame(DeliveryOutcome::INVALID_NOTIFICATION, $report->outcomes[0]->reason);
        self::assertSame([], $this->chat->sent);
    }

    #[Test]
    public function a_throwing_type_via_recipient_or_recipient_list_is_reported_not_thrown(): void
    {
        $throwingType = new ScriptedNotification(static fn (): string => throw new \RuntimeException('no type'), static fn (): array => ['chat']);
        $report = $this->notifier()->send(new Recipient('ada'), $throwingType);
        self::assertSame(DeliveryOutcome::INVALID_NOTIFICATION, $report->outcomes[0]->reason);
        self::assertStringContainsString('no type', (string) $report->outcomes[0]->detail);

        $throwingVia = new ScriptedNotification(static fn (): string => 'orders.shipped', static fn (): array => throw new \RuntimeException('no via'));
        self::assertSame(DeliveryOutcome::INVALID_NOTIFICATION, $this->notifier()->send(new Recipient('ada'), $throwingVia)->outcomes[0]->reason);

        $report = $this->notifier()->send([new BrokenRecipient(), new Recipient('ada')], ScriptedNotification::of('orders.shipped', ['chat']));
        self::assertSame(DeliveryOutcome::INVALID_RECIPIENT, $report->outcomes[0]->reason);
        self::assertTrue($report->outcomeFor('ada', 'chat')?->isSent(), 'the next recipient still got it');

        $generator = (static function () {
            yield new Recipient('ada');
            throw new \RuntimeException('the cursor died');
        })();
        /** @var iterable<Recipient> $generator */
        $report = $this->notifier()->send($generator, ScriptedNotification::of('orders.shipped', ['chat']));
        self::assertTrue($report->outcomeFor('ada', 'chat')?->isSent());
        self::assertSame(DeliveryOutcome::INVALID_RECIPIENT, $report->outcomes[1]->reason);
        self::assertStringContainsString('the cursor died', (string) $report->outcomes[1]->detail);
    }

    #[Test]
    public function channels_run_in_the_recipient_s_locale_and_the_sender_s_comes_back(): void
    {
        $channel = new LocaleRecordingChannel($this->locale, failFor: ['bob']);
        $notifier = $this->notifier(new ArrayContainer([ChatChannel::class => $channel]));

        $notifier->send(
            [new Recipient('ada', locale: 'uk'), new Recipient('bob', locale: 'de'), new Recipient('cy'), new Recipient('dan', locale: ' ')],
            ScriptedNotification::of('orders.shipped', ['chat']),
        );

        self::assertSame(['uk', 'de', 'en', 'en'], $channel->locales, 'no locale (or a blank one) keeps the sender\'s');
        self::assertSame('en', $this->locale->getLocale(), 'the sender\'s locale is back, after a failing channel too');
    }

    #[Test]
    public function an_idempotent_notification_sent_again_is_stored_once_and_reported_as_a_duplicate(): void
    {
        $inbox = self::createWithDependencies(NotificationInbox::class, ['orm' => $this->orm]);
        $database = self::createWithDependencies(DatabaseNotificationChannel::class, ['inbox' => $inbox]);
        $notifier = $this->notifier(new ArrayContainer([ChatChannel::class => $database]));
        $ada = new Recipient('ada', 'museum');
        $bob = new Recipient('bob', 'museum');
        $shipped = new IdempotentOrderShipped(7, ['chat']);

        $first = $notifier->send([$ada, $bob], $shipped);
        $again = $notifier->send([$ada, $bob], $shipped);

        self::assertNull($first->outcomeFor('ada', 'chat', 'museum')?->reason);
        $repeat = $again->outcomeFor('ada', 'chat', 'museum');
        self::assertNotNull($repeat);
        self::assertSame(DeliveryStatus::Sent, $repeat->status);
        self::assertSame(DeliveryOutcome::DUPLICATE, $repeat->reason);
        self::assertFalse($again->hasFailures());
        self::assertSame(1, $inbox->countUnread($ada));
        self::assertSame(1, $inbox->countUnread($bob), 'the key is per recipient: bob got his own');

        $notifier->send($ada, new IdempotentOrderShipped(8, ['chat']));
        self::assertSame(2, $inbox->countUnread($ada), 'another key is another notification');
    }

    #[Test]
    public function a_duplicated_project_channel_fails_only_itself_where_nothing_booted(): void
    {
        $registry = self::createWithDependencies(NotificationChannelRegistry::class, [
            'classDiscovery' => new StubClassDiscovery([AsNotificationChannel::class => [
                ChatChannel::class,
                \Semitexa\Notifications\Tests\Fixtures\Channel\AnotherChatChannel::class,
                PushChannel::class,
            ]]),
            'container' => new ArrayContainer([PushChannel::class => $this->chat]),
        ]);
        $notifier = self::createWithDependencies(Notifier::class, [
            'channels'    => $registry,
            'preferences' => self::createWithDependencies(NotificationPreferences::class, ['orm' => $this->orm]),
            'logger'      => new RecordingLogger(),
            'orm'         => $this->orm,
        ]);

        $report = $notifier->send(new Recipient('ada'), ScriptedNotification::of('orders.shipped', ['chat', 'push']));

        self::assertSame(DeliveryOutcome::CHANNEL_UNAVAILABLE, $report->outcomeFor('ada', 'chat')?->reason);
        self::assertTrue($report->outcomeFor('ada', 'push')?->isSent());
    }

    private function notifier(?ArrayContainer $container = null): Notifier
    {
        $registry = self::createWithDependencies(NotificationChannelRegistry::class, [
            'classDiscovery' => new StubClassDiscovery([AsNotificationChannel::class => [ChatChannel::class]]),
            'container'      => $container ?? new ArrayContainer([ChatChannel::class => $this->chat]),
        ]);

        return self::createWithDependencies(Notifier::class, [
            'channels'      => $registry,
            'preferences'   => self::createWithDependencies(NotificationPreferences::class, ['orm' => $this->orm]),
            'logger'        => new RecordingLogger(),
            'localeContext' => $this->locale,
            'orm'           => $this->orm,
        ]);
    }
}
