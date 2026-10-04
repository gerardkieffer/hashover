<?php

declare(strict_types=1);

namespace HashOver\Tests\Support;

use HashOver\Mail\Mailer;

/** Keeps e-mails instead of sending them */
final class RecordingMailer implements Mailer
{
    /** @var list<array{to: string, subject: string, body: string, replyTo: ?string}> */
    public array $sent = [];

    public function send(string $to, string $subject, string $body, ?string $replyTo = null): void
    {
        $this->sent[] = ['to' => $to, 'subject' => $subject, 'body' => $body, 'replyTo' => $replyTo];
    }
}
