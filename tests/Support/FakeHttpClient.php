<?php

declare(strict_types=1);

namespace HashOver\Tests\Support;

use HashOver\Http\Client;
use HashOver\Http\ClientResponse;

/**
 * Answers requests to web services with canned responses and records them.
 */
final class FakeHttpClient implements Client
{
    /** @var list<array{url: string, fields: array<string, string>}> */
    public array $requests = [];

    /** @var array<string, ClientResponse|null> answer per URL; null: unreachable */
    public array $responses = [];

    public function postForm(string $url, array $fields, int $timeout = 5): ?ClientResponse
    {
        $this->requests[] = ['url' => $url, 'fields' => $fields];

        return $this->responses[$url] ?? null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function json(string $url, array $data): void
    {
        $this->responses[$url] = new ClientResponse(200, json_encode($data, JSON_THROW_ON_ERROR), ['content-type' => 'application/json']);
    }

    /**
     * @param array<string, string> $headers
     */
    public function text(string $url, string $body, array $headers = []): void
    {
        $this->responses[$url] = new ClientResponse(200, $body, $headers);
    }

    /**
     * Fields of the requests sent to a URL
     *
     * @return list<array<string, string>>
     */
    public function sentTo(string $url): array
    {
        return array_values(array_map(
            static fn(array $request): array => $request['fields'],
            array_filter($this->requests, static fn(array $request): bool => $request['url'] === $url),
        ));
    }
}
