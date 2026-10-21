<?php

declare(strict_types=1);

namespace Semitexa\Notifications\Tests\Fixtures;

use Semitexa\Notifications\Domain\Contract\DatabaseNotificationInterface;
use Semitexa\Notifications\Domain\Contract\MailNotificationInterface;
use Semitexa\Notifications\Domain\Contract\NotifiableInterface;
use Semitexa\Notifications\Domain\Model\MailContent;

final class OrderShipped implements MailNotificationInterface, DatabaseNotificationInterface
{
    /** @param list<string> $channels */
    public function __construct(
        public readonly int $orderId,
        private readonly array $channels = ['mail', 'database'],
    ) {}

    public function type(): string
    {
        return 'orders.shipped';
    }

    public function via(NotifiableInterface $recipient): array
    {
        return $this->channels;
    }

    public function toMail(NotifiableInterface $recipient): MailContent
    {
        return new MailContent(
            subject: "Order #{$this->orderId} has shipped",
            text: "SECRET-BODY order {$this->orderId} is on its way.",
            html: "<p>SECRET-BODY order {$this->orderId} is on its way.</p>",
        );
    }

    public function toDatabase(NotifiableInterface $recipient): array
    {
        return ['order_id' => $this->orderId, 'title' => 'Order shipped'];
    }
}
