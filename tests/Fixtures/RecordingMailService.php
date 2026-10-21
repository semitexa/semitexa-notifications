<?php

declare(strict_types=1);

namespace Semitexa\Notifications\Tests\Fixtures;

use Semitexa\Mail\Domain\Contract\MailServiceInterface;
use Semitexa\Mail\Domain\Enum\MailDispatchStatus;
use Semitexa\Mail\Domain\Model\MailDispatchResult;
use Semitexa\Mail\Domain\Model\MailEnvelope;
use Semitexa\Mail\Domain\Model\MailSendOptions;

final class RecordingMailService implements MailServiceInterface
{
    /** @var list<array{envelope: MailEnvelope, options: ?MailSendOptions}> */
    public array $sent = [];

    public function __construct(private readonly MailDispatchStatus $answer = MailDispatchStatus::Queued) {}

    public function send(MailEnvelope $envelope, ?MailSendOptions $options = null): MailDispatchResult
    {
        $this->sent[] = ['envelope' => $envelope, 'options' => $options];

        return new MailDispatchResult(
            status: $this->answer,
            errorCode: $this->answer === MailDispatchStatus::Failed ? 'smtp_error' : null,
            errorMessage: $this->answer === MailDispatchStatus::Failed ? 'refused' : null,
        );
    }
}
