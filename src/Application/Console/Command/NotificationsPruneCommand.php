<?php

declare(strict_types=1);

namespace Semitexa\Notifications\Application\Console\Command;

use Psr\Container\ContainerInterface;
use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Notifications\Application\Service\NotificationRetention;
use Semitexa\Notifications\Configuration\NotificationsConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Deletes stored notifications older than --days (default
 * NOTIFICATIONS_RETENTION_DAYS), read and unread alike. With neither set, or
 * set to 0, nothing is deleted: keeping everything is the default.
 *
 * Prints a count only, never what the deleted notifications said.
 */
#[AsCommand(name: 'notifications:prune', description: 'Delete stored notifications older than N days (--days, or NOTIFICATIONS_RETENTION_DAYS)')]
final class NotificationsPruneCommand extends Command
{
    /**
     * The services are resolved inside execute(): every #[AsCommand] class is
     * built at console boot, and an injected store would open its connection on
     * every bin/semitexa invocation.
     */
    #[InjectAsReadonly]
    protected ContainerInterface $container;

    protected function configure(): void
    {
        $this
            ->setName('notifications:prune')
            ->setDescription('Delete stored notifications older than N days (--days, or NOTIFICATIONS_RETENTION_DAYS)')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Delete notifications created more than this many days ago; 0 keeps everything (default: NOTIFICATIONS_RETENTION_DAYS)')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Rows deleted per statement', (string) NotificationRetention::DEFAULT_BATCH_SIZE);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $daysOption = $input->getOption('days');
        $batchOption = $input->getOption('batch-size');

        if ($daysOption !== null && !self::isWholeNumber($daysOption)) {
            $output->writeln('<error>--days is a whole number of days (0 keeps everything).</error>');

            return Command::INVALID;
        }
        if (!self::isWholeNumber($batchOption) || (int) $batchOption < 1) {
            $output->writeln('<error>--batch-size is a whole number of at least 1.</error>');

            return Command::INVALID;
        }

        try {
            $days = $daysOption !== null
                ? (int) $daysOption
                : $this->service(NotificationsConfig::class)->retentionDays();

            if ($days === 0) {
                $output->writeln('Retention is off (no --days and NOTIFICATIONS_RETENTION_DAYS is 0 or unset): nothing deleted.');

                return Command::SUCCESS;
            }

            $deleted = $this->service(NotificationRetention::class)->prune($days, (int) $batchOption);
        } catch (\Throwable $e) {
            $output->writeln('<error>notifications:prune failed: ' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $output->writeln(sprintf('Deleted %d notification%s older than %d day%s.', $deleted, $deleted === 1 ? '' : 's', $days, $days === 1 ? '' : 's'));

        return Command::SUCCESS;
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function service(string $class): object
    {
        $service = $this->container->get($class);
        if (!$service instanceof $class) {
            throw new \LogicException(sprintf('The container returned no %s.', $class));
        }

        return $service;
    }

    /** @phpstan-assert-if-true numeric-string $value */
    private static function isWholeNumber(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d{1,6}$/', $value) === 1;
    }
}
