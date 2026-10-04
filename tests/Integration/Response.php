<?php

declare(strict_types=1);

namespace HashOver\Tests\Integration;

/**
 * An HTTP response from the test server.
 */
final class Response
{
	/**
	 * @param array<string, list<string>> $headers lower-cased header names
	 * @param array<string, array{value: string, expires: ?int, attributes: string}> $setCookies
	 */
	private function __construct(
		public readonly int $status,
		public readonly array $headers,
		public readonly array $setCookies,
		public readonly string $body,
	) {
	}

	public static function parse(int $status, string $raw_headers, string $body): self
	{
		$headers = [];
		$cookies = [];

		foreach (preg_split('/\r?\n/', trim($raw_headers)) ?: [] as $line) {
			if (!str_contains($line, ':')) {
				continue;
			}

			[$name, $value] = array_map('trim', explode(':', $line, 2));
			$headers[strtolower($name)][] = $value;

			if (strtolower($name) === 'set-cookie') {
				$parts = explode(';', $value);
				[$cookie_name, $cookie_value] = array_pad(explode('=', array_shift($parts), 2), 2, '');
				$expires = null;

				foreach ($parts as $part) {
					if (preg_match('/^\s*expires=(.+)$/i', $part, $match) === 1) {
						$expires = strtotime($match[1]) ?: null;
					}
				}

				$cookies[$cookie_name] = [
					'value' => rawurldecode($cookie_value),
					'expires' => $expires,
					'attributes' => implode(';', $parts),
				];
			}
		}

		return new self($status, $headers, $cookies, $body);
	}

	public function header(string $name): ?string
	{
		return $this->headers[strtolower($name)][0] ?? null;
	}

	/** Redirect target without the scheme and host */
	public function location(): ?string
	{
		return $this->header('Location');
	}

	/**
	 * Comment objects from the "var comments = [...]" block of JavaScript mode
	 *
	 * @return list<array<string, string>>
	 */
	public function jsComments(): array
	{
		if (preg_match('/^var comments = \[\n(.*?)\n\];$/ms', $this->body, $match) !== 1) {
			return [];
		}

		$comments = [];

		foreach (preg_split('/,\n\n/', trim($match[1])) ?: [] as $object) {
			$object = rtrim(trim($object), ',');

			if ($object === '') {
				continue;
			}

			$decoded = json_decode($object, true, 512, JSON_THROW_ON_ERROR);

			if (!is_array($decoded)) {
				throw new \UnexpectedValueException('Comment is not an object: ' . $object);
			}

			$comment = [];

			foreach ($decoded as $key => $value) {
				if (is_string($key) && is_string($value)) {
					$comment[$key] = $value;
				}
			}

			$comments[] = $comment;
		}

		return $comments;
	}
}
