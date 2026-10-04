<?php

declare(strict_types=1);

namespace HashOver\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Cross-site requests, path traversal, redirects and request headers
 */
final class RequestValidationTest extends IntegrationTestCase
{
	public function testCrossSitePostIsRejected(): void
	{
		$response = $this->client()->post('/hashover/comments.php', ['comment' => 'csrf'], ['Origin' => 'http://evil.test']);

		$this->assertSame(403, $response->status);
		$this->assertDirectoryDoesNotExist($this->server->pagesDir('js-html'));
	}

	public function testCrossSiteRefererWithoutOriginIsRejected(): void
	{
		$response = $this->client()->request('POST', '/hashover/comments.php', ['Referer' => 'http://evil.test/page'], 'comment=csrf');

		$this->assertSame(403, $response->status);
	}

	public function testLookalikeRefererHostIsRejected(): void
	{
		$response = $this->client()->get('/hashover/comments.php', ['Referer' => 'http://127.0.0.1.evil.test:' . $this->server->port . '/js.html']);

		$this->assertStringContainsString('External use not allowed', $response->body);
	}

	public function testMalformedHostHeaderIsRejected(): void
	{
		$response = $this->client()->get('/hashover/comments.php', ['Host' => 'evil"><x', 'Referer' => $this->server->baseUrl() . '/js.html']);

		$this->assertStringContainsString('Invalid domain name', $response->body);
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function maliciousCommentIds(): iterable
	{
		yield 'dot-dot-slash' => ['../../scripts/x'];
		yield 'filter bypass' => ['....//....//x'];
		yield 'absolute' => ['/etc/passwd'];
		yield 'dots only' => ['../..'];
		yield 'zero' => ['0'];
	}

	#[DataProvider('maliciousCommentIds')]
	public function testCommentIdsAreValidated(string $id): void
	{
		$client = $this->client();
		$client->comment(['name' => 'Alice', 'password' => 'pw', 'comment' => 'original']);

		$client->comment(['cmtfile' => $id, 'edit' => 'Save', 'password' => 'pw', 'comment' => 'x']);
		$client->comment(['cmtfile' => $id, 'delete' => 'Delete', 'password' => 'pw', 'comment' => 'x']);
		$client->comment(['reply_to' => $id, 'comment' => 'reply']);

		$files = array_map('basename', glob($this->server->pagesDir('js-html') . '/*') ?: []);
		$this->assertSame(['1.xml', '2.xml'], $files);
		$this->assertSame([], glob($this->server->appDir() . '/pages/*.xml') ?: []);
	}

	public function testRedirectAfterPostingStaysOnSite(): void
	{
		$response = $this->client()->post('/hashover/comments.php', ['comment' => 'hi'], ['Referer' => $this->server->baseUrl() . '//evil.test/path']);

		$this->assertStringStartsWith('/', (string) $response->location());
		$this->assertStringStartsNotWith('//', (string) $response->location());
	}

	public function testForeignCanonicalUrlIsIgnored(): void
	{
		$response = $this->client()->comment(['comment' => 'hi', 'canon_url' => 'http://evil.test/phish']);

		$this->assertSame('/js.html#c1', $response->location());
	}

	public function testCountLink(): void
	{
		$this->client()->comment(['comment' => 'hi']);
		$referer = ['Referer' => $this->server->baseUrl() . '/index.html'];

		$counted = $this->client()->get('/hashover/comments.php?count_link=' . rawurlencode($this->server->baseUrl() . '/js.html'), $referer);
		$hostile = $this->client()->get('/hashover/comments.php?count_link=' . rawurlencode('javascript:alert(1)//'), $referer);

		$this->assertStringContainsString('1 Comment', $counted->body);
		$this->assertStringNotContainsString('javascript', $hostile->body);
	}
}
