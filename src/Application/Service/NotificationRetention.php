<?php

declare(strict_types=1);

namespace Semitexa\Notifications\Application\Service;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Orm\Adapter\SqlIdentifier;
use Semitexa\Orm\OrmManager;

/**
 * Deletes inbox rows older than a number of days, read or unread, in batches.
 *
 * Batches keep each DELETE short: a single statement over a large table holds
 * its locks for the whole sweep. Rows are picked by id and deleted by id, which
 * is also the form both MySQL and SQLite accept (DELETE ... LIMIT is not portable).
 */
#[AsService]
final class NotificationRetention
{
    public const int DEFAULT_BATCH_SIZE = 1000;
    public const int MAX_BATCH_SIZE = 10000;

    private const string DATETIME = 'Y-m-d H:i:s';

    #[InjectAsReadonly]
    protected OrmManager $orm;

    /**
     * @param int $days rows created more than this many days before $now go; must be >= 1
     * @return int rows deleted
     */
    public function prune(int $days, int $batchSize = self::DEFAULT_BATCH_SIZE, ?\DateTimeImmutable $now = null): int
    {
        if ($days < 1) {
            throw new \InvalidArgumentException('Retention is at least one day.');
        }
        // One placeholder per id in the DELETE: MySQL refuses more than
        // 65,535 in a statement, SQLite more than 32,766.
        if ($batchSize < 1 || $batchSize > self::MAX_BATCH_SIZE) {
            throw new \InvalidArgumentException('The batch size is between 1 and ' . self::MAX_BATCH_SIZE . '.');
        }

        $cutoff = ($now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->modify(sprintf('-%d days', $days))
            ->format(self::DATETIME);

        $adapter = $this->orm->getQueryAdapter();
        $table = SqlIdentifier::quote(NotificationInbox::TABLE);
        $deleted = 0;

        while (true) {
            $ids = array_map(
                static fn (array $row): string => is_scalar($row['id'] ?? null) ? (string) $row['id'] : '',
                $adapter->execute(sprintf(
                    'SELECT id FROM %s WHERE created_at < :cutoff ORDER BY created_at LIMIT %d',
                    $table,
                    $batchSize,
                ), ['cutoff' => $cutoff])->fetchAll(),
            );
            if ($ids === []) {
                break;
            }

            $params = [];
            foreach ($ids as $i => $id) {
                $params['id' . $i] = $id;
            }
            $removed = $adapter->execute(sprintf(
                'DELETE FROM %s WHERE id IN (%s)',
                $table,
                implode(', ', array_map(static fn (string $p): string => ':' . $p, array_keys($params))),
            ), $params)->rowCount;
            $deleted += $removed;

            // A short batch was the last one; a batch that deleted nothing
            // would only be selected again.
            if (count($ids) < $batchSize || $removed === 0) {
                break;
            }
        }

        return $deleted;
    }
}
