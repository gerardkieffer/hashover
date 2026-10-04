<?php

declare(strict_types=1);

namespace HashOver\Security;

use HashOver\Config;
use HashOver\Http\Request;

/**
 * Identifies visitors without storing their IP address: a keyed hash of the
 * address is used for rate limiting and likes.
 */
final readonly class Visitor
{
    public function __construct(
        private Config $config,
        private Keys $keys,
    ) {}

    public function id(Request $request): string
    {
        return $this->keys->mac('visitor', $request->ip());
    }

    public function isBlocked(Request $request): bool
    {
        return in_array($request->ip(), $this->config->blockedIps, true);
    }

    /**
     * Whether stopforumspam.com lists the visitor's address (opt-in, as it
     * sends the address to a third party); fails open when unreachable
     */
    public function isKnownSpammer(Request $request): bool
    {
        if (!$this->config->stopForumSpam || $request->ip() === '') {
            return false;
        }

        $context = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);
        $response = @file_get_contents('https://api.stopforumspam.org/api?json&ip=' . rawurlencode($request->ip()), false, $context);

        if ($response === false) {
            return false;
        }

        $result = json_decode($response, true);

        return is_array($result) && is_array($result['ip'] ?? null) && in_array($result['ip']['appears'] ?? 0, [1, true, '1'], true);
    }
}
