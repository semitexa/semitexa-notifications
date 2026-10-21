<?php

declare(strict_types=1);

namespace Semitexa\Notifications\Application\Service;

use Psr\Container\ContainerInterface;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Notifications\Attribute\AsNotificationChannel;
use Semitexa\Notifications\Domain\Contract\NotificationChannelInterface;
use Semitexa\Notifications\Domain\Exception\NotificationChannelConfigurationException;
use Semitexa\Notifications\Domain\Service\NotificationNames;

/**
 * The channels by name: every `#[AsNotificationChannel]` class.
 *
 * The name map is built once per worker, from discovery, and so is the list of
 * what is wrong with it: a malformed name, or two project classes claiming one
 * name. {@see Server\BootNotificationChannelsListener} calls assertValid() at
 * worker boot, so a server never starts with either. Where nothing boots - a
 * console command, a queue worker - a broken declaration fails only itself:
 * the name two classes claim fails every delivery on it, and the other
 * channels, the built-ins among them, keep working. A project class may take
 * over a built-in name: that is how a project replaces `mail` or `database`.
 *
 * The channel objects come from the container on each lookup (they are
 * `#[AsService]` singletons), so a channel whose dependencies cannot be built
 * fails its own deliveries without stopping the others.
 */
#[AsService]
final class NotificationChannelRegistry
{
    private const string BUILT_IN_NAMESPACE = 'Semitexa\\Notifications\\';

    #[InjectAsReadonly]
    protected ClassDiscovery $classDiscovery;

    #[InjectAsReadonly]
    protected ContainerInterface $container;

    /** @var array<string, class-string>|null name => channel class; built once per worker */
    private ?array $classes = null;

    /** @var array<string, string> name => why that name cannot be used (two classes claim it) */
    private array $brokenNames = [];

    /** @var list<string> every problem found, for assertValid() */
    private array $problems = [];

    /** @return list<string> every usable channel name, sorted */
    public function names(): array
    {
        $names = array_map(strval(...), array_keys($this->classes()));
        sort($names);

        return $names;
    }

    public function has(string $name): bool
    {
        return isset($this->classes()[$name]);
    }

    /**
     * @throws NotificationChannelConfigurationException naming every problem
     *         with the declared channels; nothing when there is none
     */
    public function assertValid(): void
    {
        $this->classes();
        if ($this->problems !== []) {
            throw new NotificationChannelConfigurationException(implode(' ', $this->problems));
        }
    }

    /**
     * The channel registered under $name, or null when there is none.
     *
     * @throws NotificationChannelConfigurationException when two classes claim
     *         the name, the class is not a channel, or the container cannot build it
     */
    public function find(string $name): ?NotificationChannelInterface
    {
        $classes = $this->classes();
        if (isset($this->brokenNames[$name])) {
            throw new NotificationChannelConfigurationException($this->brokenNames[$name]);
        }

        $class = $classes[$name] ?? null;
        if ($class === null) {
            return null;
        }

        try {
            $channel = $this->container->get($class);
        } catch (\Throwable $e) {
            throw new NotificationChannelConfigurationException(sprintf(
                "Notification channel '%s' (%s) could not be built: %s. A channel class is also #[AsService].",
                $name,
                $class,
                $e->getMessage(),
            ), 0, $e);
        }

        if (!$channel instanceof NotificationChannelInterface) {
            throw new NotificationChannelConfigurationException(sprintf(
                "Notification channel '%s' (%s) does not implement %s.",
                $name,
                $class,
                NotificationChannelInterface::class,
            ));
        }

        return $channel;
    }

    /** @return array<string, class-string> */
    private function classes(): array
    {
        return $this->classes ??= $this->discover();
    }

    /** @return array<string, class-string> */
    private function discover(): array
    {
        /** @var array<string, class-string> $byName */
        $byName = [];
        /** @var array<string, list<class-string>> $claims name => every project class claiming it */
        $claims = [];

        foreach ($this->classDiscovery->findClassesWithAttribute(AsNotificationChannel::class) as $class) {
            if (!class_exists($class)) {
                continue;
            }
            $attributes = (new \ReflectionClass($class))->getAttributes(AsNotificationChannel::class);
            if ($attributes === []) {
                continue;
            }
            $name = $attributes[0]->newInstance()->name;

            // Checked before the name is ever used as a key: a valid name
            // starts with a letter, so it can never turn into an integer key.
            if (!NotificationNames::isValidChannel($name)) {
                $this->problems[] = sprintf(
                    "%s declares the notification channel name '%s': use a lower-case letter, then lower-case letters, digits and . _ - (at most %d characters).",
                    $class,
                    NotificationNames::excerpt($name),
                    NotificationNames::CHANNEL_MAX_LENGTH,
                );
                continue;
            }

            if (self::isBuiltIn($class)) {
                // A built-in yields to a project class of the same name.
                $byName[$name] ??= $class;
                continue;
            }

            $claims[$name][] = $class;
        }

        foreach ($claims as $name => $classes) {
            $name = (string) $name;
            if (count($classes) === 1) {
                $byName[$name] = $classes[0];
                continue;
            }

            unset($byName[$name]);
            $problem = sprintf(
                "Two classes declare the notification channel '%s': %s. A name belongs to one channel.",
                $name,
                implode(' and ', $classes),
            );
            $this->brokenNames[$name] = $problem;
            $this->problems[] = $problem;
        }

        return $byName;
    }

    private static function isBuiltIn(string $class): bool
    {
        return str_starts_with($class, self::BUILT_IN_NAMESPACE)
            && !str_starts_with($class, self::BUILT_IN_NAMESPACE . 'Tests\\');
    }
}
