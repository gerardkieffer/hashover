<?php

declare(strict_types=1);

namespace HashOver\Tests\Application;

use HashOver\Tests\Support\TestConfig;

final class RenderingTest extends ApplicationTestCase
{
    private const string PAYLOAD = '"><img src=x onerror=alert(1)>';

    public function testEmptyThread(): void
    {
        $html = $this->browser()->thread()->body;

        self::assertStringContainsString('No comments yet', $html);
        self::assertStringContainsString('<h2 id="hashover-heading"', $html);
        self::assertSame(0, $this->database->int('SELECT COUNT(*) FROM threads'), 'Viewing must not create threads');
    }

    public function testUserInputIsEscapedEverywhere(): void
    {
        $browser = $this->browser();
        $browser->comment([
            'name' => self::PAYLOAD,
            'website' => 'https://example.org/"onmouseover="alert(1)',
            'body' => '<script>alert(1)</script><img src=x onerror=alert(1)><b onclick="x">b</b>',
            'title' => '</title><script>alert(1)</script>',
        ]);
        $browser->comment(['body' => 'reply', 'parent' => '1']);

        foreach ([$browser->thread()->body, $browser->thread(TestConfig::PAGE, ['sort' => 'newest'])->body, $browser->get(['action' => 'form', 'url' => TestConfig::PAGE, 'reply' => '1'])->body] as $html) {
            // Only escaped text may contain the payloads, never tags or attributes
            self::assertDoesNotMatchRegularExpression('/<img\s+src=x|<script\b/i', $html);
            self::assertDoesNotMatchRegularExpression('/<[a-z][^>]*\son(click|error|mouseover)=/i', $html);
        }
    }

    public function testFormattingInComments(): void
    {
        $this->browser()->comment(['body' => "<b>bold</b> <code><i>literal</i></code>\nhttps://example.org/a?b=1&c=2."]);

        $html = $this->browser()->thread()->body;
        self::assertStringContainsString('<b>bold</b> <code>&lt;i&gt;literal&lt;/i&gt;</code><br><a href="https://example.org/a?b=1&amp;c=2" rel="nofollow ugc noopener noreferrer">https://example.org/a?b=1&amp;c=2</a>.', $html);
    }

    public function testSortOrders(): void
    {
        $browser = $this->browser();
        $browser->comment(['name' => 'Zoe', 'body' => 'first']);
        $browser->comment(['name' => 'Adam', 'body' => 'second']);
        $browser->comment(['name' => 'Mia', 'body' => 'reply', 'parent' => '1']);

        $order = function (string $sort) use ($browser): array {
            preg_match_all('/<article class="hashover-comment" id="hashover-c(\d+)"/', $browser->thread(TestConfig::PAGE, ['sort' => $sort])->body, $matches);

            return array_map(intval(...), $matches[1]);
        };

        self::assertSame([1, 3, 2], $order('threaded'));
        self::assertSame([3, 2, 1], $order('newest'));
        self::assertSame([2, 3, 1], $order('name'));
        self::assertSame([1, 3, 2], $order('invalid'));

        self::assertStringContainsString('in reply to Zoe', $browser->thread(TestConfig::PAGE, ['sort' => 'newest'])->body);
        self::assertStringContainsString('aria-current="true">Newest first', $browser->thread(TestConfig::PAGE, ['sort' => 'newest'])->body);
    }

    public function testFormsWorkWithoutJavaScript(): void
    {
        $browser = $this->browser();
        $browser->comment(['name' => 'Alice', 'password' => 'pw', 'body' => 'hello']);

        $html = $browser->thread()->body;
        self::assertStringContainsString('href="' . TestConfig::PAGE . '?hashover_reply=1#hashover-c1-slot"', $html);

        $reply = $browser->thread(TestConfig::PAGE, ['reply' => '1'])->body;
        self::assertStringContainsString('id="hashover-form-1"', $reply);
        self::assertStringContainsString('name="parent" value="1"', $reply);

        $edit = $browser->thread(TestConfig::PAGE, ['edit' => '1'])->body;
        self::assertStringContainsString('>hello</textarea>', $edit);

        $delete = $browser->thread(TestConfig::PAGE, ['delete' => '1'])->body;
        self::assertStringContainsString('name="confirm" value="1"', $delete);
    }

