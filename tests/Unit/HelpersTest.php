<?php

declare(strict_types=1);

namespace HashOver\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Escaping and validation helpers in scripts/functions.php
 */
final class HelpersTest extends TestCase
{
	public function testHtmlEscapingCoversHtmlAndJavaScriptStringContexts(): void
	{
		$escaped = h("<a href=\"x\" title='y'>&\\\n\r");

		$this->assertSame('&lt;a href=&quot;x&quot; title=&#039;y&#039;&gt;&amp;&#92;&#10;&#13;', $escaped);
	}

	public function testHtmlEscapingCanKeepExistingEntities(): void
	{
		$this->assertSame('Tom &amp; &quot;Jerry&quot;', h('Tom & &quot;Jerry&quot;', false));
	}

	public function testHtmlEscapingReplacesInvalidUtf8(): void
	{
		$this->assertSame("a\u{FFFD}b", h("a\xC3b"));
	}

	public function testJavaScriptValuesCannotBreakOutOfScripts(): void
	{
		$encoded = js_value(['x' => "</script><script>'\"&\u{2028}"]);

		$this->assertStringNotContainsString('<', $encoded);
		$this->assertStringNotContainsString("'", $encoded);
		$this->assertStringNotContainsString("\u{2028}", $encoded);
		$this->assertSame(['x' => "</script><script>'\"&\u{2028}"], json_decode($encoded, true));
	}

	public function testXmlEscaping(): void
	{
		$this->assertSame('&lt;x a=&quot;1&quot;&gt;&amp;&apos;', xml_escape('<x a="1">&\''));
	}

	/**
	 * @return iterable<string, array{string, string}>
	 */
	public static function urls(): iterable
	{
		yield 'http' => ['http://example.org/a?b=1', 'http://example.org/a?b=1'];
		yield 'https' => ['https://example.org', 'https://example.org'];
		yield 'trimmed' => ['  https://example.org ', 'https://example.org'];
		yield 'javascript' => ['javascript:alert(1)', ''];
		yield 'javascript with http inside' => ['javascript:alert(1)//http://x', ''];
		yield 'data' => ['data:text/html,<script>', ''];
		yield 'relative' => ['/path', ''];
		yield 'protocol relative' => ['//evil.test', ''];
		yield 'ftp' => ['ftp://example.org', ''];
	}

	#[DataProvider('urls')]
	public function testSafeUrl(string $input, string $expected): void
	{
		$this->assertSame($expected, safe_url($input));
	}

	public function testSafeEmail(): void
	{
		$this->assertSame('a@example.org', safe_email(' a@example.org '));
		$this->assertSame('', safe_email("a@example.org\r\nBcc: x@evil.test"));
		$this->assertSame('', safe_email('not an email'));
	}

	/**
	 * @return iterable<string, array{string, bool}>
	 */
	public static function commentIds(): iterable
	{
		yield 'top level' => ['1', true];
		yield 'reply' => ['12-3', true];
		yield 'nested' => ['1-2-3-4', true];
		yield 'zero' => ['0', false];
		yield 'leading zero' => ['01', false];
		yield 'traversal' => ['../1', false];
		yield 'trailing dash' => ['1-', false];
		yield 'empty' => ['', false];
		yield 'newline' => ["1\n", false];
	}

	#[DataProvider('commentIds')]
	public function testCommentIds(string $id, bool $valid): void
	{
		$this->assertSame($valid, is_comment_id($id));
	}

	public function testThreadNames(): void
	{
		$this->assertTrue(is_thread_name('blog-post-1-lang-%C3%A9'));
		$this->assertFalse(is_thread_name('../etc'));
		$this->assertFalse(is_thread_name('a/b'));
		$this->assertFalse(is_thread_name("a'b"));
		$this->assertFalse(is_thread_name(''));
	}

	public function testOwnUrlComparesHostsExactly(): void
	{
		$this->assertTrue(is_own_url('https://example.com/page'));
		$this->assertTrue(is_own_url('http://www.example.com/'));
		$this->assertFalse(is_own_url('http://example.com.evil.test/'));
		$this->assertFalse(is_own_url('http://evilexample.com/'));
		$this->assertFalse(is_own_url('http://example.com:8080/'));
		$this->assertFalse(is_own_url('/relative'));
	}

	public function testDomainValidation(): void
	{
		$this->assertSame('example.com:8080', clean_domain('Example.com:8080'));
		$this->assertSame('[::1]:80', clean_domain('[::1]:80'));
		$this->assertNull(clean_domain('evil"><x'));
		$this->assertNull(clean_domain("example.com\r\nX: y"));
	}
}
