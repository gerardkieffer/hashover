<?php

declare(strict_types=1);

namespace HashOver\Tests\Application;

use HashOver\Http\Request;
use HashOver\Security\Keys;
use HashOver\Security\Turnstile;
use HashOver\Security\Visitor;
use HashOver\Tests\Support\Browser;
use HashOver\Tests\Support\TestConfig;

final class TurnstileTest extends ApplicationTestCase
{
    private const array KEYS = [
        'turnstile_site_key' => '0x4AAAAAAAsitekey',
        'turnstile_secret_key' => '0x4AAAAAAAsecretkey',
    ];

    protected function setUp(): void
    {
        // A comment to like and reply to, posted before Turnstile is enabled
        parent::setUp();
        $this->browser()->comment(['name' => 'Alice', 'body' => 'first']);
        $this->reconfigure(self::KEYS);
        $this->cloudflareSays(['success' => true, 'hostname' => 'www.example.com', 'action' => Turnstile::ACTION]);
    }

    public function testDisabledByDefault(): void
    {
        $this->boot();
        $html = $this->browser()->thread()->body;

        self::assertStringNotContainsString('data-hashover-turnstile', $html);
        self::assertStringNotContainsString('aria-disabled', $html);
        self::assertSame(303, $this->browser()->comment(['body' => 'no check needed'])->status);
        self::assertSame([], $this->http->requests);
    }

    public function testControlsAreDisabledUntilVerified(): void
    {
        $html = $this->browser()->thread()->body;

        self::assertStringContainsString('data-hashover-turnstile="0x4AAAAAAAsitekey" data-hashover-verified="0"', $html);
        self::assertMatchesRegularExpression('/<div class="hashover-verify" id="hashover-verify" tabindex="-1">/', $html);
        self::assertStringContainsString('The security check needs JavaScript.', $html);
        self::assertMatchesRegularExpression('/class="hashover-like"[^>]* data-hashover-gated="hashover-c1-gate" aria-disabled="true" aria-describedby="hashover-c1-likes hashover-c1-gate"/', $html);
        self::assertMatchesRegularExpression('/class="hashover-reply"[^>]*data-hashover-gated="hashover-c1-gate" aria-disabled="true" aria-describedby="hashover-c1-gate"/s', $html);
        self::assertMatchesRegularExpression('/value="comment" class="hashover-primary" data-hashover-gated="hashover-verify-hint" aria-disabled="true"/', $html);
        self::assertMatchesRegularExpression('/<span class="hashover-gate-note" id="hashover-c1-gate" aria-hidden="true">To like or reply/', $html);
        // Logging in doesn't need the check
        self::assertDoesNotMatchRegularExpression('/value="login"[^>]*aria-disabled/', $html);
    }

    public function testPostingEditingAndLikingNeedAPass(): void
    {
        $browser = $this->browser();

        foreach ([
            ['action' => 'comment', 'body' => 'hello'],
            ['action' => 'like', 'id' => '1'],
            ['action' => 'edit', 'id' => '1', 'body' => 'changed'],
        ] as $fields) {
            $response = $browser->post($fields, json: true);
            self::assertSame(403, $response->status);
            self::assertSame('error.verification_required', self::json($response)['error']);
        }

        self::assertSame(1, $this->commentCount());
        self::assertRedirectsWithMessage($browser->comment(['body' => 'no JavaScript']), 'error.verification_required');
    }

    public function testTheAdministratorNeedsAPassToo(): void
    {
        $admin = $this->admin();

        self::assertSame(403, $admin->post(['action' => 'edit', 'id' => '1', 'body' => 'changed'], json: true)->status);
    }

    public function testVerifyingGivesAPass(): void
    {
        $browser = $this->browser();
        $data = self::json($browser->post(['action' => 'verify', 'token' => 'widget token'], json: true));

        self::assertTrue($data['ok']);
        self::assertSame(3600, $data['remaining']);
        self::assertSame('Thank you! You can now post, like and reply.', $data['message']);
        self::assertArrayHasKey(Turnstile::COOKIE, $browser->cookies);
        self::assertSame([[
            'secret' => '0x4AAAAAAAsecretkey',
            'response' => 'widget token',
            'remoteip' => '192.0.2.1',
        ]], $this->http->sentTo(Turnstile::VERIFY_URL));

        self::assertSame(200, $browser->post(['action' => 'like', 'id' => '1'], json: true)->status);
        self::assertSame(200, $browser->comment(['body' => 'verified'], json: true)->status);
        self::assertSame(2, $this->commentCount());

        $html = $browser->thread()->body;
        self::assertMatchesRegularExpression('/data-hashover-verified="(3600|3599)"/', $html);
        self::assertStringNotContainsString('aria-disabled', $html);
        self::assertStringContainsString('<div class="hashover-verify" id="hashover-verify" tabindex="-1" hidden>', $html);
        self::assertMatchesRegularExpression('/<span class="hashover-gate-note" id="hashover-c1-gate" aria-hidden="true" hidden>/', $html);
        self::assertMatchesRegularExpression('/class="hashover-like"[^>]* data-hashover-gated="hashover-c1-gate" aria-describedby="hashover-c1-likes">/', $html);
    }

