<?php

declare(strict_types=1);

namespace HashOver\Mail;

interface Mailer
{
    public function send(string $to, string $subject, string $body, ?string $replyTo = null): void;
}
