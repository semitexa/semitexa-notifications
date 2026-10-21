<?php

declare(strict_types=1);

namespace Semitexa\Notifications\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Notifications\Application\Service\Channel\DatabaseNotificationChannel;
use Semitexa\Notifications\Application\Service\NotificationInbox;
use Semitexa\Notifications\Domain\Exception\NotificationDeliveryException;
use Semitexa\Notifications\Domain\Model\RecipientKey;
use Semitexa\Notifications\Tests\Fixtures\NotificationTables;
use Semitexa\Notifications\Tests\Fixtures\OrderShipped;
use Semitexa\Notifications\Tests\Fixtures\Recipient;
use Semitexa\Notifications\Tests\Fixtures\SecurityAlert;
use Semitexa\Orm\OrmManager;
use Semitexa\Testing\Traits\BuildsContainerManagedObjects;

/**
 * The inbox on a real (SQLite) database. Tenant AND recipient scope every
 * statement - the same recipient id in two tenants is two people.
 */
final class NotificationInboxTest extends TestCase
{
    use BuildsContainerManagedObjects;

    private OrmManager $orm;
    private NotificationInbox $inbox;
    private DatabaseNotificationChannel $channel;

    protected function setUp(): void
    {
        $this->orm = NotificationTables::orm();
        $this->inbox = self::createWithDependencies(NotificationInbox::class, ['orm' => $this->orm]);
        $this->channel = self::createWithDependencies(DatabaseNotificationChannel::class, ['inbox' => $this->inbox]);
    }

    protected function tearDown(): void
    {
        $this->orm->shutdown();
    }

    #[Test]
    public function the_database_channel_stores_what_to_database_returned(): void
    {
        $ada = new Recipient('ada', 'museum');
        $this->channel->send($ada, new OrderShipped(7));

        $latest = $this->inbox->latest($ada);
        self::assertCount(1, $latest);
        self::assertSame('orders.shipped', $latest[0]->type);
        self::assertSame(['order_id' => 7, 'title' => 'Order shipped'], $latest[0]->data);
        self::assertFalse($latest[0]->isRead());
        self::assertSame(36, strlen($latest[0]->id));
        self::assertSame(1, $this->inbox->countUnread($ada));
    }

    #[Test]
    public function the_same_recipient_id_in_another_tenant_sees_nothing_of_it(): void
    {
        $museumAda = new Recipient('ada', 'museum');
        $clinicAda = new Recipient('ada', 'clinic');
        $tenantlessAda = new Recipient('ada');
        $this->channel->send($museumAda, new OrderShipped(1));
        $this->channel->send($museumAda, new OrderShipped(2));
        $this->channel->send($clinicAda, new OrderShipped(3));

        self::assertSame(2, $this->inbox->countUnread($museumAda));
        self::assertSame(1, $this->inbox->countUnread($clinicAda));
        self::assertSame(0, $this->inbox->countUnread($tenantlessAda), 'no tenant is its own scope, not "every tenant"');
        self::assertSame([3], array_map(static fn ($n) => $n->data['order_id'], $this->inbox->latest($clinicAda)));
        self::assertSame([], $this->inbox->unread($tenantlessAda));

        self::assertSame(1, $this->inbox->markAllRead($clinicAda));
        self::assertSame(2, $this->inbox->countUnread($museumAda), 'marking one tenant\'s inbox read leaves the other');
    }

    #[Test]
    public function mark_read_touches_only_the_recipient_s_own_row(): void
    {
        $ada = new Recipient('ada', 'museum');
        $this->channel->send($ada, new OrderShipped(7));
        $id = $this->inbox->latest($ada)[0]->id;

        self::assertFalse($this->inbox->markRead($id, new Recipient('bob', 'museum')), 'another recipient of the same tenant');
        self::assertFalse($this->inbox->markRead($id, new Recipient('ada', 'clinic')), 'the same id in another tenant');
        self::assertFalse($this->inbox->markRead('no-such-id', $ada));
        self::assertSame(1, $this->inbox->countUnread($ada));

        self::assertTrue($this->inbox->markRead($id, $ada));
        self::assertFalse($this->inbox->markRead($id, $ada), 'already read');
        self::assertSame(0, $this->inbox->countUnread($ada));
        self::assertNotNull($this->inbox->latest($ada)[0]->readAt);
    }

    #[Test]
    public function latest_and_unread_are_newest_first_and_bounded(): void
    {
        $ada = new Recipient('ada', 'museum');
        foreach ([1, 2, 3, 4] as $order) {
            $this->channel->send($ada, new OrderShipped($order));
        }
        // One second apart: rows created within one millisecond have no order of their own.
        foreach ([1, 2, 3, 4] as $order) {
            $this->orm->getAdapter()->execute(
                "UPDATE notifications_inbox SET created_at = :at WHERE data LIKE :match",
                ['at' => "2026-10-21 10:00:0{$order}", 'match' => "%\"order_id\":{$order}%"],
            );
        }
        $this->inbox->markRead($this->idOfOrder($ada, 4), $ada);

        self::assertSame([4, 3, 2, 1], $this->orders($this->inbox->latest($ada)));
        self::assertSame([4, 3], $this->orders($this->inbox->latest($ada, 2)));
        self::assertSame([3, 2, 1], $this->orders($this->inbox->unread($ada)));
        self::assertSame([3], $this->orders($this->inbox->unread($ada, 0)), 'a limit below 1 is 1');
        self::assertCount(4, $this->inbox->latest($ada, 1_000_000), 'a huge limit is capped, not refused');
    }

    #[Test]
    public function data_that_is_not_json_plain_or_too_large_is_refused(): void
    {
        $key = RecipientKey::of(new Recipient('ada', 'museum'));

        foreach ([['when' => new \DateTimeImmutable()], ['n' => NAN], ['blob' => str_repeat('x', NotificationInbox::MAX_DATA_BYTES)]] as $data) {
            try {
                $this->inbox->store($key, 'orders.shipped', $data);
                self::fail('must be refused');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame(0, $this->inbox->countUnread(new Recipient('ada', 'museum')));
    }

    #[Test]
    public function a_notification_without_database_content_fails_the_channel(): void
    {
        $this->expectException(NotificationDeliveryException::class);
        $this->channel->send(new Recipient('ada'), new class () extends \stdClass implements \Semitexa\Notifications\Domain\Contract\NotificationInterface {
            public function type(): string { return 'x.y'; }
            public function via(\Semitexa\Notifications\Domain\Contract\NotifiableInterface $recipient): array { return ['database']; }
        });
    }

    #[Test]
    public function an_empty_payload_is_stored_and_read_back_as_an_empty_array(): void
    {
        $ada = new Recipient('ada');
        $this->inbox->store(RecipientKey::of($ada), 'security.password_changed', []);
        $this->channel->send($ada, new SecurityAlert());

        self::assertEqualsCanonicalizing([['title' => 'Your password was changed'], []], array_map(static fn ($n) => $n->data, $this->inbox->latest($ada)));
    }

    /**
     * @param list<\Semitexa\Notifications\Domain\Model\StoredNotification> $notifications
     * @return list<mixed>
     */
    private function orders(array $notifications): array
    {
        return array_map(static fn ($n): mixed => $n->data['order_id'] ?? null, $notifications);
    }

    private function idOfOrder(Recipient $recipient, int $order): string
    {
        foreach ($this->inbox->latest($recipient, 200) as $n) {
            if ($n->data['order_id'] === $order) {
                return $n->id;
            }
        }
        self::fail("order {$order} not stored");
    }
}
