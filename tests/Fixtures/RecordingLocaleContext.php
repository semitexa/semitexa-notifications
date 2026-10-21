<?php

declare(strict_types=1);

namespace Semitexa\Notifications\Tests\Fixtures;

use Semitexa\Core\Locale\LocaleContextInterface;

final class RecordingLocaleContext implements LocaleContextInterface
{
    public function __construct(public string $locale = 'en') {}

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): void
    {
        $this->locale = $locale;
    }

    public static function get(): ?self
    {
        return null;
    }

    public static function getOrFail(): self
    {
        throw new \LogicException('Not a global context.');
    }
}
