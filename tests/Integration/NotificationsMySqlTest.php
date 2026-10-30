<?php

declare(strict_types=1);

namespace Semitexa\Notifications\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Notifications\Application\Service\NotificationInbox;
use Semitexa\Notifications\Application\Service\NotificationPreferences;
use Semitexa\Notifications\Application\Service\NotificationRetention;
use Semitexa\Notifications\Domain\Exception\NotificationAlreadyDeliveredException;
use Semitexa\Notifications\Domain\Model\RecipientKey;
use Semitexa\Notifications\Tests\Fixtures\Recipient;
use Semitexa\Orm\OrmManager;
use Semitexa\Testing\Traits\BuildsContainerManagedObjects;

/**
 * The stores on MySQL, where two things differ from SQLite and both matter:
 * an UPDATE's row count is the rows it CHANGED, and the default collation
 * folds case and accents - 'abc' = 'ABC', 'josé' = 'jose', 'straße' =
 * 'strasse' - so ids must be matched by something exact. Runs on the tables
 * orm:sync made from the resource models, under a tenant of its own, and
 * deletes only its own rows.
 *
 * Not skipped: bin/semitexa test:run runs on MySQL with the schema synced, so
 * a missing database or table there is a failure worth seeing.
 */
final class NotificationsMySqlTest extends TestCase
{
    use BuildsContainerManagedObjects;

    private OrmManager $orm;
    private string $tenant;
    private NotificationInbox $inbox;
    private NotificationPreferences $preferences;

    protected function setUp(): void
    {
        $this->orm = new OrmManager();
        self::assertSame('mysql', $this->orm->getDriver(), 'bin/semitexa test:run runs on MySQL');
        self::assertCount(
            2,
            $this->orm->getAdapter()->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('notifications_inbox', 'notifications_preferences')")->fetchAll(),
            'the notification tables exist: run orm:sync',
        );

        $this->tenant = 'test-' . bin2hex(random_bytes(8));
        $this->inbox = self::createWithDependencies(NotificationInbox::class, ['orm' => $this->orm]);
        $this->preferences = self::createWithDependencies(NotificationPreferences::class, ['orm' => $this->orm]);
    }

    protected function tearDown(): void
    {
        if (isset($this->tenant)) {
            foreach (['notifications_inbox', 'notifications_preferences'] as $table) {
                $this->orm->getAdapter()->execute("DELETE FROM {$table} WHERE tenant_id LIKE :t", ['t' => $this->tenant . '%']);
            }
        }
        if (isset($this->orm)) {
            $this->orm->shutdown();
        }
    }

    #[Test]
    public function saving_the_same_switch_twice_is_not_mistaken_for_a_missing_row(): void
    {
        $ada = new Recipient('ada', $this->tenant);
        $this->preferences->set($ada, 'orders.shipped', 'mail', false);
        $this->preferences->set($ada, 'orders.shipped', 'mail', false);

        self::assertFalse($this->preferences->allows($ada, 'orders.shipped', 'mail'));
        self::assertCount(1, $this->preferences->all($ada));
    }

    #[Test]
    public function ids_the_collation_would_fold_together_are_different_recipients(): void
    {
        foreach ([['abc', 'ABC'], ['josé', 'jose'], ['straße', 'strasse']] as [$first, $second]) {
            $one = new Recipient($first, $this->tenant);
            $two = new Recipient($second, $this->tenant);

            $this->inbox->store(RecipientKey::of($one), 'orders.shipped', ['title' => 'Ваше замовлення']);
            $id = $this->inbox->latest($one)[0]->id;

            self::assertSame(0, $this->inbox->countUnread($two), "{$second} sees nothing of {$first}'s inbox");
            self::assertSame([], $this->inbox->latest($two));
            self::assertFalse($this->inbox->markRead($id, $two));
            self::assertSame(0, $this->inbox->markAllRead($two));
            self::assertSame(1, $this->inbox->countUnread($one));
            self::assertSame(['title' => 'Ваше замовлення'], $this->inbox->latest($one)[0]->data, 'JSON round trip, non-ASCII intact');

            // Both can keep their own switch: no error, no write onto the other's row.
            $this->preferences->set($one, '*', 'mail', false);
            $this->preferences->set($two, '*', 'mail', true);
            $this->preferences->set($two, 'orders.shipped', 'database', false);
            self::assertFalse($this->preferences->allows($one, 'orders.shipped', 'mail'), "{$first}'s switch is untouched");
            self::assertTrue($this->preferences->allows($one, 'orders.shipped', 'database'));
            self::assertTrue($this->preferences->allows($two, 'orders.shipped', 'mail'));
            self::assertFalse($this->preferences->allows($two, 'orders.shipped', 'database'));
            self::assertCount(1, $this->preferences->all($one));
            self::assertCount(2, $this->preferences->all($two));
        }
    }

    #[Test]
    public function the_same_recipient_id_in_another_tenant_is_another_inbox(): void
    {
        $here = new Recipient('ada', $this->tenant);
        $there = new Recipient('ada', $this->tenant . '-other');
        $this->inbox->store(RecipientKey::of($there), 'orders.shipped', ['n' => 1]);
        $id = $this->inbox->latest($there)[0]->id;

        self::assertSame(0, $this->inbox->countUnread($here));
        self::assertFalse($this->inbox->markRead($id, $here));
        self::assertTrue($this->inbox->markRead($id, $there));
    }

