<?php

declare(strict_types=1);

namespace Semitexa\Notifications\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Notifications\Application\Service\NotificationPreferences;
use Semitexa\Notifications\Domain\Model\NotificationPreference;
use Semitexa\Notifications\Tests\Fixtures\NotificationTables;
use Semitexa\Notifications\Tests\Fixtures\Recipient;
use Semitexa\Orm\OrmManager;
use Semitexa\Testing\Traits\BuildsContainerManagedObjects;

final class NotificationPreferencesTest extends TestCase
{
    use BuildsContainerManagedObjects;

    private OrmManager $orm;
    private NotificationPreferences $preferences;

    protected function setUp(): void
    {
        $this->orm = NotificationTables::orm();
        $this->preferences = self::createWithDependencies(NotificationPreferences::class, ['orm' => $this->orm]);
    }

    protected function tearDown(): void
    {
        $this->orm->shutdown();
    }

    #[Test]
    public function everything_is_on_until_switched_off(): void
    {
        $ada = new Recipient('ada', 'museum');

        self::assertTrue($this->preferences->allows($ada, 'orders.shipped', 'mail'));

        $this->preferences->set($ada, 'orders.shipped', 'mail', false);
        self::assertFalse($this->preferences->allows($ada, 'orders.shipped', 'mail'));
        self::assertTrue($this->preferences->allows($ada, 'orders.shipped', 'database'), 'another channel');
        self::assertTrue($this->preferences->allows($ada, 'orders.cancelled', 'mail'), 'another type');
    }

    #[Test]
    public function setting_the_same_switch_again_keeps_one_row(): void
    {
        $ada = new Recipient('ada', 'museum');
        $this->preferences->set($ada, 'orders.shipped', 'mail', false);
        $this->preferences->set($ada, 'orders.shipped', 'mail', false);
        $this->preferences->set($ada, 'orders.shipped', 'mail', true);

        self::assertTrue($this->preferences->allows($ada, 'orders.shipped', 'mail'));
        self::assertCount(1, $this->orm->getAdapter()->execute('SELECT id FROM notifications_preferences')->fetchAll());
    }

    #[Test]
    public function the_wildcard_switches_every_type_and_an_exact_switch_wins_over_it(): void
    {
        $ada = new Recipient('ada', 'museum');
        $this->preferences->set($ada, '*', 'mail', false);
        $this->preferences->set($ada, 'billing.invoice', 'mail', true);

        self::assertFalse($this->preferences->allows($ada, 'orders.shipped', 'mail'));
        self::assertTrue($this->preferences->allows($ada, 'billing.invoice', 'mail'), 'no mail, except invoices');
        self::assertSame(
            ['mail' => false, 'database' => true],
            $this->preferences->allowedChannels($ada, 'orders.shipped', ['mail', 'database']),
        );

        $this->preferences->forget($ada, '*', 'mail');
        self::assertTrue($this->preferences->allows($ada, 'orders.shipped', 'mail'), 'forgotten: back to the default');
    }

    #[Test]
    public function a_switch_belongs_to_one_tenant_s_recipient(): void
    {
        $this->preferences->set(new Recipient('ada', 'museum'), '*', 'mail', false);

        self::assertFalse($this->preferences->allows(new Recipient('ada', 'museum'), 'orders.shipped', 'mail'));
        self::assertTrue($this->preferences->allows(new Recipient('ada', 'clinic'), 'orders.shipped', 'mail'));
        self::assertTrue($this->preferences->allows(new Recipient('ada'), 'orders.shipped', 'mail'));
        self::assertTrue($this->preferences->allows(new Recipient('bob', 'museum'), 'orders.shipped', 'mail'));
        self::assertSame([], $this->preferences->all(new Recipient('ada', 'clinic')));
    }

    #[Test]
    public function a_tenantless_recipient_keeps_one_row_per_switch(): void
    {
        // Stored under '' rather than NULL: a NULL in the unique key would let duplicates in.
        $cy = new Recipient('cy');
        $this->preferences->set($cy, 'orders.shipped', 'mail', false);
        $this->preferences->set($cy, 'orders.shipped', 'mail', false);

        self::assertEquals([new NotificationPreference('orders.shipped', 'mail', false)], $this->preferences->all($cy));
    }

    #[Test]
    public function malformed_types_and_channels_are_refused(): void
    {
        $ada = new Recipient('ada', 'museum');
        foreach ([['', 'mail'], ['orders shipped', 'mail'], ['Orders.Shipped', 'mail'], ['orders.shipped', 'Mail'], ['orders.shipped', ''], [str_repeat('a', 121), 'mail']] as [$type, $channel]) {
            try {
                $this->preferences->set($ada, $type, $channel, false);
                self::fail("'{$type}' / '{$channel}' must be refused");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->preferences->allows($ada, '*', 'mail');
    }
}
