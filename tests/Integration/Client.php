<?php

declare(strict_types=1);

namespace HashOver\Tests\Integration;

use RuntimeException;

/**
 * A minimal browser: sends requests to the test server and keeps cookies.
 */
final class Client
{
	/** @var array<string, string> */
	private array $cookies = [];

	public function __construct(
		private readonly TestServer $server,
	) {
	}

	/**
	 * @param array<string, string> $headers
	 */
	public function get(string $path, array $headers = []): Response
	{
		return $this->request('GET', $path, $headers);
	}

	/**
	 * POST a form, with the Origin and Referer of a page on the test site by default
	 *
	 * @param array<string, string> $fields
	 * @param array<string, string> $headers
	 */
	public function post(string $path, array $fields, array $headers = [], string $page = '/js.html'): Response
	{
		$headers += [
			'Origin' => $this->server->baseUrl(),
			'Referer' => $this->server->baseUrl() . $page,
		];

		return $this->request('POST', $path, $headers, http_build_query($fields));
	}

	/**
	 * Post a comment form to comments.php
	 *
	 * @param array<string, string> $fields
	 */
	public function comment(array $fields, string $page = '/js.html'): Response
	{
		return $this->post('/hashover/comments.php', $fields, [], $page);
	}

	public function cookie(string $name): ?string
	{
		return $this->cookies[$name] ?? null;
	}

	public function setCookie(string $name, string $value): void
	{
		$this->cookies[$name] = $value;
	}

	/**
	 * @param non-empty-string $method
	 * @param array<string, string> $headers
	 */
	public function request(string $method, string $path, array $headers = [], ?string $body = null): Response
	{
		$handle = curl_init($this->server->baseUrl() . $path);

		if ($handle === false) {
			throw new RuntimeException('curl_init failed');
		}

		$header_lines = [];

		foreach ($headers as $name => $value) {
			$header_lines[] = $name . ': ' . $value;
		}

		if ($this->cookies !== []) {
			$pairs = [];

			foreach ($this->cookies as $name => $value) {
				$pairs[] = $name . '=' . rawurlencode($value);
			}

			$header_lines[] = 'Cookie: ' . implode('; ', $pairs);
		}

		curl_setopt_array($handle, [
			CURLOPT_CUSTOMREQUEST => $method,
			CURLOPT_HTTPHEADER => $header_lines,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HEADER => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_TIMEOUT => 20,
		]);

		if ($body !== null) {
			curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
		}

		$raw = curl_exec($handle);

		if (!is_string($raw)) {
			throw new RuntimeException('Request failed: ' . curl_error($handle));
		}

		$header_size = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
		$response = Response::parse((int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), substr($raw, 0, $header_size), substr($raw, $header_size));

		foreach ($response->setCookies as $name => $cookie) {
			if ($cookie['value'] === '' || ($cookie['expires'] !== null && $cookie['expires'] < time())) {
				unset($this->cookies[$name]);
			} else {
				$this->cookies[$name] = $cookie['value'];
			}
		}

		return $response;
	}
}
