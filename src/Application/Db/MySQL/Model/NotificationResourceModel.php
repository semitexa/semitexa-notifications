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
 * One notification in one recipient's inbox, written by the `database` channel.
 *
 * Rows are found by `recipient_key`, the SHA-256 of tenant and recipient id
 * (RecipientKey): it compares exactly, where MySQL's default collation would
 * treat ids that differ in case or accents as one. tenant_id and recipient_id
 * are kept readable beside it and every query matches them too. A tenantless
 * recipient is stored under the empty tenant id, never NULL.
 *
 * `idempotency_key` is set for an IdempotentNotificationInterface only (the
 * SHA-256 of type and key); the unique index keeps one row per key and
 * recipient, and NULLs never collide.
 */
#[FromTable(name: 'notifications_inbox')]
#[Index(columns: ['recipient_key', 'read_at'], name: 'idx_notifications_inbox_unread')]
#[Index(columns: ['recipient_key', 'created_at'], name: 'idx_notifications_inbox_latest')]
#[Index(columns: ['created_at'], name: 'idx_notifications_inbox_created')]
#[Index(columns: ['recipient_key', 'idempotency_key'], unique: true, name: 'uniq_notifications_inbox_idempotency')]
final readonly class NotificationResourceModel
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

        #[Column(name: 'data', type: MySqlType::Json)]
        public string $data,

        #[Column(name: 'created_at', type: MySqlType::Datetime)]
        public \DateTimeImmutable $createdAt,

        #[Column(name: 'idempotency_key', type: MySqlType::Char, length: 64, nullable: true)]
        public ?string $idempotencyKey = null,

        #[Column(name: 'read_at', type: MySqlType::Datetime, nullable: true)]
        public ?\DateTimeImmutable $readAt = null,
    ) {}
}
