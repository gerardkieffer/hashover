<?php

declare(strict_types=1);

namespace HashOver\Tests\Application;

final class LikeTest extends ApplicationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->browser()->comment(['name' => 'Alice', 'email' => 'alice@example.org', 'body' => 'like me']);
    }

    public function testLikeToggles(): void
    {
        $browser = $this->browser();

        $data = self::json($browser->post(['action' => 'like', 'id' => '1'], json: true));
        self::assertTrue($data['liked']);
        self::assertSame(1, $data['likes']);
        self::assertSame('1 like', $data['likesText']);
        self::assertStringContainsString('aria-pressed="true"', $browser->thread()->body);

        $data = self::json($browser->post(['action' => 'like', 'id' => '1'], json: true));
        self::assertFalse($data['liked']);
        self::assertSame(0, $this->likes(1));
    }

    public function testOneLikePerVisitorEvenWithoutCookies(): void
    {
        $this->browser()->post(['action' => 'like', 'id' => '1']);
        $this->browser()->post(['action' => 'like', 'id' => '1']);

        // A fresh browser from the same address toggled the like off again
        self::assertSame(0, $this->likes(1));

        $other = $this->browser();
        $other->ip = '192.0.2.99';
        $other->post(['action' => 'like', 'id' => '1']);
        self::assertSame(1, $this->likes(1));
    }

    public function testVoterIsStoredAsKeyedHash(): void
    {
        $this->browser()->post(['action' => 'like', 'id' => '1']);

        $voter = $this->database->value('SELECT voter FROM likes');
        self::assertIsString($voter);
        self::assertStringNotContainsString('192.0.2.1', $voter);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $voter);
    }

    public function testPosterCannotLikeOwnComment(): void
    {
        $alice = $this->browser();
        $alice->cookies['hashover-author'] = json_encode(['name' => 'Alice', 'email' => 'alice@example.org', 'website' => ''], JSON_THROW_ON_ERROR);

        self::assertSame(403, $alice->post(['action' => 'like', 'id' => '1'], json: true)->status);
        self::assertStringNotContainsString('class="hashover-like"', $alice->thread()->body);
    }

    public function testLikingWithoutJavaScriptRedirectsToComment(): void
    {
        $response = $this->browser()->post(['action' => 'like', 'id' => '1']);

        self::assertSame(303, $response->status);
        self::assertStringEndsWith('#hashover-c1', (string) $response->header('Location'));
    }

    public function testUnknownCommentsCantBeLiked(): void
    {
        self::assertSame(404, $this->browser()->post(['action' => 'like', 'id' => '2'], json: true)->status);
        self::assertSame(404, $this->browser()->post(['action' => 'like', 'id' => '1'], json: true, url: 'https://example.com/other')->status);
    }

    public function testPopularComments(): void
    {
        $this->boot(['popular_threshold' => 2, 'popular_limit' => 1]);
        $this->browser()->comment(['body' => 'one']);
        $this->browser()->comment(['body' => 'two']);

        foreach (['192.0.2.10', '192.0.2.11'] as $ip) {
            $voter = $this->browser();
            $voter->ip = $ip;
            $voter->post(['action' => 'like', 'id' => '2']);
        }

        $html = $this->browser()->thread()->body;
        self::assertStringContainsString('Most popular comments', $html);
        self::assertStringContainsString('id="hashover-c2-popular"', $html);
        self::assertStringNotContainsString('id="hashover-c1-popular"', $html);
    }

    private function likes(int $id): int
    {
        return $this->database->int('SELECT likes FROM comments WHERE id = :id', ['id' => $id]);
    }
}
