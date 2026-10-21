<?php

declare(strict_types=1);

namespace Semitexa\Notifications\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Notifications\Application\Console\Command\NotificationsPruneCommand;
use Semitexa\Notifications\Application\Service\NotificationRetention;
use Semitexa\Notifications\Configuration\NotificationsConfig;
use Semitexa\Notifications\Domain\Model\RecipientKey;
use Semitexa\Notifications\Tests\Fixtures\ArrayContainer;
use Semitexa\Notifications\Tests\Fixtures\NotificationTables;
use Semitexa\Orm\OrmManager;
use Semitexa\Testing\Traits\BuildsContainerManagedObjects;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class NotificationRetentionTest extends TestCase
{
    use BuildsContainerManagedObjects;

    private OrmManager $orm;
    private NotificationRetention $retention;

    protected function setUp(): void
    {
        $this->orm = NotificationTables::orm();
        $this->retention = self::createWithDependencies(NotificationRetention::class, ['orm' => $this->orm]);
    }

    protected function tearDown(): void
    {
        $this->orm->shutdown();
    }

    #[Test]
    public function rows_older_than_the_window_go_read_or_not_in_batches(): void
    {
        $now = new \DateTimeImmutable('2026-10-21 12:00:00', new \DateTimeZone('UTC'));
        foreach (range(1, 5) as $i) {
            $this->row("old-{$i}", '2026-09-01 00:00:00', $i % 2 === 0 ? '2026-09-02 00:00:00' : null);
        }
        $this->row('edge', '2026-09-21 12:00:01');
        $this->row('new', '2026-10-21 11:00:00');

        self::assertSame(5, $this->retention->prune(30, 2, $now));
        self::assertSame(['edge', 'new'], $this->ids());
        self::assertSame(0, $this->retention->prune(30, 2, $now), 'a second run finds nothing');
    }

    #[Test]
    public function the_window_is_at_least_a_day(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->retention->prune(0);
    }

    #[Test]
    public function the_command_takes_days_from_the_option_or_the_configuration_and_zero_keeps_everything(): void
    {
        $this->row('ancient', '2020-01-01 00:00:00');

        self::assertSame(Command::SUCCESS, $this->command(0)->execute([]));
        self::assertSame(['ancient'], $this->ids(), 'unset retention keeps everything');

        $tester = $this->command(0);
        self::assertSame(Command::SUCCESS, $tester->execute(['--days' => '0']));
        self::assertStringContainsString('nothing deleted', $tester->getDisplay());
        self::assertSame(['ancient'], $this->ids());

        $tester = $this->command(365);
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Deleted 1 notification older than 365 days', $tester->getDisplay());
        self::assertSame([], $this->ids());
    }

    #[Test]
    public function the_command_refuses_a_malformed_number(): void
    {
        foreach ([['--days' => '-3'], ['--days' => 'ten'], ['--batch-size' => '0']] as $input) {
            self::assertSame(Command::INVALID, $this->command(30)->execute($input));
        }
    }

    private function command(int $configuredDays): CommandTester
    {
        $config = self::createWithDependencies(NotificationsConfig::class, ['retentionDays' => $configuredDays]);
        $command = new NotificationsPruneCommand();
        (new \ReflectionProperty(NotificationsPruneCommand::class, 'container'))->setValue($command, new ArrayContainer([
            NotificationsConfig::class   => $config,
            NotificationRetention::class => $this->retention,
        ]));

        return new CommandTester($command);
    }

    private function row(string $id, string $createdAt, ?string $readAt = null): void
    {
        $this->orm->getAdapter()->execute(
            'INSERT INTO notifications_inbox (id, tenant_id, recipient_id, recipient_key, type, data, created_at, read_at) VALUES (:id, :t, :r, :k, :type, :data, :c, :read)',
            ['id' => $id, 't' => 'museum', 'r' => 'ada', 'k' => RecipientKey::from('museum', 'ada')->storageKey, 'type' => 'orders.shipped', 'data' => '{}', 'c' => $createdAt, 'read' => $readAt],
        );
    }

    /** @return list<mixed> */
    private function ids(): array
    {
        return array_column($this->orm->getAdapter()->execute('SELECT id FROM notifications_inbox ORDER BY id')->fetchAll(), 'id');
    }
}
