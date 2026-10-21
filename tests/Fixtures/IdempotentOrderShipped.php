<?php

declare(strict_types=1);

namespace Semitexa\Notifications\Tests\Fixtures;

use Semitexa\Notifications\Domain\Contract\DatabaseNotificationInterface;
use Semitexa\Notifications\Domain\Contract\IdempotentNotificationInterface;
use Semitexa\Notifications\Domain\Contract\MailNotificationInterface;
use Semitexa\Notifications\Domain\Contract\NotifiableInterface;
use Semitexa\Notifications\Domain\Model\MailContent;

final class IdempotentOrderShipped implements IdempotentNotificationInterface, MailNotificationInterface, DatabaseNotificationInterface
{
    /** @param list<string> $channels */
    public function __construct(
        public readonly int $orderId,
        private readonly array $channels = ['database'],
    ) {}

    public function type(): string
    {
        return 'orders.shipped';
    }

    public function idempotencyKey(): string
    {
        return "order-{$this->orderId}-shipped";
    }

    public function via(NotifiableInterface $recipient): array
    {
        return $this->channels;
    }

    public function toMail(NotifiableInterface $recipient): MailContent
    {
        return new MailContent("Order #{$this->orderId} has shipped", 'On its way.');
    }

    public function toDatabase(NotifiableInterface $recipient): array
    {
        return ['order_id' => $this->orderId];
    }
}
