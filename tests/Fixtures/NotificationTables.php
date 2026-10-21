<?php

declare(strict_types=1);

namespace Semitexa\Notifications\Tests\Fixtures;

use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;

/**
 * An in-memory SQLite database with the package's two tables, shaped as the
 * resource models declare them (NotificationResourceModel,
 * NotificationPreferenceResourceModel).
 */
final class NotificationTables
{
    public static function orm(): OrmManager
    {
        $orm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));
        $orm->getAdapter()->execute(
            'CREATE TABLE notifications_inbox (id CHAR(36) PRIMARY KEY, tenant_id VARCHAR(64) NOT NULL DEFAULT \'\', recipient_id VARCHAR(64) NOT NULL,'
            . ' recipient_key CHAR(64) NOT NULL, type VARCHAR(120) NOT NULL, data TEXT NOT NULL, idempotency_key CHAR(64) NULL,'
            . ' created_at DATETIME NOT NULL, read_at DATETIME NULL, UNIQUE (recipient_key, idempotency_key))',
        );
        $orm->getAdapter()->execute(
            'CREATE TABLE notifications_preferences (id CHAR(36) PRIMARY KEY, tenant_id VARCHAR(64) NOT NULL DEFAULT \'\', recipient_id VARCHAR(64) NOT NULL,'
            . ' recipient_key CHAR(64) NOT NULL, type VARCHAR(120) NOT NULL, channel VARCHAR(64) NOT NULL, enabled BOOLEAN NOT NULL DEFAULT 1,'
            . ' updated_at DATETIME NOT NULL, UNIQUE (recipient_key, type, channel))',
        );

        return $orm;
    }
}
