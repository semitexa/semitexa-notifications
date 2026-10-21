<?php

declare(strict_types=1);

namespace Semitexa\Notifications\Tests\Fixtures;

use Semitexa\Notifications\Domain\Contract\NotifiableInterface;

final readonly class Recipient implements NotifiableInterface
{
    public function __construct(
        public string $id,
        public ?string $tenant = null,
        public ?string $email = null,
        public ?string $name = null,
        public ?string $locale = null,
    ) {}

    public function notificationRecipientId(): string { return $this->id; }
    public function notificationTenantId(): ?string { return $this->tenant; }
    public function notificationEmail(): ?string { return $this->email; }
    public function notificationName(): ?string { return $this->name; }
    public function notificationLocale(): ?string { return $this->locale; }
}
