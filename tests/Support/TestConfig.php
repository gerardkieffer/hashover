<?php

declare(strict_types=1);

namespace HashOver\Tests\Support;

use HashOver\Config;

final class TestConfig
{
    public const string ADMIN_NAME = 'Boss';
    public const string ADMIN_PASSWORD = 'admin password';
    public const string HOST = 'example.com';
    public const string PAGE = 'https://example.com/blog/post';

    private static ?string $adminHash = null;

    /**
     * @return array<string, mixed>
     */
    public static function values(): array
    {
        // Hashing is slow on purpose; do it once
        self::$adminHash ??= password_hash(self::ADMIN_PASSWORD, PASSWORD_DEFAULT);

        return [
            'secret_key' => str_repeat('0123456789abcdef', 4),
            'admin_name' => self::ADMIN_NAME,
            'admin_password_hash' => self::$adminHash,
            'allowed_hosts' => [self::HOST, 'www.' . self::HOST],
            'notification_email' => 'owner@example.com',
            'sender_email' => 'noreply@example.com',
            'minimum_submit_seconds' => 0,
            'data_directory' => sys_get_temp_dir() . '/hashover-tests',
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public static function create(array $overrides = []): Config
    {
        return Config::fromArray($overrides + self::values());
    }
}
