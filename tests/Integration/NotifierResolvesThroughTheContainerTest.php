<?php

declare(strict_types=1);

namespace Semitexa\Notifications\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Notifications\Application\Service\Channel\DatabaseNotificationChannel;
use Semitexa\Notifications\Application\Service\Channel\MailNotificationChannel;
use Semitexa\Notifications\Application\Service\NotificationChannelRegistry;
use Semitexa\Notifications\Application\Service\Notifier;
use Semitexa\Notifications\Domain\Contract\NotifierInterface;

/** What a project injects is what the package wires: the real container, the real discovery. */
final class NotifierResolvesThroughTheContainerTest extends TestCase
{
    #[Test]
    public function the_container_builds_the_notifier_and_finds_the_built_in_channels(): void
    {
        $container = ContainerFactory::get();

        self::assertInstanceOf(Notifier::class, $container->get(NotifierInterface::class));

        $registry = $container->get(NotificationChannelRegistry::class);
        self::assertInstanceOf(NotificationChannelRegistry::class, $registry);
        $registry->assertValid();
        self::assertContains('mail', $registry->names());
        self::assertContains('database', $registry->names());
        self::assertInstanceOf(MailNotificationChannel::class, $registry->find('mail'));
        self::assertInstanceOf(DatabaseNotificationChannel::class, $registry->find('database'));
    }
}
