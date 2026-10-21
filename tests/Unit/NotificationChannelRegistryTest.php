<?php

declare(strict_types=1);

namespace Semitexa\Notifications\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Notifications\Application\Service\Channel\DatabaseNotificationChannel;
use Semitexa\Notifications\Application\Service\Channel\MailNotificationChannel;
use Semitexa\Notifications\Application\Service\NotificationChannelRegistry;
use Semitexa\Notifications\Attribute\AsNotificationChannel;
use Semitexa\Notifications\Domain\Exception\NotificationChannelConfigurationException;
use Semitexa\Notifications\Tests\Fixtures\ArrayContainer;
use Semitexa\Notifications\Tests\Fixtures\Channel\AnotherChatChannel;
use Semitexa\Notifications\Tests\Fixtures\Channel\BadNameChannel;
use Semitexa\Notifications\Tests\Fixtures\Channel\ChatChannel;
use Semitexa\Notifications\Tests\Fixtures\Channel\ProjectMailChannel;
use Semitexa\Notifications\Tests\Fixtures\RecordingChannel;
use Semitexa\Notifications\Tests\Fixtures\StubClassDiscovery;
use Semitexa\Testing\Traits\BuildsContainerManagedObjects;

final class NotificationChannelRegistryTest extends TestCase
{
    use BuildsContainerManagedObjects;

    #[Test]
    public function the_built_in_channels_are_declared_under_their_names(): void
    {
        self::assertSame('mail', self::declaredName(MailNotificationChannel::class));
        self::assertSame('database', self::declaredName(DatabaseNotificationChannel::class));
    }

    #[Test]
    public function channels_are_found_by_the_name_their_attribute_declares(): void
    {
        $chat = new RecordingChannel();
        $registry = self::registry([ChatChannel::class, MailNotificationChannel::class], [ChatChannel::class => $chat]);

        self::assertSame(['chat', 'mail'], $registry->names());
        self::assertTrue($registry->has('chat'));
        self::assertSame($chat, $registry->find('chat'));
        self::assertNull($registry->find('sms'));
    }

    #[Test]
    public function two_project_channels_with_one_name_fail_that_name_and_the_boot_check(): void
    {
        $registry = self::registry([ChatChannel::class, AnotherChatChannel::class, MailNotificationChannel::class], [MailNotificationChannel::class => new RecordingChannel()]);

        self::assertSame(['mail'], $registry->names(), 'the other channels stay usable');
        self::assertNotNull($registry->find('mail'));

        try {
            $registry->find('chat');
            self::fail('a name two classes claim must not resolve to either');
        } catch (NotificationChannelConfigurationException $e) {
            self::assertStringContainsString("Two classes declare the notification channel 'chat'", $e->getMessage());
        }

        $this->expectException(NotificationChannelConfigurationException::class);
        $this->expectExceptionMessage("Two classes declare the notification channel 'chat'");
        $registry->assertValid();
    }

    #[Test]
    public function discovery_runs_once_per_worker_even_when_it_found_problems(): void
    {
        $discovery = new class ([AsNotificationChannel::class => [ChatChannel::class, AnotherChatChannel::class]]) extends StubClassDiscovery {
            public int $calls = 0;

            public function findClassesWithAttribute(string $attributeClass): array
            {
                $this->calls++;

                return parent::findClassesWithAttribute($attributeClass);
            }
        };
        $registry = self::createWithDependencies(NotificationChannelRegistry::class, ['classDiscovery' => $discovery, 'container' => new ArrayContainer()]);

        foreach (range(1, 3) as $_) {
            try {
                $registry->find('chat');
            } catch (NotificationChannelConfigurationException) {
            }
        }
        $registry->names();

        self::assertSame(1, $discovery->calls);
    }

    #[Test]
    public function a_project_channel_takes_over_a_built_in_name_whichever_is_found_first(): void
    {
        foreach ([[ProjectMailChannel::class, MailNotificationChannel::class], [MailNotificationChannel::class, ProjectMailChannel::class]] as $order) {
            $replacement = new RecordingChannel();
            $registry = self::registry($order, [ProjectMailChannel::class => $replacement]);

            self::assertSame($replacement, $registry->find('mail'));
        }
    }

    #[Test]
    public function a_malformed_name_is_left_out_and_fails_the_boot_check(): void
    {
        $registry = self::registry([BadNameChannel::class, ChatChannel::class]);
        self::assertSame(['chat'], $registry->names());

        $this->expectException(NotificationChannelConfigurationException::class);
        $this->expectExceptionMessage("'Bad Name'");
        $registry->assertValid();
    }

    #[Test]
    public function a_registered_class_that_is_not_a_channel_or_cannot_be_built_says_so(): void
    {
        $registry = self::registry([ChatChannel::class], [ChatChannel::class => new \stdClass()]);
        try {
            $registry->find('chat');
            self::fail('a non-channel must be refused');
        } catch (NotificationChannelConfigurationException $e) {
            self::assertStringContainsString('does not implement', $e->getMessage());
        }

        $this->expectException(NotificationChannelConfigurationException::class);
        $this->expectExceptionMessage('#[AsService]');
        self::registry([ChatChannel::class])->find('chat');
    }

    /**
     * @param list<class-string> $classes
     * @param array<string, object> $instances
     */
    private static function registry(array $classes, array $instances = []): NotificationChannelRegistry
    {
        return self::createWithDependencies(NotificationChannelRegistry::class, [
            'classDiscovery' => new StubClassDiscovery([AsNotificationChannel::class => $classes]),
            'container'      => new ArrayContainer($instances),
        ]);
    }

    /** @param class-string $class */
    private static function declaredName(string $class): string
    {
        return (new \ReflectionClass($class))->getAttributes(AsNotificationChannel::class)[0]->newInstance()->name;
    }
}
