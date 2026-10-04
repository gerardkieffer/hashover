<?php

declare(strict_types=1);

namespace HashOver\Http;

/**
 * A web service's answer to a Client request.
 */
final readonly class ClientResponse
{
    /**
     * @param array<string, string> $headers lower-cased names
     */
    public function __construct(
        public int $status,
        public string $body,
        public array $headers = [],
    ) {}

    public function header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }

    /**
     * The body decoded as a JSON object; empty if it isn't one
     *
     * @return array<mixed>
     */
    public function json(): array
    {
        $data = json_decode($this->body, true);

        return is_array($data) ? $data : [];
    }
}
