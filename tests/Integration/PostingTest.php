<?php

declare(strict_types=1);

namespace HashOver\Tests\Integration;

final class PostingTest extends IntegrationTestCase
{
	public function testPostingStoresHashedPasswordAndEncryptedEmail(): void
	{
		$alice = $this->client();
		$response = $alice->comment([
			'name' => 'Alice',
			'password' => 'alicepw',
			'email' => 'alice@example.test',
			'website' => 'example.org/a',
			'comment' => 'Hello <b>bold</b>',
		]);

		$this->assertSame(302, $response->status);
		$this->assertSame('/js.html#c1', $response->location());

		$comment = $this->commentFile('1');
		$this->assertSame('Alice', (string) $comment->name);
		$this->assertTrue(password_verify('alicepw', (string) $comment->passwd));
		$this->assertStringStartsWith('v2', (string) $comment->email);
		$this->assertStringNotContainsString('alice', (string) $comment->email);
		$this->assertSame('http://example.org/a', (string) $comment->website);
		$this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $comment->login);
		$this->assertSame('Hello <b>bold</b>', (string) $comment->body);
	}

	public function testCookiesAreHttpOnlyAndSameSiteAndNeverHoldThePassword(): void
	{
		$alice = $this->client();
		$response = $alice->comment(['name' => 'Alice', 'password' => 'alicepw', 'comment' => 'Hi']);

		$this->assertNotEmpty($response->setCookies);

		foreach ($response->setCookies as $name => $cookie) {
			$this->assertStringContainsStringIgnoringCase('HttpOnly', $cookie['attributes'], $name);
			$this->assertStringContainsStringIgnoringCase('SameSite=Lax', $cookie['attributes'], $name);
			$this->assertStringNotContainsString('alicepw', $cookie['value'], $name);
		}

		$this->assertArrayNotHasKey('password', $response->setCookies);
		$this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $alice->cookie('hashover-login'));
	}

	public function testRepliesAndNumbering(): void
	{
		$client = $this->client();
		$client->comment(['comment' => 'first']);
		$client->comment(['comment' => 'second']);
		$response = $client->comment(['comment' => 'reply', 'reply_to' => '1']);
		$client->comment(['comment' => 'reply 2', 'reply_to' => '1']);
		$client->comment(['comment' => 'nested', 'reply_to' => '1-1']);

		$this->assertSame('/js.html#c1r1', $response->location());

		foreach (['1', '2', '1-1', '1-2', '1-1-1'] as $id) {
			$this->commentFile($id);
		}

		$comments = $this->renderJs($client)->jsComments();
		$this->assertSame(['c1', 'c1r1', 'c1r1r1', 'c1r2', 'c2'], array_column($comments, 'permalink'));
	}

	public function testReplyToMissingCommentIsNotWritten(): void
	{
		$client = $this->client();
		$client->comment(['comment' => 'first']);
		$response = $client->comment(['comment' => 'orphan', 'reply_to' => '7']);

		$this->assertSame('/js.html#comments', $response->location());
		$this->assertCommentMissing('7-1');
	}

	public function testEmptyAndOversizedCommentsAreRejected(): void
	{
		$client = $this->client();
		$client->comment(['comment' => "  \n  "]);
		$response = $client->comment(['comment' => str_repeat('a', 20001)]);

		$this->assertSame('/js.html#comments', $response->location());
		$this->assertSame('no', $response->setCookies['success']['value'] ?? null);
		$this->assertDirectoryDoesNotExist($this->server->pagesDir('js-html'));
	}

	public function testHoneypotFieldsBlockSpam(): void
	{
		$this->client()->comment(['comment' => 'buy now', 'address' => 'spam street']);

		$this->assertDirectoryDoesNotExist($this->server->pagesDir('js-html'));
	}

	public function testViewingDoesNotCreateThreadDirectories(): void
	{
		$this->renderJs($this->client(), '/some/new/page.html');

		$this->assertDirectoryDoesNotExist($this->server->pagesDir('some-new-page-html'));
	}

	public function testInvalidWebsiteAndEmailAreDropped(): void
	{
		$this->client()->comment(['website' => 'javascript:alert(1)//http://x', 'email' => 'not an email', 'comment' => 'hi']);

		$comment = $this->commentFile('1');
		$this->assertSame('', (string) $comment->website);
		$this->assertSame('', (string) $comment->email);
	}
}
