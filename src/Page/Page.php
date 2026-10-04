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
        $parts = parse_url(trim($url));

        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw new UserError('error.invalid_page', 400);
        }

        $host = strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');

        if (!in_array($host, $config->allowedHosts, true)) {
            throw new UserError('error.invalid_page', 403);
        }

        $path = $parts['path'] ?? '/';
        $path = $path === '' ? '/' : $path;

        parse_str($parts['query'] ?? '', $query);
        $query = self::significantQuery($query, $config->ignoredQueryParameters);
        $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

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
     * Drop HashOver's own and ignored parameters; sort the rest
     *
     * @param array<mixed> $query
     * @param list<string> $ignored
     * @return array<mixed>
     */
    private static function significantQuery(array $query, array $ignored): array
    {
        foreach (array_keys($query) as $name) {
            if (str_starts_with((string) $name, 'hashover_') || in_array((string) $name, $ignored, true)) {
                unset($query[$name]);
            }
        }

        ksort($query, SORT_STRING);

        return $query;
    }
}
