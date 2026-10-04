<?php

declare(strict_types=1);

namespace HashOver\Tests\Http;

use PHPUnit\Framework\TestCase;

/**
 * The real entry points: public/index.php, embed.php and the static files
 */
final class HttpTest extends TestCase
{
    private TestServer $server;

    /** @var array<string, string> */
    private array $cookies = [];

    protected function setUp(): void
    {
        $this->server = TestServer::get();
    }

    protected function tearDown(): void
    {
        self::assertSame('', $this->server->phpErrors(), 'The server logged PHP errors');
    }

    /**
     * @param array<string, string>|null $post
     * @param list<string> $headers
     * @return array{status: int, headers: string, body: string}
     */
    private function request(string $path, ?array $post = null, array $headers = []): array
    {
        $handle = curl_init($this->server->url($path));
        self::assertNotFalse($handle);

        $cookies = [];

        foreach ($this->cookies as $name => $value) {
            $cookies[] = $name . '=' . rawurlencode($value);
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        if ($cookies !== []) {
            curl_setopt($handle, CURLOPT_COOKIE, implode('; ', $cookies));
        }

        if ($post !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($post));
        }

        $raw = curl_exec($handle);
        self::assertIsString($raw);
        $size = curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        $headerText = substr($raw, 0, $size);

        preg_match_all('/^Set-Cookie: ([^=]+)=([^;]*)/mi', $headerText, $matches, PREG_SET_ORDER);

        foreach ($matches as [, $name, $value]) {
            if ($value === '' || $value === 'deleted') {
                unset($this->cookies[$name]);
            } else {
                $this->cookies[$name] = rawurldecode($value);
            }
        }

        return ['status' => curl_getinfo($handle, CURLINFO_RESPONSE_CODE), 'headers' => $headerText, 'body' => substr($raw, $size)];
    }

    /**
     * @return array<string, string>
     */
    private static function formFields(string $html, string $formId = 'hashover-form'): array
    {
        self::assertMatchesRegularExpression('/id="' . $formId . '"/', $html);
        preg_match('~<form[^>]*id="' . $formId . '".*?</form>~s', $html, $form);
        preg_match_all('/<input type="hidden" name="([^"]+)" value="([^"]*)"/', $form[0] ?? '', $inputs, PREG_SET_ORDER);

        $fields = [];

        foreach ($inputs as [, $name, $value]) {
            $fields[$name] = html_entity_decode($value);
        }

        return $fields;
    }

    public function testPhpEmbedWorksWithoutJavaScript(): void
    {
        $page = $this->request('/page.php');
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('<div id="hashover" class="hashover"', $page['body']);
        self::assertStringContainsString('Post a comment on “PHP page”', $page['body']);

        $fields = self::formFields($page['body']);
        $response = $this->request('/hashover/index.php', $fields + ['action' => 'comment', 'name' => 'Alice', 'password' => 'pw', 'body' => 'Posted without JavaScript'], ['Origin: ' . $this->server->url()]);

        self::assertSame(303, $response['status']);
        self::assertMatchesRegularExpression('~^Location: ' . preg_quote($this->server->url('/page.php'), '~') . '\?hashover_message=message\.comment_posted#hashover-c\d+~mi', $response['headers']);
        self::assertMatchesRegularExpression('/^Set-Cookie: hashover-login=[a-f0-9]{64}; expires=[^;]+; Max-Age=\d+; path=\/; HttpOnly; SameSite=Lax/mi', $response['headers']);

        $page = $this->request('/page.php?hashover_message=message.comment_posted');
        self::assertStringContainsString('<p>Your comment was posted.</p>', $page['body']);
        self::assertStringContainsString('Posted without JavaScript', $page['body']);
        self::assertStringContainsString('hashover_edit=', $page['body']);
    }

    public function testJavaScriptEndpoint(): void
    {
        $thread = $this->request('/hashover/index.php?action=thread&url=' . rawurlencode($this->server->url('/js.html')));

        self::assertSame(200, $thread['status']);
        self::assertMatchesRegularExpression('/^X-Content-Type-Options: nosniff/mi', $thread['headers']);
        self::assertStringContainsString('class="hashover-thread"', $thread['body']);

        $foreign = $this->request('/hashover/index.php?action=thread&url=' . rawurlencode('https://evil.example/'));
        self::assertSame(403, $foreign['status']);

        $count = $this->request('/hashover/index.php?action=count&url=' . rawurlencode($this->server->url('/js.html')));
        self::assertMatchesRegularExpression('~^Content-Type: application/json~mi', $count['headers']);
    }

    public function testCrossSitePostIsRejected(): void
    {
        $response = $this->request('/hashover/index.php', ['action' => 'comment', 'url' => $this->server->url('/page.php'), 'body' => 'x'], ['Origin: https://evil.example', 'Accept: application/json']);

        self::assertSame(403, $response['status']);
    }

    public function testStaticFiles(): void
    {
        self::assertSame(200, $this->request('/hashover/hashover.js')['status']);
        self::assertSame(200, $this->request('/hashover/hashover.css')['status']);
        self::assertSame(200, $this->request('/hashover/images/avatar.png')['status']);
    }
}
