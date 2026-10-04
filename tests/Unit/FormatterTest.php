<?php

declare(strict_types=1);

namespace HashOver\Tests\Unit;

use HashOver\Content\Formatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FormatterTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        yield 'plain text' => ['Hello', 'Hello'];
        yield 'allowed tags' => ['<b>b</b> <i>i</i> <u>u</u> <s>s</s>', '<b>b</b> <i>i</i> <u>u</u> <s>s</s>'];
        yield 'upper-case tags' => ['<B>bold</B>', '<b>bold</b>'];
        yield 'unclosed tags are closed' => ['<b>bold <i>both', '<b>bold <i>both</i></b>'];
        yield 'stray closing tags are dropped' => ['</b>text</ul>', 'text'];
        yield 'lists are repaired' => ['<ul><li>one<li>two</ul>', '<ul><li>one</li><li>two</li></ul>'];
        yield 'script is text' => ['<script>alert(1)</script>', '&lt;script&gt;alert(1)&lt;/script&gt;'];
        yield 'attributes disable a tag' => ['<b onclick="x">b</b>', '&lt;b onclick="x"&gt;b'];
        yield 'links are text' => ['<a href=x>y</a>', '&lt;a href=x&gt;y&lt;/a&gt;'];
        yield 'entities are text' => ['&lt;b&gt; &amp;', '&amp;lt;b&amp;gt; &amp;amp;'];
        yield 'line breaks' => ["one\ntwo\r\n\r\n\r\n\r\nthree", 'one<br>two<br><br>three'];
        yield 'code is literal' => ['<code><b>x</b> https://e.example</code>', '<code>&lt;b&gt;x&lt;/b&gt; https://e.example</code>'];
        yield 'unclosed code' => ['<code><b>x', '<code>&lt;b&gt;x</code>'];
        yield 'pre keeps line breaks' => ["<pre>a\n  b</pre>", "<pre>a\n  b</pre>"];
        yield 'urls become links' => ['see https://example.org/a?b=1&c=2.', 'see <a href="https://example.org/a?b=1&amp;c=2" rel="nofollow ugc noopener noreferrer">https://example.org/a?b=1&amp;c=2</a>.'];
        yield 'parentheses around urls' => ['(https://example.org/a)', '(<a href="https://example.org/a" rel="nofollow ugc noopener noreferrer">https://example.org/a</a>)'];
        yield 'parentheses inside urls' => ['https://e.example/a_(b)', '<a href="https://e.example/a_(b)" rel="nofollow ugc noopener noreferrer">https://e.example/a_(b)</a>'];
        yield 'quotes end urls' => ['https://e.example/"onmouseover=x', '<a href="https://e.example/" rel="nofollow ugc noopener noreferrer">https://e.example/</a>"onmouseover=x'];
        yield 'other schemes are text' => ['javascript:alert(1) data:text/html,x', 'javascript:alert(1) data:text/html,x'];
        yield 'images on request' => ['[img]https://e.example/p.png[/img]', '<a href="https://e.example/p.png" rel="nofollow ugc noopener noreferrer" class="hashover-image">https://e.example/p.png</a>'];
        yield 'image with javascript url' => ['[img]javascript:alert(1)[/img]', '[img]javascript:alert(1)[/img]'];
        yield 'control characters are removed' => ["a\u{0}b\u{7}c", 'abc'];
        yield 'bidirectional overrides are removed' => ["file\u{202E}gpj.exe", 'filegpj.exe'];
        yield 'emoji sequences are kept' => ["\u{1F469}\u{200D}\u{1F4BB}", "\u{1F469}\u{200D}\u{1F4BB}"];
        yield 'invalid utf-8' => ["a\xC3(b", 'a?(b'];
    }

    #[DataProvider('cases')]
    public function testToHtml(string $input, string $expected): void
    {
        self::assertSame($expected, new Formatter()->toHtml($input));
    }

    public function testNormalize(): void
    {
        self::assertSame("a\n\nb", Formatter::normalize("  a\r\n\r\n\r\n\rb  "));
    }
}
