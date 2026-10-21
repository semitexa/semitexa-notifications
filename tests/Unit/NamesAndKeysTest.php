<?php

declare(strict_types=1);

namespace Semitexa\Notifications\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Notifications\Domain\Model\RecipientKey;
use Semitexa\Notifications\Domain\Service\NotificationNames;

final class NamesAndKeysTest extends TestCase
{
    #[Test]
    public function names_start_with_a_letter_and_end_at_the_end_of_the_string(): void
    {
        foreach (['orders.shipped', 'a', 'billing:invoice-2', 'x_y'] as $type) {
            self::assertTrue(NotificationNames::isValidType($type), $type);
        }
        foreach (['', '42', '7orders', "orders.shipped\n", 'Orders.Shipped', 'orders shipped', '.orders', str_repeat('a', 121)] as $type) {
            self::assertFalse(NotificationNames::isValidType($type), var_export($type, true));
        }

        foreach (['mail', 'database', 'push-v2', 'a.b_c'] as $channel) {
            self::assertTrue(NotificationNames::isValidChannel($channel), $channel);
        }
        foreach (['', '42', '0', "mail\n", 'Mail', 'chat:bot', '-x', str_repeat('a', 65)] as $channel) {
            self::assertFalse(NotificationNames::isValidChannel($channel), var_export($channel, true));
        }
    }

    #[Test]
    public function a_recipient_key_refuses_padded_ids_and_tells_tenants_apart_exactly(): void
    {
        foreach ([['acme ', 'ada'], [' acme', 'ada'], ['acme', ' ada'], ['acme', 'ada '], ['acme', '']] as [$tenant, $id]) {
            try {
                RecipientKey::from($tenant, $id);
                self::fail(var_export([$tenant, $id], true) . ' must be refused');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $keys = array_map(
            static fn (array $pair): string => RecipientKey::from($pair[0], $pair[1])->storageKey,
            [['acme', 'ada'], ['acme', 'Ada'], ['acme', 'adá'], ['', 'ada'], ['acm', 'eada'], ['acme', 'ada']],
        );
        self::assertCount(5, array_unique($keys), 'case, accents, the tenant and where the tenant ends all count');
        self::assertSame($keys[0], $keys[5]);
        $shifted = array_map(
            static fn (array $pair): string => RecipientKey::from($pair[0], $pair[1])->storageKey,
            [['ab', 'c'], ['a', 'bc'], ['', 'abc'], ['1:a', 'bc'], ['', '1:abc'], ['a1', ':abc'], ['', '2:a1:abc']],
        );
        self::assertCount(7, array_unique($shifted), 'moving the boundary between tenant and id never gives the same key');

        foreach ([["a\0b", 'c'], ['a', "b\0c"], ['acme', "ada\n"], ["ac\x7Fme", 'ada']] as [$tenant, $id]) {
            try {
                RecipientKey::from($tenant, $id);
                self::fail('control characters must be refused');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        self::assertSame(RecipientKey::from(null, 'ada')->storageKey, RecipientKey::from('', 'ada')->storageKey, 'no tenant is the shared empty tenant');
    }
}
