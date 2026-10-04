<?php

declare(strict_types=1);

namespace HashOver\Security;

use HashOver\Config;
use HashOver\Exception\UserError;
use HashOver\Http\Client;
use HashOver\Http\Cookie;
use HashOver\Http\Request;

/**
 * Cloudflare Turnstile: a visitor proves once that they are human and gets a
 * signed "pass" cookie, bound to their address, that lets them post, edit
 * and like until it expires. A Turnstile token can only be checked once, so
 * checking one per action would mean solving a challenge for every like.
 */
final readonly class Turnstile
{
    public const string COOKIE = 'hashover-human';
    public const string ACTION = 'hashover';
    public const string VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
    private const int MAXIMUM_TOKEN_LENGTH = 2048;

    public function __construct(
        private Config $config,
        private Keys $keys,
        private Visitor $visitor,
        private Client $http,
    ) {}

    public function isEnabled(): bool
    {
        return $this->config->turnstileSiteKey !== '';
    }

    /**
     * Whether the visitor may post, edit and like
     */
    public function isPassed(Request $request): bool
    {
        return !$this->isEnabled() || $this->remaining($request) > 0;
    }

    /**
     * Seconds left on the visitor's pass; 0 without a valid one
     */
    public function remaining(Request $request): int
    {
        $parts = explode('.', $request->cookie(self::COOKIE), 2);

        if (count($parts) !== 2 || !ctype_digit($parts[0]) || !hash_equals($this->signature((int) $parts[0], $request), $parts[1])) {
            return 0;
        }

        // A pass never lasts longer than configured now, even if it was issued for longer
        return max(0, min((int) $parts[0] - time(), $this->config->turnstilePassMinutes * 60));
    }

    /**
     * @throws UserError
     */
    public function requirePass(Request $request): void
    {
        if (!$this->isPassed($request)) {
            throw new UserError('error.verification_required', 403);
        }
    }

    /**
     * Check a token from the Turnstile widget with Cloudflare and issue a pass
     *
     * @throws UserError
     */
    public function verify(Request $request, string $token, bool $secure): Cookie
    {
        if (!$this->isEnabled()) {
            throw new UserError('error.invalid_request', 400);
        }

        if ($token === '' || strlen($token) > self::MAXIMUM_TOKEN_LENGTH) {
            throw new UserError('error.verification_failed', 400);
        }

        $fields = ['secret' => $this->config->turnstileSecretKey, 'response' => $token];

        if ($request->ip() !== '') {
            $fields['remoteip'] = $request->ip();
        }

        $response = $this->http->postForm(self::VERIFY_URL, $fields);
        $result = $response?->json() ?? [];

        // Fail closed: without Cloudflare's answer, nobody can be told apart from a bot
        if (!array_key_exists('success', $result)) {
            error_log('HashOver: Turnstile verification unavailable' . ($response !== null ? ' (HTTP ' . $response->status . ')' : ''));

            throw new UserError('error.verification_unavailable', 503);
        }

        if ($result['success'] !== true || !$this->isExpected($result)) {
            $codes = is_array($result['error-codes'] ?? null) ? implode(', ', array_filter($result['error-codes'], is_string(...))) : '';

            // A wrong secret key is the site owner's problem, not the visitor's
            if (str_contains($codes, 'secret')) {
                error_log('HashOver: Turnstile rejected the secret key (' . $codes . ')');
            }

            throw new UserError('error.verification_failed', 403);
        }

        $expires = time() + $this->config->turnstilePassMinutes * 60;

        return new Cookie(self::COOKIE, $expires . '.' . $this->signature($expires, $request), $expires, $secure);
    }

    /**
     * The token must come from HashOver's widget on one of the website's
     * pages. Cloudflare's test keys answer for "localhost" and "test".
     *
     * @param array<mixed> $result
     */
    private function isExpected(array $result): bool
    {
        if (self::isTestSecret($this->config->turnstileSecretKey)) {
            return true;
        }

        $hostname = is_string($result['hostname'] ?? null) ? strtolower($result['hostname']) : '';
        $hosts = array_map(static fn(string $host): string => (string) preg_replace('/:\d+$/', '', $host), $this->config->allowedHosts);

        return ($result['action'] ?? null) === self::ACTION && in_array($hostname, $hosts, true);
    }

    /** Cloudflare's documented dummy secret keys, for testing */
    public static function isTestSecret(string $secret): bool
    {
        return preg_match('/^[1-3]x0{31}AA$/D', $secret) === 1;
    }

    private function signature(int $expires, Request $request): string
    {
        return $this->keys->mac('turnstile', $expires . "\0" . $this->visitor->id($request));
    }
}
