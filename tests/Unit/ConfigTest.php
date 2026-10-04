<?php

declare(strict_types=1);

namespace HashOver\Tests\Unit;

use HashOver\Config;
use HashOver\Exception\ConfigException;
use HashOver\Tests\Support\TestConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = TestConfig::create();

        self::assertSame('/hashover/', $config->baseUrl);
        self::assertFalse($config->gravatar);
        self::assertFalse($config->storeIpAddresses);
        self::assertFalse($config->stopForumSpam);
        self::assertSame([5, 600], $config->rateLimit('comment'));
        self::assertContains('utm_source', $config->ignoredQueryParameters);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalid(): iterable
    {
        yield 'short secret' => [['secret_key' => 'short'], 'secret_key'];
        yield 'plain admin password' => [['admin_password_hash' => 'password'], 'admin_password_hash'];
        yield 'no admin' => [['admin_name' => ' '], 'admin_name'];
        yield 'no hosts' => [['allowed_hosts' => []], 'allowed_hosts'];
        yield 'language' => [['language' => 'xx'], 'language'];
        yield 'timezone' => [['timezone' => 'Mars/Olympus'], 'timezone'];
        yield 'e-mail' => [['notification_email' => 'nope'], 'notification_email'];
        yield 'base url' => [['base_url' => '//evil.example/'], 'base_url'];
        yield 'type' => [['gravatar' => 'yes'], 'gravatar'];
        yield 'range' => [['comment_rows' => 0], 'comment_rows'];
        yield 'rate limit' => [['rate_limits' => ['comment' => [5]]], 'rate_limits'];
    }

    /**
     * @param array<string, mixed> $values
     */
    #[DataProvider('invalid')]
    public function testInvalidValuesAreExplained(array $values, string $key): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage($key);

        TestConfig::create($values);
    }

    public function testExampleFileIsCompleteAndOnlyNeedsSecrets(): void
    {
        $values = require dirname(__DIR__, 2) . '/config/config.example.php';
        self::assertIsArray($values);

        $config = Config::fromArray([
            'secret_key' => str_repeat('x', 64),
            'admin_name' => 'Admin',
            'admin_password_hash' => password_hash('x', PASSWORD_DEFAULT),
        ] + $values);

        self::assertSame(['example.com', 'www.example.com'], $config->allowedHosts);
    }

    public function testMissingFile(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('bin/hashover setup');

        Config::fromFile('/nonexistent/config.php');
    }
}