    public function testMessagesAfterRedirect(): void
    {
        $html = $this->browser()->thread(TestConfig::PAGE, ['message' => 'message.comment_posted'])->body;
        self::assertMatchesRegularExpression('~role="status"[^>]*>\s*<p>Your comment was posted.</p>~', $html);

        $html = $this->browser()->thread(TestConfig::PAGE, ['message' => 'error.comment_required', 'field' => 'body'])->body;
        self::assertStringContainsString('Please write a comment.', $html);
        self::assertStringContainsString('aria-invalid="true"', $html);

        $html = $this->browser()->thread(TestConfig::PAGE, ['message' => 'error.<script>'])->body;
        self::assertStringNotContainsString('<script>', $html);
    }

    public function testAccessibleMarkup(): void
    {
        $this->browser()->comment(['name' => 'Alice', 'body' => 'hello']);
        $html = $this->browser()->thread()->body;

        // Every field has a label
        preg_match_all('/<(?:input|textarea)[^>]*\sid="([^"]+)"/', $html, $fields);

        foreach ($fields[1] as $id) {
            self::assertStringContainsString('for="' . $id . '"', $html, 'Missing label for #' . $id);
        }

        // Ids are unique
        preg_match_all('/\sid="([^"]+)"/', $html, $ids);
        self::assertSame($ids[1], array_unique($ids[1]));

        // Decorative avatars, no new windows, no inline scripts or styles
        self::assertStringContainsString('class="hashover-avatar" src="/hashover/images/avatar.png" alt=""', $html);
        self::assertStringNotContainsString('target="_blank"', $html);
        self::assertDoesNotMatchRegularExpression('/\son[a-z]+=|<script|\sstyle=/i', $html);
    }

    public function testCountEndpoint(): void
    {
        $browser = $this->browser();
        $browser->comment(['body' => 'one']);
        $browser->comment(['body' => 'two', 'parent' => '1']);

        $data = self::json($browser->get(['action' => 'count', 'url' => TestConfig::PAGE]));
        self::assertSame(['comments' => 1, 'replies' => 1, 'text' => '1 comment and 1 reply'], $data);

        $empty = self::json($browser->get(['action' => 'count', 'url' => 'https://example.com/none']));
        self::assertSame('0 comments', $empty['text']);
    }

    public function testRssFeed(): void
    {
        $this->browser()->comment(['name' => 'Alice <x>', 'body' => '<b>hi</b> & bye', 'title' => 'Post & more']);
        $response = $this->browser()->get(['action' => 'rss', 'url' => TestConfig::PAGE]);

        self::assertStringStartsWith('application/rss+xml', (string) $response->header('Content-Type'));
        self::assertStringContainsString('sandbox', (string) $response->header('Content-Security-Policy'));

        $xml = simplexml_load_string($response->body);
        self::assertNotFalse($xml, 'The feed must be well-formed XML');
        self::assertSame('Comments on “Post & more”', (string) $xml->channel->title);
        self::assertSame('Comment by Alice <x>', (string) $xml->channel->item[0]->title);
        self::assertSame('<b>hi</b> &amp; bye', (string) $xml->channel->item[0]->description);
    }

    public function testOtherLanguages(): void
    {
        foreach (['fr' => 'Commentaires', 'es' => 'Comentarios', 'ja' => 'コメント'] as $language => $heading) {
            $this->boot(['language' => $language]);
            $html = $this->browser()->thread()->body;

            self::assertStringContainsString('lang="' . $language . '"', $html);
            self::assertStringContainsString('>' . $heading . '</h2>', $html);
        }
    }
}
