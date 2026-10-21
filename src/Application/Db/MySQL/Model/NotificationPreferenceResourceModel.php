<?php

declare(strict_types=1);

namespace Semitexa\Notifications\Application\Db\MySQL\Model;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\Index;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Metadata\HasColumnReferences;

/**
 * One recipient's switch for one notification type on one channel.
 *
 * No row means the channel is on. `type` = '*' switches the channel for every
 * type; a row for the exact type wins over it, so "no mail, except invoices"
 * is two rows. Unique by `recipient_key` (the exact SHA-256 of tenant and
 * recipient id, see NotificationResourceModel), type and channel; types and
 * channel names are lower-case ASCII, so they compare exactly as they are.
 */
#[FromTable(name: 'notifications_preferences')]
#[Index(columns: ['recipient_key', 'type', 'channel'], unique: true, name: 'uniq_notifications_preferences')]
final readonly class NotificationPreferenceResourceModel
{
    use HasColumnReferences;

    public function __construct(
        #[PrimaryKey(strategy: 'manual')]
        #[Column(name: 'id', type: MySqlType::Char, length: 36)]
        public string $id,

        #[Column(name: 'tenant_id', type: MySqlType::Varchar, length: 64, default: '')]
        public string $tenantId,

        #[Column(name: 'recipient_id', type: MySqlType::Varchar, length: 64)]
        public string $recipientId,

        #[Column(name: 'recipient_key', type: MySqlType::Char, length: 64)]
        public string $recipientKey,

        #[Column(name: 'type', type: MySqlType::Varchar, length: 120)]
        public string $type,

        #[Column(name: 'channel', type: MySqlType::Varchar, length: 64)]
        public string $channel,

        #[Column(name: 'enabled', type: MySqlType::Boolean, default: true)]
        public bool $enabled,

        #[Column(name: 'updated_at', type: MySqlType::Datetime)]
        public \DateTimeImmutable $updatedAt,
    ) {}
}
