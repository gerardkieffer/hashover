<?php

declare(strict_types=1);

namespace HashOver\Http;

/**
 * An immutable view of the incoming HTTP request with string-only accessors.
 */
final readonly class Request
{
    /**
     * @param array<array-key, mixed> $query
     * @param array<array-key, mixed> $post
     * @param array<array-key, mixed> $cookies
     * @param array<array-key, mixed> $server
     */
    public function __construct(
        private array $query = [],
        private array $post = [],
        private array $cookies = [],
        private array $server = [],
    ) {}

    public static function fromGlobals(): self
    {
        return new self($_GET, $_POST, $_COOKIE, $_SERVER);
    }

    /**
     * The same request as it would look with the response's cookies applied
     *
     * @param array<string, Cookie> $cookies
     */
    public function withCookies(array $cookies): self
    {
        $values = $this->cookies;

        foreach ($cookies as $cookie) {
            if ($cookie->isDeletion()) {
                unset($values[$cookie->name]);
            } else {
                $values[$cookie->name] = $cookie->value;
            }
        }

        return new self($this->query, $this->post, $values, $this->server);
    }

    public function method(): string
    {
        return strtoupper($this->server('REQUEST_METHOD', 'GET'));
    }

    public function isPost(): bool
    {
        return $this->method() === 'POST';
    }

    public function query(string $name, string $default = ''): string
    {
        $value = $this->query[$name] ?? $default;

        return is_string($value) ? $value : $default;
    }

    public function post(string $name, string $default = ''): string
    {
        $value = $this->post[$name] ?? $default;

        return is_string($value) ? $value : $default;
    }

    public function hasPost(string $name): bool
    {
        return isset($this->post[$name]);
    }

    public function cookie(string $name): string
    {
        $value = $this->cookies[$name] ?? '';

        return is_string($value) ? $value : '';
    }

    /**
     * @return list<string>
     */
    public function cookieNames(): array
    {
        return array_values(array_filter(array_keys($this->cookies), is_string(...)));
    }

    public function header(string $name): string
    {
        return $this->server('HTTP_' . strtoupper(str_replace('-', '_', $name)));
    }

    public function server(string $name, string $default = ''): string
    {
        $value = $this->server[$name] ?? $default;

        return is_scalar($value) ? (string) $value : $default;
    }

    public function isHttps(): bool
    {
        $https = strtolower($this->server('HTTPS'));

        return ($https !== '' && $https !== 'off') || $this->server('SERVER_PORT') === '443';
    }

    public function ip(): string
    {
        $ip = $this->server('REMOTE_ADDR');

        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '';
    }

    /** Whether the client asked for a JSON response (requests made by hashover.js) */
    public function wantsJson(): bool
    {
        return str_contains($this->header('Accept'), 'application/json');
    }

    /** Absolute URL of the current page, used when HashOver is included by PHP */
    public function url(): string
    {
        $host = $this->server('HTTP_HOST');

        return ($this->isHttps() ? 'https' : 'http') . '://' . $host . $this->server('REQUEST_URI', '/');
    }
}
