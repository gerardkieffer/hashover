<?php

declare(strict_types=1);

namespace HashOver\Http;

/**
 * An HTTP response; sent by public/index.php, inspected by the tests.
 */
final class Response
{
    /** @var array<string, string> */
    private array $headers = [];

    /** @var array<string, Cookie> */
    private array $cookies = [];

    public function __construct(
        public private(set) string $body = '',
        public private(set) int $status = 200,
        string $contentType = 'text/html; charset=UTF-8',
    ) {
        $this->headers['Content-Type'] = $contentType;
        $this->headers['X-Content-Type-Options'] = 'nosniff';
        $this->headers['Cache-Control'] = 'no-store';
        $this->headers['Referrer-Policy'] = 'same-origin';
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function json(array $data, int $status = 200): self
    {
        return new self(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $status, 'application/json; charset=UTF-8');
    }

    public static function text(string $body, int $status = 200): self
    {
        return new self($body, $status, 'text/plain; charset=UTF-8');
    }

    /** "See Other" redirect after a form submission */
    public static function redirect(string $location): self
    {
        $response = new self('', 303);
        $response->headers['Location'] = $location;

        return $response;
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;

        return $this;
    }

    public function withCookie(Cookie $cookie): self
    {
        $this->cookies[$cookie->name] = $cookie;

        return $this;
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * @return array<string, Cookie>
     */
    public function cookies(): array
    {
        return $this->cookies;
    }

    public function cookie(string $name): ?Cookie
    {
        return $this->cookies[$name] ?? null;
    }

    public function send(): void
    {
        http_response_code($this->status);

        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }

        foreach ($this->cookies as $cookie) {
            $cookie->send();
        }

        echo $this->body;
    }
}
