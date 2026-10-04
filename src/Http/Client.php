<?php

declare(strict_types=1);

namespace HashOver\Http;

/**
 * Sends requests to web services (Akismet, Turnstile).
 */
interface Client
{
    /**
     * POST form fields; null when the service can't be reached
     *
     * @param array<string, string> $fields
     */
    public function postForm(string $url, array $fields, int $timeout = 5): ?ClientResponse;
}
