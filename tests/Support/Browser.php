<?php

declare(strict_types=1);

namespace HashOver\Tests\Support;

use HashOver\Application;
use HashOver\Http\Request;
use HashOver\Http\Response;

/**
 * Talks to the Application in-process like a browser: keeps cookies, reads
 * forms from rendered threads and posts them back.
 */
final class Browser
{
    /** @var array<string, string> */
    public array $cookies = [];

    public string $ip = '192.0.2.1';

    public function __construct(
        private readonly Application $app,
    ) {}

    /**
     * @param array<string, string> $query
     */
    public function get(array $query, bool $json = false): Response
    {
        return $this->send(new Request($query, [], $this->cookies, $this->server('GET', $json)));
    }

    /**
     * @param array<string, string> $query
     */
    public function thread(string $url = TestConfig::PAGE, array $query = []): Response
    {
        return $this->get(['action' => 'thread', 'url' => $url] + $query);
    }

    /**
     * Post a form the way hashover.js or a browser would, with fresh CSRF and timestamp fields
     *
     * @param array<string, string> $fields
     * @param array<string, string> $headers
     */
    public function post(array $fields, bool $json = false, array $headers = ['HTTP_ORIGIN' => 'https://example.com'], string $url = TestConfig::PAGE): Response
    {
        $form = $this->formFields($url);

        return $this->send(new Request([], $fields + ['url' => $url] + $form, $this->cookies, $headers + $this->server('POST', $json)));
    }

    /**
     * Post exactly the given fields
     *
     * @param array<string, string> $fields
     * @param array<string, string> $headers
     */
    public function postRaw(array $fields, array $headers = ['HTTP_ORIGIN' => 'https://example.com'], bool $json = false): Response
    {
        return $this->send(new Request([], $fields, $this->cookies, $headers + $this->server('POST', $json)));
    }

    /**
     * @param array<string, string> $fields
     */
    public function comment(array $fields, bool $json = false): Response
    {
        return $this->post($fields + ['action' => 'comment'], $json);
    }

    /**
     * Hidden csrf and ts fields of the thread's main form
     *
     * @return array<string, string>
     */
    public function formFields(string $url = TestConfig::PAGE): array
    {
        $html = $this->thread($url)->body;
        $fields = [];

        foreach (['csrf', 'ts'] as $name) {
            if (preg_match('/name="' . $name . '" value="([^"]*)"/', $html, $match) === 1) {
                $fields[$name] = html_entity_decode($match[1]);
            }
        }

        return $fields;
    }

    private function send(Request $request): Response
    {
        $response = $this->app->handle($request);

        foreach ($response->cookies() as $cookie) {
            if ($cookie->isDeletion()) {
                unset($this->cookies[$cookie->name]);
            } else {
                $this->cookies[$cookie->name] = $cookie->value;
            }
        }

        return $response;
    }

    /**
     * @return array<string, string>
     */
    private function server(string $method, bool $json): array
    {
        return [
            'REQUEST_METHOD' => $method,
            'HTTP_HOST' => TestConfig::HOST,
            'HTTPS' => 'on',
            'REMOTE_ADDR' => $this->ip,
            'HTTP_ACCEPT' => $json ? 'application/json' : 'text/html',
        ];
    }
}
