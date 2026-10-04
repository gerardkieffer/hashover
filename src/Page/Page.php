<?php

declare(strict_types=1);

namespace HashOver\Page;

use HashOver\Config;
use HashOver\Exception\UserError;

/**
 * A web page of the site, identified by its path and significant query
 * parameters; the host must be one of the configured host names.
 */
final readonly class Page
{
    private const int MAXIMUM_KEY_LENGTH = 1000;

    private function __construct(
        /** Thread identifier: path and sorted query, e.g. "/blog/post?id=3" */
        public string $key,
        /** Absolute URL without fragment and without HashOver parameters */
        public string $url,
    ) {}

    /**
     * @throws UserError if the URL isn't an http(s) URL of this website
     */
    public static function fromUrl(string $url, Config $config): self
    {
        $url = trim($url);
        $parts = parse_url($url);

        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw new UserError('error.invalid_page', 400);
        }

        $host = Config::hostOf($url);

        if ($host === null || !$config->allowsUrl($url)) {
            throw new UserError('error.invalid_page', 403);
        }

        $path = $parts['path'] ?? '/';
        $path = $path === '' ? '/' : $path;

        $queryString = self::significantQuery($parts['query'] ?? '', $config->ignoredQueryParameters);

        $key = $path . ($queryString !== '' ? '?' . $queryString : '');

        if (strlen($key) > self::MAXIMUM_KEY_LENGTH) {
            $key = substr($key, 0, self::MAXIMUM_KEY_LENGTH - 65) . '#' . hash('sha256', $key);
        }

        $url = strtolower($parts['scheme']) . '://' . $host . $path . ($queryString !== '' ? '?' . $queryString : '');

        return new self($key, $url);
    }

    /**
     * URL of the page with extra query parameters, e.g. to open a reply form
     *
     * @param array<string, string|int> $parameters
     */
    public function urlWith(array $parameters, string $fragment = ''): string
    {
        $separator = str_contains($this->url, '?') ? '&' : '?';
        $query = http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);

        return $this->url . ($query !== '' ? $separator . $query : '') . ($fragment !== '' ? '#' . $fragment : '');
    }

    /**
     * Drop HashOver's own and ignored parameters and sort the rest, keeping
     * the parameters exactly as written (parse_str() would rename some)
     *
     * @param list<string> $ignored
     */
    private static function significantQuery(string $query, array $ignored): string
    {
        $kept = [];

        foreach (explode('&', $query) as $parameter) {
            $name = urldecode(explode('=', $parameter, 2)[0]);

            if ($parameter === '' || str_starts_with($name, 'hashover_') || in_array($name, $ignored, true)) {
                continue;
            }

            $kept[] = $parameter;
        }

        // Sort by name only, keeping the order of repeated parameters
        usort($kept, static fn(string $a, string $b): int => strcmp(explode('=', $a, 2)[0], explode('=', $b, 2)[0]));

        return implode('&', $kept);
    }
}
