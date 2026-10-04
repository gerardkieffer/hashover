<?php

declare(strict_types=1);

namespace HashOver\Tests\Application;

use HashOver\Security\Akismet;
use HashOver\Tests\Support\TestConfig;

final class AkismetTest extends ApplicationTestCase
{
    private const string CHECK = Akismet::BASE_URL . 'comment-check';

    protected function setUp(): void
    {
        $this->boot(['akismet_key' => 'abc123']);
        $this->http->text(self::CHECK, 'false');
    }

    public function testCommentsAreSentToAkismet(): void
    {
        $this->browser()->comment([
            'name' => 'Alice',
            'email' => 'alice@example.org',
            'website' => 'alice.example.org',
            'body' => 'Nice post',
        ]);

        self::assertSame(1, $this->commentCount());

        $fields = $this->http->sentTo(self::CHECK)[0] ?? [];
        self::assertSame('abc123', $fields['api_key']);
        self::assertSame('https://example.com/', $fields['blog']);
        self::assertSame(TestConfig::PAGE, $fields['permalink']);
        self::assertSame('192.0.2.1', $fields['user_ip']);
        self::assertSame('comment', $fields['comment_type']);
        self::assertSame('Alice', $fields['comment_author']);
        self::assertSame('alice@example.org', $fields['comment_author_email']);
        self::assertSame('https://alice.example.org', $fields['comment_author_url']);
        self::assertSame('Nice post', $fields['comment_content']);
        self::assertSame('en', $fields['blog_lang']);
        // Empty values are left out
        self::assertArrayNotHasKey('referrer', $fields);
    }

    public function testRepliesAreMarkedAsSuch(): void
    {
        $this->browser()->comment(['body' => 'first']);
        $this->browser()->comment(['body' => 'second', 'parent' => '1']);

        self::assertSame('reply', $this->http->sentTo(self::CHECK)[1]['comment_type'] ?? null);
    }

    public function testSpamIsRejected(): void
    {
        $this->http->text(self::CHECK, 'true', ['x-akismet-pro-tip' => 'discard']);
        $response = $this->browser()->comment(['body' => 'Cheap pills'], json: true);

        self::assertSame(403, $response->status);
        $data = self::json($response);
        self::assertSame('error.spam_filter', $data['error']);
        self::assertSame('body', $data['field']);
        self::assertSame('Our spam filter flagged your comment, so it wasn’t posted.', $data['message']);
        self::assertSame(0, $this->commentCount());
        self::assertSame([], $this->mailer->sent);
    }

    public function testSpamIsRejectedWithoutJavaScript(): void
    {
        $this->http->text(self::CHECK, 'true');

        self::assertRedirectsWithMessage($this->browser()->comment(['body' => 'Cheap pills']), 'error.spam_filter');
        self::assertSame(0, $this->commentCount());
    }

    public function testEditsAreChecked(): void
    {
        $browser = $this->browser();
        $browser->comment(['name' => 'Bob', 'password' => 'bob password', 'body' => 'harmless']);
        $this->http->text(self::CHECK, 'true');

        self::assertSame(403, $browser->post(['action' => 'edit', 'id' => '1', 'name' => 'Bob', 'body' => 'Cheap pills'], json: true)->status);
        self::assertSame('harmless', $this->comment(1)?->body);
    }

    public function testTheAdministratorIsNotChecked(): void
    {
        $admin = $this->admin();
        $this->http->text(self::CHECK, 'true');

        self::assertSame(200, $admin->comment(['name' => TestConfig::ADMIN_NAME, 'body' => 'Official answer'], json: true)->status);
        self::assertSame(200, $admin->post(['action' => 'edit', 'id' => '1', 'name' => TestConfig::ADMIN_NAME, 'body' => 'Edited answer'], json: true)->status);
        self::assertSame([], $this->http->sentTo(self::CHECK));
    }

    public function testCommentsAreAcceptedWhenAkismetCantAnswer(): void
    {
        $this->http->responses = [];
        [$response, $log] = self::withErrorLog(fn() => $this->browser()->comment(['body' => 'unchecked']));
        self::assertRedirectsWithMessage($response, 'message.comment_posted');
        self::assertStringContainsString('Akismet unreachable', $log);

        $this->http->text(self::CHECK, 'invalid', ['x-akismet-debug-help' => 'We were unable to parse your blog URI']);
        [$response, $log] = self::withErrorLog(fn() => $this->browser()->comment(['body' => 'unchecked too']));
        self::assertRedirectsWithMessage($response, 'message.comment_posted');
        self::assertStringContainsString('unexpected Akismet answer "invalid": We were unable to parse your blog URI', $log);

        self::assertSame(2, $this->commentCount());
    }

    public function testDisabledByDefault(): void
    {
        $this->boot();
        $this->browser()->comment(['body' => 'Hi']);

        self::assertSame(1, $this->commentCount());
        self::assertSame([], $this->http->requests);
    }
}
