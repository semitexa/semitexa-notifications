<?php

declare(strict_types=1);

namespace Semitexa\Notifications\Domain\Service;

/**
 * What a notification type and a channel name may look like.
 *
 * Both are stored and matched as written, so they are kept to a plain
 * vocabulary: lower case only (MySQL's default collation ignores case and
 * SQLite does not, so a mixed-case key would match differently on the two),
 * nothing that needs escaping, and a letter first - a name of digits only
 * would turn into an integer the moment it became an array key.
 */
final class NotificationNames
{
    /** In preferences only: the switch for every type at once. */
    public const string ANY_TYPE = '*';

    public const int TYPE_MAX_LENGTH = 120;
    public const int CHANNEL_MAX_LENGTH = 64;

    public static function isValidType(string $type): bool
    {
        return strlen($type) <= self::TYPE_MAX_LENGTH
            && preg_match('/^[a-z][a-z0-9_.:-]*\z/', $type) === 1;
    }

    public static function isValidChannel(string $channel): bool
    {
        return strlen($channel) <= self::CHANNEL_MAX_LENGTH
            && preg_match('/^[a-z][a-z0-9_.-]*\z/', $channel) === 1;
    }

    public static function assertType(string $type, bool $allowWildcard = false): void
    {
        if ($allowWildcard && $type === self::ANY_TYPE) {
            return;
        }
        if (!self::isValidType($type)) {
            throw new \InvalidArgumentException(sprintf(
                "Notification type '%s' is not valid: a lower-case letter, then lower-case letters, digits and . _ : -, at most %d characters.",
                self::excerpt($type),
                self::TYPE_MAX_LENGTH,
            ));
        }
    }

    public static function assertChannel(string $channel): void
    {
        if (!self::isValidChannel($channel)) {
            throw new \InvalidArgumentException(sprintf(
                "Notification channel name '%s' is not valid: a lower-case letter, then lower-case letters, digits and . _ -, at most %d characters.",
                self::excerpt($channel),
                self::CHANNEL_MAX_LENGTH,
            ));
        }
    }

    public static function excerpt(string $value): string
    {
        return mb_substr(preg_replace('/[\x00-\x1F\x7F]/', '?', $value) ?? '', 0, 40);
    }
}
