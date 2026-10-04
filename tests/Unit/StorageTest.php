<?php

declare(strict_types=1);

namespace HashOver\Tests\Unit;

use HashOver\Exception\UserError;
use HashOver\Page\Page;
use HashOver\Storage\CommentRepository;
use HashOver\Storage\Database;
use HashOver\Storage\RateLimiter;
use HashOver\Storage\ThreadRepository;
use HashOver\Tests\Support\TestConfig;
use PHPUnit\Framework\TestCase;

final class StorageTest extends TestCase
{
    private Database $db;
    private CommentRepository $comments;
    private int $threadId;

    protected function setUp(): void
    {
        $this->db = new Database(':memory:');
        $this->comments = new CommentRepository($this->db);
        $this->threadId = new ThreadRepository($this->db)->findOrCreate(Page::fromUrl(TestConfig::PAGE, TestConfig::create()), 'Title')->id;
    }

    private function add(?int $parent = null): int
    {
        return $this->comments->insert($this->threadId, $parent, ['name' => 'x', 'password_hash' => null, 'login_verifier' => null, 'email' => null, 'email_hash' => null, 'website' => '', 'body' => 'b', 'notify' => true, 'ip_address' => null]);
    }

    public function testSchemaVersion(): void
    {
        self::assertSame(1, $this->db->int('PRAGMA user_version'));
        self::assertSame(1, $this->db->int('PRAGMA foreign_keys'));
    }

    public function testThreadsAreCreatedOnce(): void
    {
        $threads = new ThreadRepository($this->db);
        $page = Page::fromUrl(TestConfig::PAGE, TestConfig::create());

        self::assertSame($this->threadId, $threads->findOrCreate($page, 'Other title')->id);
        self::assertSame('Title', $threads->find($page)?->title);
    }

    public function testDeletingPlaceholderChains(): void
    {
        $a = $this->add();
        $b = $this->add($a);
        $c = $this->add($b);

        $this->comments->delete($a);
        $this->comments->delete($b);
        self::assertTrue($this->comments->find($a)?->deleted);
        self::assertTrue($this->comments->find($b)?->deleted);

        // Deleting the last reply removes the whole placeholder chain
        $this->comments->delete($c);
        self::assertSame(0, $this->db->int('SELECT COUNT(*) FROM comments'));
    }

    public function testIdsOfDeletedCommentsAreNeverReused(): void
    {
        $first = $this->add();
        $this->comments->delete($first);

        self::assertGreaterThan($first, $this->add());
    }

    public function testCountsExcludeDeletedComments(): void
    {
        $a = $this->add();
        $this->add($a);
        $this->add($a);
        $this->comments->delete($a);

        self::assertSame(['comments' => 0, 'replies' => 2], $this->comments->count($this->threadId));
    }

    public function testRateLimiterWindows(): void
    {
        $limiter = new RateLimiter($this->db, TestConfig::create(['rate_limits' => ['like' => [2, 60]]]));

        $limiter->hit('like', 'visitor');
        $limiter->hit('like', 'visitor');
        $limiter->hit('like', 'other');

        // An expired window starts over
        $this->db->execute("UPDATE rate_limits SET window_start = window_start - 61 WHERE bucket = 'like:other'");
        $limiter->hit('like', 'other');
        $limiter->hit('like', 'other');

        $this->expectException(UserError::class);
        $limiter->hit('like', 'visitor');
    }

    public function testDatabaseFileIsPrivate(): void
    {
        $file = sys_get_temp_dir() . '/hashover-' . bin2hex(random_bytes(4)) . '/hashover.sqlite';
        new Database($file);

        try {
            self::assertSame(0o600, fileperms($file) & 0o777);
            self::assertSame(0o700, fileperms(dirname($file)) & 0o777);
        } finally {
            $files = glob(dirname($file) . '/*');
            array_map(unlink(...), $files === false ? [] : $files);
            rmdir(dirname($file));
        }
    }
}
