<?php

declare(strict_types=1);

namespace Semitexa\Notifications\Tests\Fixtures;

use Semitexa\Core\Locale\LocaleContextInterface;
use Semitexa\Notifications\Domain\Contract\NotifiableInterface;
use Semitexa\Notifications\Domain\Contract\NotificationChannelInterface;
use Semitexa\Notifications\Domain\Contract\NotificationInterface;

/** Records the locale in force while it runs; throws for the recipients it is told to. */
final class LocaleRecordingChannel implements NotificationChannelInterface
{
    /** @var list<string> */
    public array $locales = [];

    /** @param list<string> $failFor */
    public function __construct(
        private readonly LocaleContextInterface $locale,
        private readonly array $failFor = [],
    ) {}

    public function send(NotifiableInterface $recipient, NotificationInterface $notification): void
    {
        $this->locales[] = $this->locale->getLocale();
        if (in_array($recipient->notificationRecipientId(), $this->failFor, true)) {
            throw new \RuntimeException('refused');
        }
    }
}
