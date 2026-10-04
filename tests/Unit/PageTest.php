<?php

declare(strict_types=1);

namespace HashOver\Tests\Unit;

use HashOver\Exception\UserError;
use HashOver\Page\Page;
use HashOver\Tests\Support\TestConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PageTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function urls(): iterable
    {
        yield 'root' => ['https://example.com', '/', 'https://example.com/'];
        yield 'path' => ['https://example.com/blog/post', '/blog/post', 'https://example.com/blog/post'];
        yield 'fragment dropped' => ['https://example.com/a#comments', '/a', 'https://example.com/a'];
        yield 'query sorted' => ['https://example.com/a?b=2&a=1', '/a?a=1&b=2', 'https://example.com/a?a=1&b=2'];
        yield 'own parameters dropped' => ['https://example.com/a?hashover_reply=3&id=1', '/a?id=1', 'https://example.com/a?id=1'];
        yield 'tracking parameters dropped' => ['https://example.com/a?utm_source=x&fbclid=y', '/a', 'https://example.com/a'];
        yield 'www host' => ['HTTP://WWW.EXAMPLE.COM/A', '/A', 'http://www.example.com/A'];
    }

    #[DataProvider('urls')]
    public function testNormalization(string $url, string $key, string $normalized): void
    {
        $page = Page::fromUrl($url, TestConfig::create());

        self::assertSame($key, $page->key);
        self::assertSame($normalized, $page->url);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejected(): iterable
    {
        yield 'other host' => ['https://evil.example/a'];
        yield 'subdomain' => ['https://evil.example.com/a'];
        yield 'other port' => ['https://example.com:8443/a'];
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'relative' => ['/a'];
        yield 'protocol relative' => ['//example.com/a'];
        yield 'empty' => [''];
    }

    #[DataProvider('rejected')]
    public function testOnlyThisWebsitesPagesAreAccepted(string $url): void
    {
        $this->expectException(UserError::class);

        Page::fromUrl($url, TestConfig::create());
    }

    public function testVeryLongUrlsGetBoundedKeys(): void
    {
        $page = Page::fromUrl('https://example.com/' . str_repeat('a', 5000), TestConfig::create());

        self::assertSame(1000, strlen($page->key));
    }

    public function testUrlWith(): void
    {
        $config = TestConfig::create();

        self::assertSame('https://example.com/a?hashover_reply=3#x', Page::fromUrl('https://example.com/a', $config)->urlWith(['hashover_reply' => 3], 'x'));
        self::assertSame('https://example.com/a?id=1&hashover_sort=name', Page::fromUrl('https://example.com/a?id=1', $config)->urlWith(['hashover_sort' => 'name']));
    }
}
