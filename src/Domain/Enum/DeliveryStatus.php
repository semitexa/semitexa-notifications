<?php

declare(strict_types=1);

namespace Semitexa\Notifications\Domain\Enum;

enum DeliveryStatus: string
{
    /** The channel took it: sent, queued or stored. */
    case Sent = 'sent';

    /** Not attempted, on purpose: the recipient switched this channel off. */
    case Skipped = 'skipped';

    /** Attempted, or impossible to attempt, and it did not happen. */
    case Failed = 'failed';
}
