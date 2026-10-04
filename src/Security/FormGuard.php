<?php

declare(strict_types=1);

namespace HashOver\Security;

use HashOver\Config;
use HashOver\Exception\UserError;
use HashOver\Http\Request;

/**
 * Bot protection for forms: a hidden honeypot field and a signed timestamp
 * that rejects forms submitted implausibly fast or long after loading.
 */
final readonly class FormGuard
{
    public const string HONEYPOT_FIELD = 'hashover-hp';
    private const int MAXIMUM_AGE = 60 * 60 * 24 * 7;

    public function __construct(
        private Config $config,
        private Keys $keys,
    ) {}

    public function timestamp(?int $time = null): string
    {
        $time ??= time();

        return $time . '.' . $this->keys->mac('form', (string) $time);
    }

    /**
     * @throws UserError
     */
    public function check(Request $request): void
    {
        if ($request->post(self::HONEYPOT_FIELD) !== '') {
            throw new UserError('error.spam', 400);
        }

        $parts = explode('.', $request->post('ts'), 2);

        if (count($parts) !== 2 || !ctype_digit($parts[0]) || !hash_equals($this->keys->mac('form', $parts[0]), $parts[1])) {
            throw new UserError('error.form_expired', 400);
        }

        $age = time() - (int) $parts[0];

        if ($age > self::MAXIMUM_AGE) {
            throw new UserError('error.form_expired', 400);
        }

        if ($age < $this->config->minimumSubmitSeconds) {
            throw new UserError('error.too_fast', 400);
        }
    }
}
