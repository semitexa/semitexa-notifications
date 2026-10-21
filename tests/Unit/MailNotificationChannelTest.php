<?php

declare(strict_types=1);

namespace Semitexa\Notifications\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Mail\Domain\Enum\MailDispatchStatus;
use Semitexa\Notifications\Application\Service\Channel\MailNotificationChannel;
use Semitexa\Notifications\Domain\Exception\NotificationDeliveryException;
use Semitexa\Notifications\Domain\Model\MailContent;
use Semitexa\Notifications\Tests\Fixtures\IdempotentOrderShipped;
use Semitexa\Notifications\Tests\Fixtures\OrderShipped;
use Semitexa\Notifications\Tests\Fixtures\Recipient;
use Semitexa\Notifications\Tests\Fixtures\RecordingMailService;
use Semitexa\Notifications\Tests\Fixtures\SecurityAlert;
use Semitexa\Testing\Traits\BuildsContainerManagedObjects;

final class MailNotificationChannelTest extends TestCase
{
    use BuildsContainerManagedObjects;

    #[Test]
    public function the_mail_is_addressed_to_the_recipient_and_queued_the_mail_service_s_default_way(): void
    {
        $mail = new RecordingMailService();
        self::channel($mail)->send(new Recipient('ada', 'museum', 'ada@museum.test', 'Ada Lovelace', 'uk'), new OrderShipped(7));

        self::assertCount(1, $mail->sent);
        $envelope = $mail->sent[0]['envelope'];
        self::assertNull($mail->sent[0]['options'], 'no options: MailService queues by default');
        self::assertSame('ada@museum.test', $envelope->to[0]->email);
        self::assertSame('Ada Lovelace', $envelope->to[0]->name);
        self::assertSame('museum', $envelope->tenantId);
        self::assertSame('uk', $envelope->locale);
        self::assertSame('Order #7 has shipped', $envelope->subject);
        self::assertStringContainsString('on its way', (string) $envelope->textBody);
        self::assertStringContainsString('<p>', (string) $envelope->htmlBody);
        self::assertSame(['notification_type' => 'orders.shipped'], $envelope->metadata);
        self::assertSame([], $envelope->cc);
        self::assertSame([], $envelope->bcc);
    }

    #[Test]
    public function an_idempotent_notification_carries_one_key_per_recipient_and_others_none(): void
    {
        $mail = new RecordingMailService();
        $channel = self::channel($mail);
        $channel->send(new Recipient('ada', 'museum', 'ada@museum.test'), new IdempotentOrderShipped(7, ['mail']));
        $channel->send(new Recipient('ada', 'museum', 'ada@museum.test'), new IdempotentOrderShipped(7, ['mail']));
        $channel->send(new Recipient('bob', 'museum', 'bob@museum.test'), new IdempotentOrderShipped(7, ['mail']));
        $channel->send(new Recipient('ada', 'museum', 'ada@museum.test'), new OrderShipped(7));

        $keys = array_map(static fn (array $sent): ?string => $sent['envelope']->idempotencyKey, $mail->sent);
        self::assertNotNull($keys[0]);
        self::assertStringStartsWith('notification:', (string) $keys[0]);
        self::assertSame($keys[0], $keys[1], 'the same notification to the same recipient: the same key, so mail stores it once');
        self::assertNotSame($keys[0], $keys[2], 'another recipient: another mail');
        self::assertNull($keys[3], 'a notification that is not idempotent has no key');
    }

    #[Test]
    public function the_empty_tenant_reaches_mail_as_no_tenant(): void
    {
        $mail = new RecordingMailService();
        self::channel($mail)->send(new Recipient('ada', '', 'ada@museum.test'), new OrderShipped(7));
        self::channel($mail)->send(new Recipient('ada', null, 'ada@museum.test'), new OrderShipped(7));

        self::assertNull($mail->sent[0]['envelope']->tenantId);
        self::assertNull($mail->sent[1]['envelope']->tenantId);
    }

    #[Test]
    public function a_result_that_did_not_succeed_is_a_failure(): void
    {
        foreach ([MailDispatchStatus::Failed, MailDispatchStatus::EnqueueFailed] as $status) {
            try {
                self::channel(new RecordingMailService($status))->send(new Recipient('ada', null, 'ada@museum.test'), new OrderShipped(7));
                self::fail("{$status->value} must fail the channel");
            } catch (NotificationDeliveryException $e) {
                self::assertStringContainsString($status->value, $e->getMessage());
            }
        }
    }

    #[Test]
    public function a_recipient_without_an_address_is_a_failure_and_nothing_is_sent(): void
    {
        $mail = new RecordingMailService();

        $this->expectException(NotificationDeliveryException::class);
        try {
            self::channel($mail)->send(new Recipient('ada', null, '  '), new OrderShipped(7));
        } finally {
            self::assertSame([], $mail->sent);
        }
    }

    #[Test]
    public function a_notification_without_mail_content_is_a_failure(): void
    {
        $this->expectException(NotificationDeliveryException::class);
        $this->expectExceptionMessage('MailNotificationInterface');
        self::channel(new RecordingMailService())->send(new Recipient('ada', null, 'ada@museum.test'), new SecurityAlert(['mail']));
    }

    #[Test]
    public function an_address_that_would_inject_headers_never_reaches_the_mail_service(): void
    {
        $mail = new RecordingMailService();

        try {
            self::channel($mail)->send(new Recipient('ada', null, "ada@museum.test\r\nBcc: all@museum.test"), new OrderShipped(7));
            self::fail('a CR/LF address must be refused');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $mail->sent);
        }
    }

    #[Test]
    public function mail_content_refuses_an_empty_or_multi_line_subject_and_an_empty_body(): void
    {
        foreach ([['', 'body'], ["Hi\r\nBcc: x", 'body'], ['Hi', ' ']] as [$subject, $text]) {
            try {
                new MailContent($subject, $text);
                self::fail('must be refused');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private static function channel(RecordingMailService $mail): MailNotificationChannel
    {
        return self::createWithDependencies(MailNotificationChannel::class, ['mail' => $mail]);
    }
}
