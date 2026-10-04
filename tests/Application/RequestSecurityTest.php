<?php

declare(strict_types=1);

namespace HashOver\Tests\Application;

use HashOver\Tests\Support\TestConfig;

final class RequestSecurityTest extends ApplicationTestCase
{
    public function testCrossSitePostsAreRejected(): void
    {
        $browser = $this->browser();

        foreach ([['HTTP_ORIGIN' => 'https://evil.example'], ['HTTP_REFERER' => 'https://evil.example/page'], ['HTTP_ORIGIN' => 'null'], ['HTTP_ORIGIN' => 'https://example.com.evil.example']] as $headers) {
            $response = $browser->post(['action' => 'comment', 'body' => 'csrf'], json: true, headers: $headers);
            self::assertSame(403, $response->status, (string) json_encode($headers));
        }

        self::assertSame(0, $this->commentCount());
    }

    public function testCsrfTokenIsRequiredAndBoundToLoginCookies(): void
    {
        $alice = $this->browser();
        $alice->comment(['name' => 'Alice', 'password' => 'pw', 'body' => 'original']);

        // Missing token
        $response = $alice->postRaw(['action' => 'edit', 'url' => TestConfig::PAGE, 'id' => '1', 'body' => 'x'], json: true);
        self::assertSame(400, $response->status);

        // A token issued to a visitor without the login cookie
        $attacker = $this->browser();
        $token = $attacker->formFields()['csrf'];
        $response = $alice->postRaw(['action' => 'edit', 'url' => TestConfig::PAGE, 'id' => '1', 'body' => 'x', 'csrf' => $token], json: true);
        self::assertSame(400, $response->status);

        self::assertSame('original', $this->comment(1)?->body);
    }

    public function testHoneypotAndSignedTimestamp(): void
    {
        $browser = $this->browser();

        self::assertSame(400, $browser->comment(['body' => 'spam', 'hashover-hp' => 'http://spam.example'], json: true)->status);
        self::assertSame(400, $browser->comment(['body' => 'x', 'ts' => (time() - 10) . '.forged'], json: true)->status);
        self::assertSame(400, $browser->comment(['body' => 'x', 'ts' => 'garbage'], json: true)->status);
        self::assertSame(0, $this->commentCount());
    }

    public function testFormsSentTooFastAreRejected(): void
    {
        $this->boot(['minimum_submit_seconds' => 30]);

        $response = $this->browser()->comment(['body' => 'bot'], json: true);

        self::assertSame(400, $response->status);
        self::assertSame('That was very fast. Please wait a few seconds and send the form again.', self::json($response)['message']);
    }

    public function testRateLimits(): void
    {
        $this->boot(['rate_limits' => ['comment' => [2, 600], 'auth' => [2, 600]]]);
        $browser = $this->browser();

        $browser->comment(['body' => 'one']);
        $browser->comment(['body' => 'two']);
        self::assertSame(429, $browser->comment(['body' => 'three'], json: true)->status);

        // Other visitors aren't affected
        $other = $this->browser();
        $other->ip = '192.0.2.50';
        self::assertSame(303, $other->comment(['body' => 'four'])->status);

        // Wrong passwords are limited separately
        $guesser = $this->browser();
        $guesser->ip = '192.0.2.60';
        $guesser->post(['action' => 'login', 'name' => TestConfig::ADMIN_NAME, 'password' => 'a'], json: true);
        $guesser->post(['action' => 'login', 'name' => TestConfig::ADMIN_NAME, 'password' => 'b'], json: true);
        $response = $guesser->post(['action' => 'login', 'name' => TestConfig::ADMIN_NAME, 'password' => TestConfig::ADMIN_PASSWORD], json: true);
        self::assertSame(429, $response->status);
        self::assertNull($response->cookie('hashover-admin'));
    }

    public function testBlockedAddresses(): void
    {
        $this->boot(['blocked_ips' => ['192.0.2.1']]);

        self::assertSame(403, $this->browser()->comment(['body' => 'x'], json: true)->status);

        // Without JavaScript, the message is shown as text rather than JSON
        $response = $this->browser()->comment(['body' => 'x']);
        self::assertSame(403, $response->status);
        self::assertStringStartsWith('text/plain', (string) $response->header('Content-Type'));
        self::assertSame('Sorry, you can’t post comments.', $response->body);
        self::assertSame(0, $this->commentCount());
    }

    public function testOnlyConfiguredHostsHaveThreads(): void
    {
        foreach (['https://evil.example/page', 'javascript:alert(1)', '/relative', 'https://example.com:8080/page', ''] as $url) {
            $response = $this->browser()->get(['action' => 'thread', 'url' => $url]);
            self::assertContains($response->status, [400, 403], $url);
            self::assertStringStartsWith('text/plain', (string) $response->header('Content-Type'));
        }
    }

    public function testRedirectsOnlyGoToTheCommentedPage(): void
    {
        $response = $this->browser()->post(['action' => 'comment', 'body' => 'x'], url: 'https://www.example.com/page');

        self::assertStringStartsWith('https://www.example.com/page?', (string) $response->header('Location'));
    }

    public function testSecurityHeaders(): void
    {
        $response = $this->browser()->thread();

        self::assertSame('nosniff', $response->header('X-Content-Type-Options'));
        self::assertSame('no-store', $response->header('Cache-Control'));
        self::assertSame('same-origin', $response->header('Referrer-Policy'));
    }

    public function testCookiesAreSecureOnHttps(): void
    {
        $response = $this->browser()->comment(['name' => 'Alice', 'password' => 'pw', 'body' => 'x']);

        foreach ($response->cookies() as $cookie) {
            self::assertTrue($cookie->secure, $cookie->name);
        }
    }

    public function testUnknownActions(): void
    {
        self::assertSame(404, $this->browser()->get(['action' => 'admin'])->status);
        self::assertSame(400, $this->browser()->post(['action' => 'drop'], json: true)->status);
    }
}
