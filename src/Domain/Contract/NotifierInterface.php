<?php

declare(strict_types=1);

namespace Semitexa\Notifications\Domain\Contract;

use Semitexa\Notifications\Domain\Model\NotificationReport;

/**
 * Sends a notification to recipients over the channels it names.
 *
 * Inject this contract rather than the implementation: a test then hands the
 * code a {@see \Semitexa\Notifications\Application\Service\FakeNotifier}.
 */
interface NotifierInterface
{
    /**
     * Never throws for a delivery problem: every recipient and channel ends up
     * in the report as sent, skipped or failed, and one failure stops nothing else.
     *
     * @param NotifiableInterface|iterable<NotifiableInterface> $to
     */
    public function send(NotifiableInterface|iterable $to, NotificationInterface $notification): NotificationReport;
}
