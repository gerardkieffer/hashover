<?php

declare(strict_types=1);

namespace HashOver\Tests\Integration;

/**
 * Cross-site scripting in JavaScript mode, PHP mode and the RSS feed
 */
final class OutputEscapingTest extends IntegrationTestCase
{
	private const PAYLOAD = '"><img src=x onerror=alert(1)>';

	public function testJavaScriptModeHasSafeHeaders(): void
	{
		$response = $this->renderJs($this->client());

		$this->assertSame(200, $response->status);
		$this->assertStringStartsWith('application/javascript', (string) $response->header('Content-Type'));
		$this->assertSame('nosniff', $response->header('X-Content-Type-Options'));
	}

	public function testStoredFieldsAreEscapedInJavaScriptMode(): void
	{
		$client = $this->client();
		$client->comment(['name' => 'x" onmouseover="alert(1)', 'email' => 'a@example.test', 'comment' => '<script>alert(1)</script> \'+alert(1)+\'']);

		$response = $this->renderJs($client);
		$comment = $response->jsComments()[0];

		$this->assertStringNotContainsString('<script>', $comment['comment']);
		$this->assertStringContainsString('&lt;script&gt;', $comment['comment']);
		$this->assertStringNotContainsString('" onmouseover', $comment['name']);
		$this->assertStringNotContainsString('" onmouseover', $comment['reply_link']);

		// User data never appears outside JavaScript string literals
		$this->assertStringNotContainsString("'+alert(1)+'", $response->body);
	}

	public function testCookieValuesAreEscapedInForms(): void
	{
		$client = $this->client();
		$client->setCookie('name', self::PAYLOAD);
		$client->setCookie('message', '<script>alert(1)</script>');
		$client->setCookie('replied', '1" onfocus="alert(1)');

		$js = $this->renderJs($client)->body;
		$php = $client->get('/php.php')->body;

		foreach ([$js, $php] as $body) {
			$this->assertStringNotContainsString('<img src=x', $body);
			$this->assertStringNotContainsString('<script>alert', $body);
			$this->assertStringNotContainsString('onfocus="alert', $body);
		}
	}

	public function testPhpModeEscapesReflectedQueryValues(): void
	{
		$body = $this->client()->get('/php.php?pagetitle=' . rawurlencode(self::PAYLOAD) . '&x=' . rawurlencode(self::PAYLOAD))->body;

		$this->assertStringNotContainsString('<img src=x', $body);
		$this->assertStringContainsString('&quot;&gt;&lt;img', $body);
	}

	public function testRssFeedEscapesQueryValues(): void
	{
		$this->client()->comment(['name' => 'Alice <b>', 'comment' => 'hi']);
		$xss = '<x:script xmlns:x="http://www.w3.org/1999/xhtml">alert(1)</x:script>';
		$response = $this->client()->get('/hashover/comments.php?rss=' . rawurlencode($this->server->baseUrl() . '/js.html') . '&title=' . rawurlencode($xss));

		$this->assertStringNotContainsString('<x:script', $response->body);
		$this->assertStringContainsString("sandbox", (string) $response->header('Content-Security-Policy'));
		$this->assertNotFalse(simplexml_load_string($response->body), 'The feed must be well-formed XML');
	}

	public function testRssFeedIgnoresNonHttpUrls(): void
	{
		$response = $this->client()->get('/hashover/comments.php?rss=' . rawurlencode('javascript:alert(1)//x'));

		$this->assertStringNotContainsString('javascript:', $response->body);
	}
}
