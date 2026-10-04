<?php

declare(strict_types=1);

namespace HashOver\Tests\Application;

use HashOver\Security\EmailCipher;
use HashOver\Security\Keys;
use HashOver\Tests\Support\TestConfig;

final class PostingTest extends ApplicationTestCase
{
    public function testPostingStoresCommentAndRedirectsBackWithoutJavaScript(): void
    {
        $response = $this->browser()->comment([
            'name' => 'Alice',
            'password' => 'alice password',
            'email' => 'alice@example.org',
            'website' => 'alice.example.org',
            'body' => "Hello <b>world</b>\r\nSecond line",
            'notify' => '1',
            'title' => 'My post',
        ]);

        self::assertRedirectsWithMessage($response, 'message.comment_posted');
        self::assertStringStartsWith(TestConfig::PAGE . '?', (string) $response->header('Location'));
        self::assertStringEndsWith('#hashover-c1', (string) $response->header('Location'));

        $comment = $this->comment(1);
        self::assertNotNull($comment);
        self::assertSame('Alice', $comment->name);
        self::assertSame("Hello <b>world</b>\nSecond line", $comment->body);
        self::assertSame('https://alice.example.org', $comment->website);
        self::assertTrue($comment->notify);
        self::assertNotNull($comment->passwordHash);
        self::assertTrue(password_verify('alice password', $comment->passwordHash));
        self::assertNotNull($comment->email);
        self::assertStringNotContainsString('alice', $comment->email);
        self::assertSame('alice@example.org', new EmailCipher(new Keys(TestConfig::create()))->decrypt($comment->email));
    }

    public function testJsonResponseContainsRenderedThread(): void
    {
        $data = self::json($this->browser()->comment(['body' => 'Hi'], json: true));

        self::assertTrue($data['ok']);
        self::assertSame('hashover-c1', $data['focus']);
        self::assertSame('Your comment was posted.', $data['message']);
        self::assertIsString($data['html']);
        self::assertStringContainsString('id="hashover-c1"', $data['html']);
    }

    public function testUnsubscribedNewCommentIsNotMarkedEdited(): void
    {
        $browser = $this->browser();
        $browser->comment(['body' => 'no notifications please']);

        $comment = $this->comment(1);
        self::assertNotNull($comment);
        self::assertFalse($comment->notify);
        self::assertNull($comment->updatedAt);
        self::assertStringNotContainsString('hashover-edited', $browser->thread()->body);
    }

    public function testRejectedRepliesDontCreateThreads(): void
    {
        $this->browser()->comment(['body' => 'x', 'parent' => '1'], json: true);

        self::assertSame(0, $this->database->int('SELECT COUNT(*) FROM threads'));
    }

    public function testQueryParametersAreKeptAsWritten(): void
    {
        $response = $this->browser()->post(['action' => 'comment', 'body' => 'x'], url: 'https://example.com/view?doc.id=5&a%20b=1');

        self::assertSame('/view?a%20b=1&doc.id=5', $this->database->value('SELECT page_key FROM threads'));
        self::assertStringStartsWith('https://example.com/view?a%20b=1&doc.id=5&hashover_message=', (string) $response->header('Location'));
    }

    public function testAnonymousCommentWithoutPasswordGetsNoLogin(): void
    {
        $browser = $this->browser();
        $browser->comment(['body' => 'Hi']);

        $comment = $this->comment(1);
        self::assertNotNull($comment);
        self::assertSame('', $comment->name);
        self::assertNull($comment->passwordHash);
        self::assertNull($comment->loginVerifier);
        self::assertArrayNotHasKey('hashover-login', $browser->cookies);
        self::assertStringContainsString('Anonymous', $browser->thread()->body);
    }

    public function testRepliesAreNested(): void
    {
        $browser = $this->browser();
        $browser->comment(['body' => 'first']);
        $browser->comment(['body' => 'second']);
        $browser->comment(['body' => 'reply', 'parent' => '1']);
        $browser->comment(['body' => 'nested', 'parent' => '3']);

        self::assertSame(1, $this->comment(3)?->parentId);
        self::assertSame(3, $this->comment(4)?->parentId);

        $html = $browser->thread()->body;
        self::assertMatchesRegularExpression('~id="hashover-c1".*<ol class="hashover-list hashover-replies">.*id="hashover-c3".*id="hashover-c4".*id="hashover-c2"~s', $html);
        self::assertStringContainsString('2 comments and 2 replies', $html);
    }

