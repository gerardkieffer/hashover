<?php

declare(strict_types=1);

namespace HashOver\Storage;

use HashOver\Config;
use HashOver\Exception\UserError;

/**
 * Fixed-window rate limiting per visitor and action.
 */
final readonly class RateLimiter
{
    public function __construct(
        private Database $db,
        private Config $config,
    ) {}

    /**
     * Count an attempt; throws once the visitor exceeded the limit
     *
     * @param string $bucket "comment", "auth" or "like"
     * @param string $visitor a keyed hash identifying the visitor
     * @throws UserError
     */
    public function hit(string $bucket, string $visitor): void
    {
        [$limit, $window] = $this->config->rateLimit($bucket);
        $key = $bucket . ':' . $visitor;
        $now = time();

        $hits = $this->db->transaction(function () use ($key, $now, $window): int {
            $this->db->execute(
                'INSERT INTO rate_limits (bucket, window_start, hits) VALUES (:key, :now, 1)
                 ON CONFLICT (bucket) DO UPDATE SET
                    hits = CASE WHEN window_start <= :expired THEN 1 ELSE hits + 1 END,
                    window_start = CASE WHEN window_start <= :expired THEN :now ELSE window_start END',
                ['key' => $key, 'now' => $now, 'expired' => $now - $window],
            );

            // Occasionally clean up expired windows
            if (random_int(1, 100) === 1) {
                $this->db->execute('DELETE FROM rate_limits WHERE window_start <= :old', ['old' => $now - 86400]);
            }

            return $this->db->int('SELECT hits FROM rate_limits WHERE bucket = :key', ['key' => $key]);
        });

        if ($hits > $limit) {
            throw new UserError('error.rate_limited', 429);
        }
    }
}
