<?php

declare(strict_types=1);

namespace Semitexa\Notifications\Application\Service;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Notifications\Domain\Contract\NotifiableInterface;
use Semitexa\Notifications\Domain\Model\NotificationPreference;
use Semitexa\Notifications\Domain\Model\RecipientKey;
use Semitexa\Notifications\Domain\Service\NotificationNames;
use Semitexa\Orm\Adapter\SqlIdentifier;
use Semitexa\Orm\Application\Service\Uuid7;
use Semitexa\Orm\OrmManager;

/**
 * Which channels a recipient wants which notifications on.
 *
 * Everything is on until switched off. A switch is for one type on one
 * channel, or for every type on one channel (type '*'); when both exist, the
 * one for the exact type wins - so "no mail, except invoices" is
 * set('*', 'mail', false) plus set('billing.invoice', 'mail', true).
 *
 * Rows are keyed by tenant AND recipient, compared exactly like the inbox
 * ({@see RecipientScope}).
 */
#[AsService]
final class NotificationPreferences
{
    public const string TABLE = 'notifications_preferences';

    private const string DATETIME = 'Y-m-d H:i:s';

    #[InjectAsReadonly]
    protected OrmManager $orm;

    /** Switch $channel on or off for notifications of $type ('*' for every type). */
    public function set(NotifiableInterface $recipient, string $type, string $channel, bool $enabled): void
    {
        $key = RecipientKey::of($recipient);
        NotificationNames::assertType($type, allowWildcard: true);
        NotificationNames::assertChannel($channel);

        $values = [
            'enabled'    => $enabled ? 1 : 0,
            'updated_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(self::DATETIME),
        ];

        // One statement, insert or update: the unique key (recipient_key is
        // the exact hash, with type and channel) decides. As a write it sees
        // the latest committed row even inside a REPEATABLE READ transaction,
        // where a SELECT reads the snapshot; and there is no window between a
        // refused insert and the update for another request to delete the row
        // in, which left the switch unstored (review of notifications#1).
        $this->orm->getQueryAdapter()->execute(sprintf(
            'INSERT INTO %s (id, tenant_id, recipient_id, recipient_key, type, channel, enabled, updated_at)'
            . ' VALUES (:id, :tenant_id, :recipient_id, :recipient_key, :type, :channel, :enabled, :updated_at) %s',
            SqlIdentifier::quote(self::TABLE),
            $this->orm->getDriver() === 'mysql'
                ? 'ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), updated_at = VALUES(updated_at)'
                : 'ON CONFLICT (recipient_key, type, channel) DO UPDATE SET enabled = excluded.enabled, updated_at = excluded.updated_at',
        ), [
            'id'            => Uuid7::generate(),
            'tenant_id'     => $key->tenantId,
            'recipient_id'  => $key->recipientId,
            'recipient_key' => $key->storageKey,
            'type'          => $type,
            'channel'       => $channel,
            ...$values,
        ]);
    }

    /** Drop a stored switch, so the default (or the '*' switch) applies again. */
    public function forget(NotifiableInterface $recipient, string $type, string $channel): void
    {
        [$scope, $params] = $this->scope(RecipientKey::of($recipient));

        $this->orm->getQueryAdapter()->execute(sprintf(
            'DELETE FROM %s WHERE %s AND type = :type AND channel = :channel',
            SqlIdentifier::quote(self::TABLE),
            $scope,
        ), [...$params, 'type' => $type, 'channel' => $channel]);
    }

    public function allows(NotifiableInterface $recipient, string $type, string $channel): bool
    {
        return $this->allowedChannels($recipient, $type, [$channel])[$channel];
    }

    /**
     * The answer for several channels in one query - what the notifier asks
     * once per recipient.
     *
     * @param list<string> $channels
     * @return array<string, bool> channel => allowed, for every channel asked about
     */
    public function allowedChannels(NotifiableInterface $recipient, string $type, array $channels): array
    {
        [$scope, $params] = $this->scope(RecipientKey::of($recipient));
        NotificationNames::assertType($type);

        $allowed = [];
        foreach ($channels as $channel) {
            NotificationNames::assertChannel($channel);
            $allowed[$channel] = true;
        }
        if ($allowed === []) {
            return $allowed;
        }

        $rows = $this->orm->getQueryAdapter()->execute(sprintf(
            'SELECT type, channel, enabled FROM %s WHERE %s AND (type = :type OR type = :any_type)',
            SqlIdentifier::quote(self::TABLE),
            $scope,
        ), [...$params, 'type' => $type, 'any_type' => NotificationNames::ANY_TYPE])->fetchAll();

        $exact = [];
        $wildcard = [];
        foreach ($rows as $row) {
            $rowChannel = self::text($row['channel'] ?? null);
            if (self::text($row['type'] ?? null) === NotificationNames::ANY_TYPE) {
                $wildcard[$rowChannel] = self::flag($row['enabled'] ?? null);
            } else {
                $exact[$rowChannel] = self::flag($row['enabled'] ?? null);
            }
        }

        foreach ($channels as $channel) {
            $allowed[$channel] = $exact[$channel] ?? $wildcard[$channel] ?? true;
        }

        return $allowed;
    }

    /**
     * Every switch the recipient has stored - what a settings page shows.
     *
     * @return list<NotificationPreference>
     */
    public function all(NotifiableInterface $recipient): array
    {
        [$scope, $params] = $this->scope(RecipientKey::of($recipient));

        $rows = $this->orm->getQueryAdapter()->execute(sprintf(
            'SELECT type, channel, enabled FROM %s WHERE %s ORDER BY type, channel',
            SqlIdentifier::quote(self::TABLE),
            $scope,
        ), $params)->fetchAll();

        $preferences = [];
        foreach ($rows as $row) {
            $preferences[] = new NotificationPreference(
                self::text($row['type'] ?? null),
                self::text($row['channel'] ?? null),
                self::flag($row['enabled'] ?? null),
            );
        }

        return $preferences;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /** MySQL hands TINYINT(1) back as '0'/'1' or 0/1, SQLite as 0/1. */
    private static function flag(mixed $value): bool
    {
        return is_scalar($value) && (int) $value === 1;
    }

    /** @return array{0: string, 1: array<string, string>} */
    private function scope(RecipientKey $key): array
    {
        return RecipientScope::where($key);
    }
}
