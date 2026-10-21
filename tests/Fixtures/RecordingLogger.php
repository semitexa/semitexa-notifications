<?php

declare(strict_types=1);

namespace Semitexa\Notifications\Tests\Fixtures;

use Semitexa\Core\Log\LoggerInterface;

final class RecordingLogger implements LoggerInterface
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function error(string $message, array $context = []): void { $this->record('error', $message, $context); }
    public function critical(string $message, array $context = []): void { $this->record('critical', $message, $context); }
    public function warning(string $message, array $context = []): void { $this->record('warning', $message, $context); }
    public function info(string $message, array $context = []): void { $this->record('info', $message, $context); }
    public function notice(string $message, array $context = []): void { $this->record('notice', $message, $context); }
    public function debug(string $message, array $context = []): void { $this->record('debug', $message, $context); }

    /** @param array<string, mixed> $context */
    private function record(string $level, string $message, array $context): void
    {
        $this->records[] = ['level' => $level, 'message' => $message, 'context' => $context];
    }

    public function dump(): string
    {
        return json_encode($this->records, JSON_THROW_ON_ERROR);
    }
}