    #[Test]
    public function an_idempotency_key_is_stored_once_per_recipient_and_no_key_never_collides(): void
    {
        $ada = RecipientKey::from($this->tenant, 'ada');
        $bob = RecipientKey::from($this->tenant, 'bob');
        $key = hash('sha256', 'orders.shipped' . "\0" . 'order-7');

        $this->inbox->store($ada, 'orders.shipped', [], $key);
        $this->inbox->store($bob, 'orders.shipped', [], $key);
        $this->inbox->store($ada, 'orders.shipped', []);
        $this->inbox->store($ada, 'orders.shipped', []);

        $this->expectException(NotificationAlreadyDeliveredException::class);
        try {
            $this->inbox->store($ada, 'orders.shipped', [], $key);
        } finally {
            self::assertSame(3, $this->inbox->countUnread(new Recipient('ada', $this->tenant)));
        }
    }

    #[Test]
    public function a_key_committed_after_the_caller_s_snapshot_is_still_a_duplicate(): void
    {
        // getAdapter(), not getQueryAdapter(): a connection outside the open
        // transaction, autocommitting - another request, in effect.
        $other = new OrmManager();
        try {
            $ada = RecipientKey::from($this->tenant, 'ada');
            $key = hash('sha256', 'orders.shipped' . "\0" . 'order-9');

            $this->expectException(NotificationAlreadyDeliveredException::class);
            $this->orm->getTransactionManager()->run(function () use ($ada, $key, $other): void {
                // The first read fixes this transaction's REPEATABLE READ snapshot...
                self::assertSame(0, $this->inbox->countUnread(new Recipient('ada', $this->tenant)));
                // ...then another connection stores the same key and commits...
                $other->getAdapter()->execute(
                    'INSERT INTO notifications_inbox (id, tenant_id, recipient_id, recipient_key, type, data, idempotency_key, created_at)'
                    . ' VALUES (:id, :t, :r, :k, :type, :data, :key, :c)',
                    ['id' => \Semitexa\Orm\Application\Service\Uuid7::generate(), 't' => $ada->tenantId, 'r' => $ada->recipientId, 'k' => $ada->storageKey, 'type' => 'orders.shipped', 'data' => '{}', 'key' => $key, 'c' => '2026-10-21 12:00:00'],
                );
                self::assertSame(0, $this->inbox->countUnread(new Recipient('ada', $this->tenant)), 'the snapshot does not see it');
                // ...so a lookup here cannot see it, but the unique index can.
                $this->inbox->store($ada, 'orders.shipped', [], $key);
            });
        } finally {
            $other->shutdown();
        }
    }

    /** A double-submit inside a transaction: the other request's row is outside this one's snapshot. */
    #[Test]
    public function a_switch_committed_after_the_caller_s_snapshot_is_updated_not_refused(): void
    {
        $other = new OrmManager();
        try {
            $ada = new Recipient('ada', $this->tenant);
            $key = RecipientKey::of($ada);

            $this->orm->getTransactionManager()->run(function () use ($ada, $key, $other): void {
                // The first read fixes this transaction's REPEATABLE READ snapshot...
                self::assertSame([], $this->preferences->all($ada));
                // ...then the other request stores the same switch and commits.
                $other->getAdapter()->execute(
                    'INSERT INTO notifications_preferences (id, tenant_id, recipient_id, recipient_key, type, channel, enabled, updated_at)'
                    . ' VALUES (:id, :t, :r, :k, :type, :channel, 1, :u)',
                    ['id' => \Semitexa\Orm\Application\Service\Uuid7::generate(), 't' => $key->tenantId, 'r' => $key->recipientId, 'k' => $key->storageKey, 'type' => 'orders.shipped', 'channel' => 'mail', 'u' => '2026-10-21 12:00:00'],
                );

                $this->preferences->set($ada, 'orders.shipped', 'mail', false);
            });

            self::assertFalse($this->preferences->allows($ada, 'orders.shipped', 'mail'), 'the later choice stands');
            self::assertCount(1, $this->preferences->all($ada));
        } finally {
            $other->shutdown();
        }
    }

    #[Test]
    public function prune_deletes_old_rows_in_batches(): void
    {
        $key = RecipientKey::from($this->tenant, 'ada');
        foreach (range(1, 3) as $i) {
            $this->orm->getAdapter()->execute(
                'INSERT INTO notifications_inbox (id, tenant_id, recipient_id, recipient_key, type, data, created_at)'
                . ' VALUES (:id, :t, :r, :k, :type, :data, :c)',
                ['id' => \Semitexa\Orm\Application\Service\Uuid7::generate(), 't' => $this->tenant, 'r' => 'ada', 'k' => $key->storageKey, 'type' => 'orders.shipped', 'data' => '{}', 'c' => '2001-01-01 00:00:00'],
            );
        }
        $retention = self::createWithDependencies(NotificationRetention::class, ['orm' => $this->orm]);

        // At least ours: anything else this old in the test database goes too.
        self::assertGreaterThanOrEqual(3, $retention->prune(30, 2, new \DateTimeImmutable('2001-02-15 00:00:00', new \DateTimeZone('UTC'))));
        self::assertSame(0, $this->inbox->countUnread(new Recipient('ada', $this->tenant)));
    }
}
