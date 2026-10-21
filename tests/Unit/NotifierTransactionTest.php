<?php

declare(strict_types=1);

namespace Semitexa\Notifications\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Log\LoggerInterface;
use Semitexa\Notifications\Application\Service\NotificationChannelRegistry;
use Semitexa\Notifications\Application\Service\NotificationPreferences;
use Semitexa\Notifications\Application\Service\Notifier;
use Semitexa\Notifications\Attribute\AsNotificationChannel;
use Semitexa\Notifications\Domain\Model\DeliveryOutcome;
use Semitexa\Notifications\Tests\Fixtures\ArrayContainer;
use Semitexa\Notifications\Tests\Fixtures\Channel\ChatChannel;
use Semitexa\Notifications\Tests\Fixtures\NotificationTables;
use Semitexa\Notifications\Tests\Fixtures\Recipient;
use Semitexa\Notifications\Tests\Fixtures\RecordingLogger;
use Semitexa\Notifications\Tests\Fixtures\ScriptedNotification;
use Semitexa\Notifications\Tests\Fixtures\StubClassDiscovery;
use Semitexa\Notifications\Tests\Fixtures\ThrowingChannel;
use Semitexa\Notifications\Tests\Fixtures\ThrowingLogger;
use Semitexa\Orm\Exception\ConnectionLostException;
use Semitexa\Orm\Exception\DeadlockException;
use Semitexa\Orm\Exception\LockWaitTimeoutException;
use Semitexa\Orm\OrmManager;
use Semitexa\Testing\Traits\BuildsContainerManagedObjects;

/**
 * The one exception to "send() never throws": a transient transaction failure
 * while the caller's transaction is open goes back to the caller, so its unit
 * can be replayed. And a broken logger never becomes an exception.
 */
final class NotifierTransactionTest extends TestCase
{
    use BuildsContainerManagedObjects;

    private OrmManager $orm;

    protected function setUp(): void
    {
        $this->orm = NotificationTables::orm();
    }

    protected function tearDown(): void
    {
        $this->orm->shutdown();
    }

    #[Test]
    public function a_deadlock_inside_the_caller_s_transaction_is_rethrown_for_its_retry(): void
    {
        foreach ([
            new DeadlockException('Deadlock found when trying to get lock', '40001', 1213),
            new LockWaitTimeoutException('Lock wait timeout exceeded', 'HY000', 1205),
            new ConnectionLostException('MySQL server has gone away', 'HY000', 2006),
        ] as $transient) {
            $notifier = $this->notifier(new ThrowingChannel($transient));
            try {
                $this->orm->getTransactionManager()->run(
                    static fn () => $notifier->send(new Recipient('ada'), ScriptedNotification::of('orders.shipped', ['chat'])),
                );
                self::fail($transient::class . ' must reach the caller\'s transaction');
            } catch (DeadlockException | LockWaitTimeoutException | ConnectionLostException $e) {
                self::assertSame($transient, $e);
            }
        }
    }

    #[Test]
    public function a_wrapped_deadlock_is_found_and_rethrown_itself(): void
    {
        $deadlock = new DeadlockException('Deadlock found', '40001', 1213);
        $notifier = $this->notifier(new ThrowingChannel(new \RuntimeException('the chat bot\'s outbox failed', 0, $deadlock)));

        $this->expectExceptionObject($deadlock);
        $this->orm->getTransactionManager()->run(
            static fn () => $notifier->send(new Recipient('ada'), ScriptedNotification::of('orders.shipped', ['chat'])),
        );
    }

    #[Test]
    public function outside_a_transaction_a_deadlock_is_a_channel_failure(): void
    {
        $notifier = $this->notifier(new ThrowingChannel(new DeadlockException('Deadlock found', '40001', 1213)));

        $report = $notifier->send(new Recipient('ada'), ScriptedNotification::of('orders.shipped', ['chat']));

        self::assertSame(DeliveryOutcome::CHANNEL_FAILED, $report->outcomeFor('ada', 'chat')?->reason);
    }

    #[Test]
    public function any_other_failure_inside_a_transaction_is_still_reported(): void
    {
        $notifier = $this->notifier(new ThrowingChannel(new \RuntimeException('gateway refused')));

        $report = $this->orm->getTransactionManager()->run(
            static fn () => $notifier->send(new Recipient('ada'), ScriptedNotification::of('orders.shipped', ['chat'])),
        );

        self::assertSame(DeliveryOutcome::CHANNEL_FAILED, $report->outcomeFor('ada', 'chat')?->reason);
    }

    #[Test]
    public function a_logger_that_throws_does_not_escape_send(): void
    {
        $notifier = $this->notifier(new ThrowingChannel(new \RuntimeException('gateway refused')), new ThrowingLogger());

        $report = $notifier->send(new Recipient('ada'), ScriptedNotification::of('orders.shipped', ['chat', 'sms']));

        self::assertSame(DeliveryOutcome::CHANNEL_FAILED, $report->outcomeFor('ada', 'chat')?->reason);
        self::assertSame(DeliveryOutcome::UNKNOWN_CHANNEL, $report->outcomeFor('ada', 'sms')?->reason);
    }

    private function notifier(ThrowingChannel $channel, ?LoggerInterface $logger = null): Notifier
    {
        $registry = self::createWithDependencies(NotificationChannelRegistry::class, [
            'classDiscovery' => new StubClassDiscovery([AsNotificationChannel::class => [ChatChannel::class]]),
            'container'      => new ArrayContainer([ChatChannel::class => $channel]),
        ]);

        return self::createWithDependencies(Notifier::class, [
            'channels'    => $registry,
            'preferences' => self::createWithDependencies(NotificationPreferences::class, ['orm' => $this->orm]),
            'logger'      => $logger ?? new RecordingLogger(),
            'orm'         => $this->orm,
        ]);
    }
}
