<?php

declare(strict_types=1);

namespace Semitexa\Notifications\Tests\Fixtures;

use Semitexa\Core\Log\LoggerInterface;

/** A logger whose sink is broken. */
final class ThrowingLogger implements LoggerInterface
{
    public function error(string $message, array $context = []): void { throw new \RuntimeException('disk full'); }
    public function critical(string $message, array $context = []): void { throw new \RuntimeException('disk full'); }
    public function warning(string $message, array $context = []): void { throw new \RuntimeException('disk full'); }
    public function info(string $message, array $context = []): void { throw new \RuntimeException('disk full'); }
    public function notice(string $message, array $context = []): void { throw new \RuntimeException('disk full'); }
    public function debug(string $message, array $context = []): void { throw new \RuntimeException('disk full'); }
}
