<?php

declare(strict_types=1);

namespace Semitexa\Notifications\Application\Service;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Notifications\Domain\Contract\NotifiableInterface;
use Semitexa\Notifications\Domain\Exception\NotificationAlreadyDeliveredException;
use Semitexa\Notifications\Domain\Model\RecipientKey;
use Semitexa\Notifications\Domain\Model\StoredNotification;
use Semitexa\Notifications\Domain\Service\NotificationNames;
use Semitexa\Orm\Adapter\SqlIdentifier;
use Semitexa\Orm\Application\Service\Uuid7;
use Semitexa\Orm\Exception\ConstraintViolationException;
use Semitexa\Orm\OrmManager;

/**
 * A recipient's inbox: what the `database` channel stored for them.
 *
 * Every statement here is pinned to one recipient - tenant AND recipient id,
 * compared exactly ({@see RecipientScope}). That is the security property of
 * the inbox: a recipient id alone is not unique across tenants, and an id
 * taken from a request (markRead) only ever touches a row of the recipient
 * who asked.
 *
 * Newest first means by created_at (whole seconds), then by id. Ids are
 * UUIDv7, which follow the millisecond; rows created within one millisecond
 * have no defined order between them.
 *
 * The write goes through the ORM's query adapter, so it joins the caller's
 * transaction: a caller that rolls back loses the row.
 */
#[AsService]
final class NotificationInbox
{
    public const string TABLE = 'notifications_inbox';

    /** A stored notification's JSON is capped: an inbox row is a summary, not a document. */
    public const int MAX_DATA_BYTES = 65535;

    public const int MAX_LIMIT = 200;

    private const string DATETIME = 'Y-m-d H:i:s';

    private const int JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;

    #[InjectAsReadonly]
    protected OrmManager $orm;

    /**
     * Store one notification for one recipient (the `database` channel's write).
     *
     * @param array<string, mixed> $data JSON-plain
     * @param string|null $idempotencyKey from IdempotencyKeys::forInbox(); a
     *        second row with the same key for this recipient is not stored
     * @return string the new row's id
     * @throws NotificationAlreadyDeliveredException when $idempotencyKey is
     *         already stored for this recipient
     * @throws \InvalidArgumentException for a malformed type, data that is
     *         not JSON-plain, or data over MAX_DATA_BYTES
     */
    public function store(RecipientKey $recipient, string $type, array $data, ?string $idempotencyKey = null): string
    {
        NotificationNames::assertType($type);
        self::assertJsonPlain($data, 'data');

        $json = json_encode($data === [] ? new \stdClass() : $data, self::JSON_FLAGS);
        if (strlen($json) > self::MAX_DATA_BYTES) {
            throw new \InvalidArgumentException(sprintf(
                'A stored notification is at most %d bytes of JSON; %s produced %d.',
                self::MAX_DATA_BYTES,
                $type,
                strlen($json),
            ));
        }

        $id = Uuid7::generate();
        try {
            $this->orm->getQueryAdapter()->execute(sprintf(
                'INSERT INTO %s (id, tenant_id, recipient_id, recipient_key, type, data, idempotency_key, created_at, read_at)'
                . ' VALUES (:id, :tenant_id, :recipient_id, :recipient_key, :type, :data, :idempotency_key, :created_at, NULL)',
                SqlIdentifier::quote(self::TABLE),
            ), [
                'id'              => $id,
                'tenant_id'       => $recipient->tenantId,
                'recipient_id'    => $recipient->recipientId,
                'recipient_key'   => $recipient->storageKey,
                'type'            => $type,
                'data'            => $json,
                'idempotency_key' => $idempotencyKey,
                'created_at'      => self::now()->format(self::DATETIME),
            ]);
        } catch (ConstraintViolationException $e) {
            if ($idempotencyKey !== null && ($this->violatesIdempotencyKey($e) || $this->holdsKey($recipient, $idempotencyKey))) {
                throw new NotificationAlreadyDeliveredException('This notification is already in the recipient\'s inbox.', 0, $e);
            }
            throw $e;
        }

        return $id;
    }

    /**
     * The newest notifications, read or not, newest first.
     *
     * @return list<StoredNotification>
     */
    public function latest(NotifiableInterface $recipient, int $limit = 20): array
    {
        return $this->select(RecipientKey::of($recipient), false, $limit);
    }

    /**
     * The newest unread notifications, newest first.
     *
     * @return list<StoredNotification>
     */
    public function unread(NotifiableInterface $recipient, int $limit = 20): array
    {
        return $this->select(RecipientKey::of($recipient), true, $limit);
    }

