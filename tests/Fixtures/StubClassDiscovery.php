<?php

declare(strict_types=1);

namespace Semitexa\Notifications\Tests\Fixtures;

use Semitexa\Core\Discovery\ClassDiscovery;

/** Discovery that knows only the classes a test names. */
class StubClassDiscovery extends ClassDiscovery
{
    /** @param array<string, list<string>> $byAttribute */
    public function __construct(private readonly array $byAttribute) {}

    public function findClassesWithAttribute(string $attributeClass): array
    {
        return $this->byAttribute[$attributeClass] ?? [];
    }
}
