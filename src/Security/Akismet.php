<?php

declare(strict_types=1);

namespace HashOver\Security;

use HashOver\Config;
use HashOver\Http\Client;
use HashOver\Http\Request;
use HashOver\Page\Page;

/**
 * Akismet spam filter (opt-in, as it sends comments and their authors' data
 * to Automattic). Fails open: comments are accepted when Akismet can't answer.
 */
final readonly class Akismet
{
    public const string BASE_URL = 'https://rest.akismet.com/1.1/';

    public function __construct(
        private Config $config,
        private Client $http,
    ) {}

    public function isEnabled(): bool
    {
        return $this->config->akismetKey !== '';
    }

    /**
     * Whether Akismet considers the comment spam
     *
     * @param array{name: string, email: string, website: string, body: string, reply: bool} $comment
     */
    public function isSpam(Request $request, Page $page, array $comment): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        $fields = [
            'api_key' => $this->config->akismetKey,
            'blog' => self::homepage($page->url),
            'user_ip' => $request->ip(),
            'user_agent' => $request->header('User-Agent'),
            'referrer' => $request->header('Referer'),
            'permalink' => $page->url,
            'comment_type' => $comment['reply'] ? 'reply' : 'comment',
            'comment_author' => $comment['name'],
            'comment_author_email' => $comment['email'],
            'comment_author_url' => $comment['website'],
            'comment_content' => $comment['body'],
            'comment_date_gmt' => gmdate('c'),
            'blog_lang' => $this->config->language,
            'blog_charset' => 'UTF-8',
        ];

        $response = $this->http->postForm(self::BASE_URL . 'comment-check', array_filter($fields, static fn(string $value): bool => $value !== ''));

        if ($response === null) {
            error_log('HashOver: Akismet unreachable; comment accepted unchecked');

            return false;
        }

        $answer = trim($response->body);

        if ($answer !== 'true' && $answer !== 'false') {
            error_log('HashOver: unexpected Akismet answer "' . mb_substr($answer, 0, 100) . '"'
                . ($response->header('X-akismet-debug-help') !== '' ? ': ' . $response->header('X-akismet-debug-help') : '')
                . '; comment accepted unchecked');

            return false;
        }

        return $answer === 'true';
    }

    /**
     * Whether Akismet accepts the configured key; null when it can't be reached
     */
    public function isValidKey(string $siteUrl): ?bool
    {
        $response = $this->http->postForm(self::BASE_URL . 'verify-key', ['api_key' => $this->config->akismetKey, 'blog' => self::homepage($siteUrl)]);

        return $response === null ? null : trim($response->body) === 'valid';
    }

    /** "https://host/" of a URL */
    private static function homepage(string $url): string
    {
        $parts = parse_url($url);
        $scheme = is_array($parts) && isset($parts['scheme']) ? $parts['scheme'] : 'https';
        $host = Config::hostOf($url) ?? '';

        return $scheme . '://' . $host . '/';
    }
}
