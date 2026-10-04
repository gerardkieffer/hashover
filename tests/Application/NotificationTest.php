<?php

declare(strict_types=1);

namespace HashOver\Tests\Application;

final class NotificationTest extends ApplicationTestCase
{
    public function testOwnerIsNotifiedOfNewComments(): void
    {
        $this->browser()->comment(['name' => 'Alice', 'body' => 'Hello there', 'title' => 'My post']);

        self::assertCount(1, $this->mailer->sent);
        $mail = $this->mailer->sent[0];
        self::assertSame('owner@example.com', $mail['to']);
        self::assertSame('New comment on “My post”', $mail['subject']);
        self::assertStringContainsString('Alice wrote on “My post”', $mail['body']);
        self::assertStringContainsString('Hello there', $mail['body']);
        self::assertStringContainsString('https://example.com/blog/post#hashover-c1', $mail['body']);
        self::assertNull($mail['replyTo']);
    }

    public function testSubscribedAuthorIsNotifiedOfReplies(): void
    {
        $this->browser()->comment(['name' => 'Alice', 'email' => 'alice@example.org', 'notify' => '1', 'body' => 'question']);
        $this->browser()->comment(['name' => 'Bob', 'email' => 'bob@example.org', 'body' => 'answer', 'parent' => '1']);

        $recipients = array_column($this->mailer->sent, 'to');
        self::assertSame(['owner@example.com', 'owner@example.com', 'alice@example.org'], $recipients);
        self::assertStringContainsString('untick the notification option', $this->mailer->sent[2]['body']);
    }

    public function testUnsubscribedAuthorsAndSelfRepliesAreNotNotified(): void
    {
        $this->browser()->comment(['email' => 'alice@example.org', 'body' => 'question']);
        $this->browser()->comment(['email' => 'carol@example.org', 'notify' => '1', 'body' => 'other']);
        $this->browser()->comment(['body' => 'reply', 'parent' => '1']);
        $this->browser()->comment(['email' => 'carol@example.org', 'body' => 'own reply', 'parent' => '2']);

        self::assertNotContains('alice@example.org', array_column($this->mailer->sent, 'to'));
        self::assertNotContains('carol@example.org', array_column($this->mailer->sent, 'to'));
    }

    public function testReplyToCommenterIsOptIn(): void
    {
        $this->boot(['reply_to_commenter' => true]);
        $this->browser()->comment(['email' => 'alice@example.org', 'body' => 'x']);

        self::assertSame('alice@example.org', $this->mailer->sent[0]['replyTo']);
    }

    public function testOwnerIsNotNotifiedOfOwnComments(): void
    {
        $this->browser()->comment(['email' => 'Owner@Example.com', 'body' => 'x']);

        self::assertSame([], $this->mailer->sent);
    }
}
