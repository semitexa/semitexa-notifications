<?php

declare(strict_types=1);

namespace Semitexa\Notifications\Domain\Model;

/**
 * What a notification's mail reads. The `mail` channel turns it into a
 * semitexa/mail envelope addressed to the recipient.
 */
final readonly class MailContent
{
    public function __construct(
        public string $subject,
        public string $text,
        public ?string $html = null,
    ) {
        if (trim($subject) === '') {
            throw new \InvalidArgumentException('A notification mail needs a subject.');
        }
        // The subject becomes a header line: a line break there would start a new header.
        if (preg_match('/[\r\n]/', $subject) === 1) {
            throw new \InvalidArgumentException('A notification mail subject cannot contain a line break.');
        }
        if (trim($text) === '') {
            throw new \InvalidArgumentException('A notification mail needs a plain-text body.');
        }
    }
}
