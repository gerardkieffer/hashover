<?php

declare(strict_types=1);

namespace HashOver\Security;

use HashOver\Config;

/**
 * Derives independent keys from the configured secret, one per purpose,
 * so a key exposed in one context can't be used in another.
 */
final class Keys
{
    /** @var array<string, string> */
    private array $cache = [];

    public function __construct(
        private readonly Config $config,
    ) {}

    /** A 256-bit key for the given purpose */
    public function for(string $purpose): string
    {
        return $this->cache[$purpose] ??= hash_hkdf('sha256', $this->config->secretKey, 32, 'hashover:' . $purpose);
    }

    /** Keyed hash of a value for the given purpose (hex) */
    public function mac(string $purpose, string $value): string
    {
        return hash_hmac('sha256', $value, $this->for($purpose));
    }
}
