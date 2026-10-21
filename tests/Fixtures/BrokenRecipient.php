<?php

declare(strict_types=1);

namespace Semitexa\Notifications\Tests\Fixtures;

use Semitexa\Notifications\Domain\Contract\NotifiableInterface;

/** A recipient whose accessors throw - a lazy-loading model whose row is gone, say. */
final class BrokenRecipient implements NotifiableInterface
{
    public function notificationRecipientId(): string { throw new \RuntimeException('the user row is gone'); }
    public function notificationTenantId(): ?string { throw new \RuntimeException('the user row is gone'); }
    public function notificationEmail(): ?string { return null; }
    public function notificationName(): ?string { return null; }
    public function notificationLocale(): ?string { return null; }
}
