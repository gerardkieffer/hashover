<?php

declare(strict_types=1);

namespace HashOver\Http;

/**
 * A cookie that JavaScript can't read and that isn't sent with cross-site requests.
 */
final readonly class Cookie
{
    public function __construct(
        public string $name,
        public string $value,
        public int $expires,
        public bool $secure,
        public string $path = '/',
    ) {}

    public static function delete(string $name, bool $secure, string $path = '/'): self
    {
        return new self($name, '', 1, $secure, $path);
    }

    public function isDeletion(): bool
    {
        return $this->value === '' || $this->expires < time();
    }

    public function send(): void
    {
        setcookie($this->name, $this->value, [
            'expires' => $this->expires,
            'path' => $this->path,
            'secure' => $this->secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
