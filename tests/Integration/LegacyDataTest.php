<?php

declare(strict_types=1);

namespace HashOver\Tests\Integration;

/**
 * Comment files written by HashOver 1.0.x keep working
 */
final class LegacyDataTest extends IntegrationTestCase
{
	/** The XOR obfuscation of HashOver 1.0.x */
	private static function legacyXor(string $string): string
	{
		$key = TestServer::ENCRYPTION_KEY;
		$key_length = min(strlen($key), 32);

		for ($i = 0, $j = 0; $i < strlen($string); $i++) {
			$char = ord($string[$i]);
			$string[$i] = ($char & 0xE0) ? chr($char ^ (ord($key[$j]) & 0x1F)) : chr($char);
			$j = ($j + 1) % $key_length;
		}

		return $string;
	}

	protected function setUp(): void
	{
		parent::setUp();

		$dir = $this->server->pagesDir('js-html');
		mkdir($dir, 0755, true);

		// Written as older PHP versions did: quotes stored unescaped
		$xml = new \SimpleXMLElement('<comment likes="3" notifications="yes" ipaddr=""/>');
		$xml->name = 'Old "onmouseover=alert(1)//';
		$xml->email = self::legacyXor('old@example.test');
		$xml->passwd = md5(self::legacyXor('oldpw'));
		$xml->website = 'javascript:alert(1)';
		$xml->date = '01/02/2020 - 3:04pm';
		$xml->body = 'legacy body';
		$xml->asXML($dir . '/1.xml');
	}

	public function testLegacyCommentRendersSafely(): void
	{
		$comment = $this->renderJs($this->client())->jsComments()[0];

		$this->assertMatchesRegularExpression('/\d+ years? ago/', $comment['date']);
		$this->assertStringContainsString(md5('old@example.test'), $comment['avatar']);
		$this->assertStringContainsString('Old &quot;onmouseover', $comment['name']);
		$this->assertStringNotContainsString('javascript:', $comment['name']);
		$this->assertStringNotContainsString('"onmouseover', $comment['reply_link']);
	}

	public function testLegacyPasswordWorksAndIsUpgraded(): void
	{
		$this->client()->comment(['cmtfile' => '1', 'edit' => 'Save', 'name' => 'Old', 'password' => 'oldpw', 'email' => 'old@example.test', 'comment' => 'edited']);

		$comment = $this->commentFile('1');
		$this->assertSame('edited', (string) $comment->body);
		$this->assertTrue(password_verify('oldpw', (string) $comment->passwd));
		$this->assertStringStartsWith('v2', (string) $comment->email);
		$this->assertSame('3', (string) $comment['likes']);
	}

	public function testWrongLegacyPasswordIsRejected(): void
	{
		$this->client()->comment(['cmtfile' => '1', 'delete' => 'Delete', 'password' => 'wrong', 'comment' => 'x']);

		$this->commentFile('1');
	}
}
