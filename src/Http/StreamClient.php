<?php

declare(strict_types=1);

namespace HashOver\Http;

/**
 * Client based on PHP's HTTP stream wrapper (needs allow_url_fopen and openssl).
 */
final readonly class StreamClient implements Client
{
    public const string USER_AGENT = 'HashOver/2.0';

    public function postForm(string $url, array $fields, int $timeout = 5): ?ClientResponse
    {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\nUser-Agent: " . self::USER_AGENT,
            'content' => http_build_query($fields, '', '&', PHP_QUERY_RFC1738),
            'timeout' => $timeout,
            'ignore_errors' => true,
        ]]);

        $body = @file_get_contents($url, false, $context);

        if ($body === false) {
            return null;
        }

        $status = 0;
        $headers = [];

        foreach (http_get_last_response_headers() ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $match) === 1) {
                // After a redirect, only the last response counts
                $status = (int) $match[1];
                $headers = [];
            } elseif (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
        }

        return new ClientResponse($status, $body, $headers);
    }
}
