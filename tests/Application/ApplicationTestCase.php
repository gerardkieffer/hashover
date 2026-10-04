<?php

declare(strict_types=1);

namespace HashOver\Tests\Application;

use HashOver\Application;
use HashOver\Http\Response;
use HashOver\Model\Comment;
use HashOver\Storage\CommentRepository;
use HashOver\Storage\Database;
use HashOver\Tests\Support\Browser;
use HashOver\Tests\Support\FakeHttpClient;
use HashOver\Tests\Support\RecordingMailer;
use HashOver\Tests\Support\TestConfig;
use PHPUnit\Framework\TestCase;

abstract class ApplicationTestCase extends TestCase
{
    protected Database $database;
    protected RecordingMailer $mailer;
    protected FakeHttpClient $http;
    protected Application $app;

    protected function setUp(): void
    {
        $this->boot();
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function boot(array $config = []): void
    {
        $this->database = new Database(':memory:');
        $this->mailer = new RecordingMailer();
        $this->http = new FakeHttpClient();
        $this->app = new Application(TestConfig::create($config), $this->database, $this->mailer, http: $this->http);
    }

    /**
     * Restart with another configuration, keeping the database
     *
     * @param array<string, mixed> $config
     */
    protected function reconfigure(array $config): void
    {
        $this->app = new Application(TestConfig::create($config), $this->database, $this->mailer, http: $this->http);
    }

    /**
     * Run code; returns its result and what it wrote with error_log()
     *
     * @template T
     * @param callable(): T $code
     * @return array{T, string}
     */
    protected static function withErrorLog(callable $code): array
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'hashover-log');
        $previous = ini_set('error_log', $file);

        try {
            $result = $code();

            return [$result, (string) file_get_contents($file)];
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
            unlink($file);
        }
    }

    protected function browser(): Browser
    {
        return new Browser($this->app);
    }

    protected function admin(): Browser
    {
        $admin = $this->browser();
        $admin->post(['action' => 'login', 'name' => TestConfig::ADMIN_NAME, 'password' => TestConfig::ADMIN_PASSWORD]);
        self::assertArrayHasKey('hashover-admin', $admin->cookies);

        return $admin;
    }

    protected function comment(int $id): ?Comment
    {
        return new CommentRepository($this->database)->find($id);
    }

    protected function commentCount(): int
    {
        return $this->database->int('SELECT COUNT(*) FROM comments');
    }

    /**
     * @return array<string, mixed>
     */
    protected static function json(Response $response): array
    {
        $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }

    protected static function assertRedirectsWithMessage(Response $response, string $message): void
    {
        self::assertSame(303, $response->status);
        self::assertStringContainsString('hashover_message=' . $message, (string) $response->header('Location'));
    }
}