    public function countUnread(NotifiableInterface $recipient): int
    {
        [$scope, $params] = $this->scope(RecipientKey::of($recipient));
        $row = $this->orm->getQueryAdapter()->execute(sprintf(
            'SELECT COUNT(*) AS n FROM %s WHERE %s AND read_at IS NULL',
            SqlIdentifier::quote(self::TABLE),
            $scope,
        ), $params)->fetchOne();

        $count = $row['n'] ?? 0;

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * Mark one notification read - only if it is this recipient's own.
     *
     * @return bool true when an unread row of this recipient was marked; false
     *              for an unknown id, someone else's row, or one already read
     */
    public function markRead(string $id, NotifiableInterface $recipient): bool
    {
        [$scope, $params] = $this->scope(RecipientKey::of($recipient));
        if ($id === '' || strlen($id) > 36) {
            return false;
        }

        return $this->orm->getQueryAdapter()->execute(sprintf(
            'UPDATE %s SET read_at = :read_at WHERE id = :id AND %s AND read_at IS NULL',
            SqlIdentifier::quote(self::TABLE),
            $scope,
        ), [
            'read_at' => self::now()->format(self::DATETIME),
            'id'      => $id,
            ...$params,
        ])->rowCount === 1;
    }

    /** @return int how many notifications were unread and are read now */
    public function markAllRead(NotifiableInterface $recipient): int
    {
        [$scope, $params] = $this->scope(RecipientKey::of($recipient));

        return $this->orm->getQueryAdapter()->execute(sprintf(
            'UPDATE %s SET read_at = :read_at WHERE %s AND read_at IS NULL',
            SqlIdentifier::quote(self::TABLE),
            $scope,
        ), [
            'read_at' => self::now()->format(self::DATETIME),
            ...$params,
        ])->rowCount;
    }

    /** @return list<StoredNotification> */
    private function select(RecipientKey $key, bool $unreadOnly, int $limit): array
    {
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        [$scope, $params] = $this->scope($key);

        $rows = $this->orm->getQueryAdapter()->execute(sprintf(
            'SELECT id, type, data, created_at, read_at FROM %s WHERE %s%s'
            . ' ORDER BY created_at DESC, id DESC LIMIT %d',
            SqlIdentifier::quote(self::TABLE),
            $scope,
            $unreadOnly ? ' AND read_at IS NULL' : '',
            $limit,
        ), $params)->fetchAll();

        $notifications = [];
        foreach ($rows as $row) {
            $notifications[] = self::hydrate($row);
        }

        return $notifications;
    }

    /**
     * Whether the violated constraint is the idempotency index, read from the
     * driver's message: MySQL names the index (uniq_notifications_inbox_
     * idempotency), SQLite the columns (... .idempotency_key). This, not a
     * lookup, is the answer that holds inside a caller's REPEATABLE READ
     * transaction: the row that collided was committed after the transaction's
     * snapshot, so a SELECT there cannot see it.
     */
    private function violatesIdempotencyKey(ConstraintViolationException $e): bool
    {
        return str_contains($e->getMessage(), 'idempotency');
    }

    private function holdsKey(RecipientKey $key, string $idempotencyKey): bool
    {
        [$scope, $params] = $this->scope($key);

        return $this->orm->getQueryAdapter()->execute(sprintf(
            'SELECT id FROM %s WHERE %s AND idempotency_key = :idempotency_key',
            SqlIdentifier::quote(self::TABLE),
            $scope,
        ), [...$params, 'idempotency_key' => $idempotencyKey])->fetchOne() !== null;
    }

    /** @return array{0: string, 1: array<string, string>} */
    private function scope(RecipientKey $key): array
    {
        return RecipientScope::where($key);
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): StoredNotification
    {
        $data = json_decode(self::text($row['data'] ?? null), true);
        $readAt = self::text($row['read_at'] ?? null);
        $utc = new \DateTimeZone('UTC');

        /** @var array<string, mixed> $data JSON objects decode to string-keyed arrays */
        $data = is_array($data) ? $data : [];

        return new StoredNotification(
            id: self::text($row['id'] ?? null),
            type: self::text($row['type'] ?? null),
            data: $data,
            createdAt: new \DateTimeImmutable(self::text($row['created_at'] ?? null), $utc),
            readAt: $readAt === '' ? null : new \DateTimeImmutable($readAt, $utc),
        );
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Objects are refused even when json_encode() could serialize them: the
     * inbox hands the same array back, and an object would come back as an
     * array with a different shape than the one that went in.
     */
    private static function assertJsonPlain(mixed $value, string $path): void
    {
        if ($value === null || is_scalar($value)) {
            if (is_float($value) && !is_finite($value)) {
                throw new \InvalidArgumentException("Notification data at {$path} is not a finite number.");
            }

            return;
        }
        if (!is_array($value)) {
            throw new \InvalidArgumentException(sprintf(
                'Notification data at %s is a %s; only scalars, null and arrays of them are stored.',
                $path,
                get_debug_type($value),
            ));
        }
        foreach ($value as $key => $item) {
            self::assertJsonPlain($item, $path . '.' . $key);
        }
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