    public function testPassLengthIsConfigurable(): void
    {
        $this->reconfigure(['turnstile_pass_minutes' => 5] + self::KEYS);

        self::assertSame(300, self::json($this->browser()->post(['action' => 'verify', 'token' => 't'], json: true))['remaining']);
    }

    public function testRejectedTokensGiveNoPass(): void
    {
        $this->cloudflareSays(['success' => false, 'error-codes' => ['timeout-or-duplicate']]);
        $browser = $this->browser();
        $response = $browser->post(['action' => 'verify', 'token' => 'used token'], json: true);

        self::assertSame(403, $response->status);
        self::assertSame('error.verification_failed', self::json($response)['error']);
        self::assertArrayNotHasKey(Turnstile::COOKIE, $browser->cookies);
    }

    public function testTokensFromOtherSitesOrWidgetsAreRejected(): void
    {
        foreach ([
            ['success' => true, 'hostname' => 'evil.example', 'action' => Turnstile::ACTION],
            ['success' => true, 'hostname' => 'example.com', 'action' => 'login'],
        ] as $answer) {
            $this->cloudflareSays($answer);
            self::assertSame(403, $this->browser()->post(['action' => 'verify', 'token' => 't'], json: true)->status);
        }
    }

    public function testCloudflareTestKeysAnswerForLocalhost(): void
    {
        $keys = ['turnstile_site_key' => '1x00000000000000000000AA', 'turnstile_secret_key' => '1x0000000000000000000000000000000AA'];
        $this->reconfigure($keys);
        $this->cloudflareSays(['success' => true, 'hostname' => 'localhost', 'action' => 'test']);

        self::assertSame(200, $this->browser()->post(['action' => 'verify', 'token' => 'XXXX.DUMMY.TOKEN.XXXX'], json: true)->status);
    }

    public function testUnreachableCloudflareGivesNoPass(): void
    {
        $this->http->responses = [];
        $browser = $this->browser();
        [$response, $log] = self::withErrorLog(static fn() => $browser->post(['action' => 'verify', 'token' => 't'], json: true));

        self::assertStringContainsString('Turnstile verification unavailable', $log);

        self::assertSame(503, $response->status);
        self::assertSame('error.verification_unavailable', self::json($response)['error']);
    }

    public function testMissingOrOversizedTokensAreNotSent(): void
    {
        self::assertSame(400, $this->browser()->post(['action' => 'verify', 'token' => ''], json: true)->status);
        self::assertSame(400, $this->browser()->post(['action' => 'verify', 'token' => str_repeat('x', 2049)], json: true)->status);
        self::assertSame([], $this->http->requests);
    }

    public function testVerifyingIsRateLimited(): void
    {
        $this->reconfigure(['rate_limits' => ['verify' => [2, 600]]] + self::KEYS);
        $browser = $this->browser();

        $browser->post(['action' => 'verify', 'token' => 't'], json: true);
        $browser->post(['action' => 'verify', 'token' => 't'], json: true);
        self::assertSame(429, $browser->post(['action' => 'verify', 'token' => 't'], json: true)->status);
    }

    public function testVerifyingIsUnavailableWhenDisabled(): void
    {
        $this->boot();

        self::assertSame(400, $this->browser()->post(['action' => 'verify', 'token' => 't'], json: true)->status);
        self::assertSame([], $this->http->requests);
    }

    public function testPassIsBoundToTheVisitorsAddress(): void
    {
        $browser = $this->verifiedBrowser();
        $browser->ip = '192.0.2.50';

        self::assertSame(403, $browser->post(['action' => 'like', 'id' => '1'], json: true)->status);
    }

    public function testForgedAndExpiredPassesAreRejected(): void
    {
        $config = TestConfig::create(self::KEYS);
        $visitor = new Visitor($config, new Keys($config));
        $browser = $this->browser();
        $sign = static fn(int $expires): string => $expires . '.' . new Keys($config)->mac('turnstile', $expires . "\0" . $visitor->id(new Request(server: ['REMOTE_ADDR' => $browser->ip])));

        $browser->cookies[Turnstile::COOKIE] = (time() + 600) . '.' . str_repeat('0', 64);
        self::assertSame(403, $browser->post(['action' => 'like', 'id' => '1'], json: true)->status);

        $browser->cookies[Turnstile::COOKIE] = $sign(time() - 1);
        self::assertSame(403, $browser->post(['action' => 'like', 'id' => '1'], json: true)->status);

        $browser->cookies[Turnstile::COOKIE] = $sign(time() + 600);
        self::assertSame(200, $browser->post(['action' => 'like', 'id' => '1'], json: true)->status);
    }

    public function testVerifyingWithoutJavaScriptRedirectsToTheForm(): void
    {
        $browser = $this->browser();
        $response = $browser->post(['action' => 'verify', 'token' => 't']);

        self::assertRedirectsWithMessage($response, 'message.verified');
        self::assertArrayHasKey(Turnstile::COOKIE, $browser->cookies);
    }

    /**
     * @param array<string, mixed> $answer
     */
    private function cloudflareSays(array $answer): void
    {
        $this->http->json(Turnstile::VERIFY_URL, $answer);
    }

    private function verifiedBrowser(): Browser
    {
        $browser = $this->browser();
        self::assertSame(200, $browser->post(['action' => 'verify', 'token' => 't'], json: true)->status);

        return $browser;
    }
}
