<?php

declare(strict_types=1);

namespace HashOver\Tests\Integration;

final class LikeTest extends IntegrationTestCase
{
	protected function setUp(): void
	{
		parent::setUp();

		$this->client()->comment(['name' => 'Alice', 'email' => 'alice@example.test', 'comment' => 'like me']);
	}

	private function like(Client $client, string $target = 'js-html/1'): Response
	{
		return $client->post('/hashover/scripts/like.php', ['like' => $target]);
	}

	private function likes(): int
	{
		return (int) $this->commentFile('1')['likes'];
	}

	public function testLikeToggles(): void
	{
		$client = $this->client();

		$this->assertSame('1 likes!', $this->like($client)->body);
		$this->assertSame(1, $this->likes());

		$this->assertSame('Unliked >;)', $this->like($client)->body);
		$this->assertSame(0, $this->likes());

		$this->like($client);
		$this->assertSame(1, $this->likes());
	}

	public function testGetRequestIsRejected(): void
	{
		$response = $this->client()->get('/hashover/scripts/like.php?like=js-html/1', ['Referer' => $this->server->baseUrl() . '/js.html']);

		$this->assertSame(403, $response->status);
		$this->assertSame(0, $this->likes());
	}

	public function testCrossSiteLikeIsRejected(): void
	{
		$response = $this->client()->post('/hashover/scripts/like.php', ['like' => 'js-html/1'], ['Origin' => 'http://evil.test']);

		$this->assertSame(403, $response->status);
	}

	public function testPosterCannotLikeOwnComment(): void
	{
		$alice = $this->client();
		$alice->setCookie('email', 'alice@example.test');

		$this->assertSame('Practice altruism!', $this->like($alice)->body);
		$this->assertSame(0, $this->likes());
	}

	public function testInvalidTargetsAreRejectedWithoutEcho(): void
	{
		foreach (['....//....//scripts/x', '<script>alert(1)</script>/1', 'js-html/../1', 'js-html/9'] as $target) {
			$response = $this->like($this->client(), $target);

			$this->assertContains($response->status, [400, 404], $target);
			$this->assertStringNotContainsString('<script>', $response->body);
			$this->assertStringStartsWith('text/plain', (string) $response->header('Content-Type'));
		}
	}
}