    public function testReplyToMissingOrForeignCommentIsRejected(): void
    {
        $browser = $this->browser();
        $browser->comment(['body' => 'first']);
        $browser->post(['action' => 'comment', 'body' => 'elsewhere'], url: 'https://example.com/other');

        self::assertSame(404, $browser->comment(['body' => 'x', 'parent' => '99'], json: true)->status);
        self::assertSame(404, $browser->comment(['body' => 'x', 'parent' => '2'], json: true)->status);
        self::assertSame(2, $this->commentCount());
    }

    public function testValidation(): void
    {
        $this->boot(['rate_limits' => ['comment' => [100, 600]]]);
        $browser = $this->browser();

        $cases = [
            [['body' => "  \n "], 'body'],
            [['body' => str_repeat('a', 20001)], 'body'],
            [['body' => 'x', 'email' => 'not an e-mail'], 'email'],
            [['body' => 'x', 'email' => "a@example.org\r\nBcc: b@example.org"], 'email'],
            [['body' => 'x', 'website' => 'javascript:alert(1)'], 'website'],
            [['body' => 'x', 'website' => 'ftp://example.org'], 'website'],
        ];

        foreach ($cases as [$fields, $field]) {
            $response = $browser->comment($fields, json: true);
            $data = self::json($response);

            self::assertSame(400, $response->status, json_encode($fields, JSON_THROW_ON_ERROR));
            self::assertFalse($data['ok']);
            self::assertSame($field, $data['field']);
        }

        self::assertSame(0, $this->commentCount());
    }

    public function testValidationErrorWithoutJavaScriptReopensTheForm(): void
    {
        $browser = $this->browser();
        $browser->comment(['body' => 'first']);
        $response = $browser->comment(['body' => '', 'parent' => '1']);

        $location = (string) $response->header('Location');
        self::assertStringContainsString('hashover_message=error.comment_required', $location);
        self::assertStringContainsString('hashover_field=body', $location);
        self::assertStringContainsString('hashover_reply=1', $location);
        self::assertStringEndsWith('#hashover-form-1', $location);
    }

    public function testNamesAreSingleLineAndShortened(): void
    {
        $this->browser()->comment(['name' => "  A\nvery\tlong   name that goes on and on and on  ", 'body' => 'x']);

        self::assertSame('A very long name that goes on', $this->comment(1)?->name);
    }

    public function testThreadsAreSharedAcrossIgnoredQueryParameters(): void
    {
        $browser = $this->browser();
        $browser->post(['action' => 'comment', 'body' => 'one'], url: 'https://example.com/page?b=2&a=1&utm_source=x');
        $browser->post(['action' => 'comment', 'body' => 'two'], url: 'https://www.example.com/page?a=1&b=2#top');

        self::assertSame(1, $this->database->int('SELECT COUNT(*) FROM threads'));
        self::assertSame('/page?a=1&b=2', $this->database->value('SELECT page_key FROM threads'));
    }

    public function testIpAddressesAreOnlyStoredWhenEnabledAndThenForgotten(): void
    {
        $this->browser()->comment(['body' => 'x']);
        self::assertNull($this->database->value('SELECT ip_address FROM comments'));

        $this->boot(['store_ip_addresses' => true, 'ip_retention_days' => 1]);
        $this->browser()->comment(['body' => 'old']);
        self::assertSame('192.0.2.1', $this->database->value('SELECT ip_address FROM comments'));

        $this->database->execute("UPDATE comments SET created_at = '2000-01-01 00:00:00'");
        $this->browser()->comment(['body' => 'new']);

        self::assertNull($this->database->value('SELECT ip_address FROM comments WHERE id = 1'));
        self::assertSame('192.0.2.1', $this->database->value('SELECT ip_address FROM comments WHERE id = 2'));
    }

    public function testAuthorDetailsAreRememberedInHttpOnlyCookie(): void
    {
        $browser = $this->browser();
        $response = $browser->comment(['name' => 'Alice', 'email' => 'alice@example.org', 'website' => 'https://alice.example.org', 'body' => 'x']);

        $cookie = $response->cookie('hashover-author');
        self::assertNotNull($cookie);

        $html = $browser->thread()->body;
        self::assertStringContainsString('name="name" value="Alice"', $html);
        self::assertStringContainsString('name="email" value="alice@example.org"', $html);
    }
}
