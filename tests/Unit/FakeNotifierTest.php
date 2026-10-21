<?php

declare(strict_types=1);

namespace Semitexa\Notifications\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Notifications\Application\Service\FakeNotifier;
use Semitexa\Notifications\Domain\Contract\NotifierInterface;
use Semitexa\Notifications\Domain\Model\DeliveryOutcome;
use Semitexa\Notifications\Tests\Fixtures\BrokenRecipient;
use Semitexa\Notifications\Tests\Fixtures\OrderShipped;
use Semitexa\Notifications\Tests\Fixtures\Recipient;
use Semitexa\Notifications\Tests\Fixtures\ScriptedNotification;
use Semitexa\Notifications\Tests\Fixtures\SecurityAlert;

final class FakeNotifierTest extends TestCase
{
    #[Test]
    public function it_records_sends_and_answers_assertions(): void
    {
        $notifier = new FakeNotifier();
        self::assertInstanceOf(NotifierInterface::class, $notifier);
        $notifier->assertNothingSent();

        $report = $notifier->send([new Recipient('ada', 'museum'), new Recipient('bob', 'museum')], new OrderShipped(42));

        self::assertCount(4, $report->sent(), 'two recipients x mail + database');
        $notifier->assertSent(OrderShipped::class, static fn (OrderShipped $n) => $n->orderId === 42);
        $notifier->assertSentTo(new Recipient('ada', 'museum'), OrderShipped::class);
        $notifier->assertSentTimes(OrderShipped::class, 2);
        $notifier->assertNotSent(SecurityAlert::class);
        self::assertSame([42], array_map(static fn (OrderShipped $n) => $n->orderId, array_slice($notifier->sentOf(OrderShipped::class), 0, 1)));
    }

    #[Test]
    public function it_reports_what_the_real_notifier_would_refuse(): void
    {
        $notifier = new FakeNotifier(['mail', 'database', 'chat']);

        $report = $notifier->send(new Recipient('ada'), ScriptedNotification::of('orders.shipped', ['chat', 'sms', '42']));
        self::assertTrue($report->outcomeFor('ada', 'chat')?->isSent());
        self::assertSame(DeliveryOutcome::UNKNOWN_CHANNEL, $report->outcomeFor('ada', 'sms')?->reason);
        self::assertSame(DeliveryOutcome::INVALID_CHANNEL, $report->outcomeFor('ada', '42')?->reason);

        $bad = $notifier->send(new Recipient('ada'), ScriptedNotification::of("orders.shipped\n", ['chat']));
        self::assertSame(DeliveryOutcome::INVALID_NOTIFICATION, $bad->outcomes[0]->reason);
        $notifier->assertSentTimes(ScriptedNotification::class, 2);
    }

    #[Test]
    public function it_refuses_the_recipients_and_names_the_real_notifier_refuses_and_never_throws(): void
    {
        $notifier = new FakeNotifier();

        /** @var list<Recipient> $recipients what PHP does not check: one of them is a string */
        $recipients = [new Recipient(''), new Recipient("ada\0x"), new Recipient('ada', ' museum'), 'not a recipient', new BrokenRecipient(), new Recipient('bob')];
        $report = $notifier->send(
            $recipients,
            ScriptedNotification::of('orders.shipped', ['database', 42]),
        );

        self::assertSame(
            array_fill(0, 5, DeliveryOutcome::INVALID_RECIPIENT),
            array_map(static fn (DeliveryOutcome $o): ?string => $o->reason, array_slice($report->outcomes, 0, 5)),
        );
        self::assertTrue($report->outcomeFor('bob', 'database')?->isSent());
        self::assertSame(DeliveryOutcome::INVALID_CHANNEL, $report->outcomeFor('bob', 'int')?->reason, 'labelled by its type, like the real notifier');
        $notifier->assertSentTimes(ScriptedNotification::class, 1);

        $throwingType = new ScriptedNotification(static fn (): string => throw new \RuntimeException('no type'), static fn (): array => ['database']);
        self::assertSame(DeliveryOutcome::INVALID_NOTIFICATION, $notifier->send(new Recipient('ada'), $throwingType)->outcomes[0]->reason);
        $throwingVia = new ScriptedNotification(static fn (): string => 'orders.shipped', static fn (): array => throw new \RuntimeException('no via'));
        self::assertSame(DeliveryOutcome::INVALID_NOTIFICATION, $notifier->send(new Recipient('ada'), $throwingVia)->outcomes[0]->reason);
        $notifier->assertSentTimes(ScriptedNotification::class, 1);
    }

    #[Test]
    public function a_recipient_is_matched_by_tenant_and_id(): void
    {
        $notifier = new FakeNotifier();
        $notifier->send(new Recipient('ada', 'museum'), new OrderShipped(1));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('was not sent to clinic/ada');
        $notifier->assertSentTo(new Recipient('ada', 'clinic'), OrderShipped::class);
    }

    #[Test]
    public function failed_assertions_say_what_was_sent(): void
    {
        $notifier = new FakeNotifier();
        $notifier->send(new Recipient('ada'), new SecurityAlert());

        try {
            $notifier->assertSent(OrderShipped::class);
            self::fail('must throw');
        } catch (\LogicException $e) {
            self::assertStringContainsString(SecurityAlert::class . ' to ada', $e->getMessage());
        }

        $notifier->reset();
        $notifier->assertNothingSent();
    }
}
