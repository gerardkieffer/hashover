<?php

declare(strict_types=1);

namespace HashOver\Tests\Application;

use HashOver\Application;
use HashOver\Http\Response;
use HashOver\Model\Comment;
use HashOver\Storage\CommentRepository;
use HashOver\Storage\Database;
use HashOver\Tests\Support\Browser;
use HashOver\Tests\Support\RecordingMailer;
use HashOver\Tests\Support\TestConfig;
use PHPUnit\Framework\TestCase;

abstract class ApplicationTestCase extends TestCase
{
    protected Database $database;
    protected RecordingMailer $mailer;
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
        $this->app = new Application(TestConfig::create($config), $this->database, $this->mailer);
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
