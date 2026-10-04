<?php

declare(strict_types=1);

namespace HashOver\Storage;

/**
 * Timestamps are stored as UTC "Y-m-d H:i:s" strings, which sort correctly.
 *
 * @internal
 */
final class Clock
{
    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    public static function ago(int $seconds): string
    {
        return gmdate('Y-m-d H:i:s', time() - $seconds);
    }
}
