<?php

declare(strict_types=1);

namespace Semitexa\Notifications\Tests\Fixtures;

use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

final class ArrayContainer implements ContainerInterface
{
    /** @param array<string, object> $entries */
    public function __construct(private array $entries = []) {}

    public function get(string $id): mixed
    {
        return $this->entries[$id] ?? throw new class ("No entry for {$id}") extends \RuntimeException implements NotFoundExceptionInterface {};
    }

    public function has(string $id): bool
    {
        return isset($this->entries[$id]);
    }
}
