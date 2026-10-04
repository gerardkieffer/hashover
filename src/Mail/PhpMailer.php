<?php

declare(strict_types=1);

namespace HashOver\Mail;

/**
 * Sends plain-text UTF-8 e-mail with PHP's mail() function.
 */
final readonly class PhpMailer implements Mailer
{
    public function __construct(
        private string $sender,
    ) {}

    public function send(string $to, string $subject, string $body, ?string $replyTo = null): void
    {
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }

        $headers = [
            'MIME-Version' => '1.0',
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Transfer-Encoding' => '8bit',
        ];

        if ($this->sender !== '') {
            $headers['From'] = $this->sender;
        }

        if ($replyTo !== null && filter_var($replyTo, FILTER_VALIDATE_EMAIL) !== false) {
            $headers['Reply-To'] = $replyTo;
        }

        $subject = mb_encode_mimeheader(str_replace(["\r", "\n"], ' ', $subject), 'UTF-8', 'Q');

        if (!mail($to, $subject, str_replace("\n", "\r\n", $body), $headers)) {
            error_log('HashOver: could not send e-mail to ' . $to);
        }
    }
}
