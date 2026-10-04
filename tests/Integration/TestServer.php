<?php

declare(strict_types=1);

namespace HashOver\Tests\Integration;

use RuntimeException;

/**
 * Runs a copy of HashOver under PHP's built-in web server.
 *
 * The application is copied into a temporary document root as "/hashover/",
 * with test secrets, next to a few host pages that embed the comments.
 */
final class TestServer
{
	public const ADMIN_NAME = 'Boss';
	public const ADMIN_PASSWORD = 'adminpw-secret';
	public const ENCRYPTION_KEY = 'test-key-0123456789abcdef';
	public const NOTIFICATION_EMAIL = 'owner@example.test';

	private static ?self $instance = null;

	/** @var resource */
	private $process;

	private function __construct(
		public readonly string $root,
		public readonly int $port,
	) {
	}

	public static function get(): self
	{
		return self::$instance ??= self::start();
	}

	public function baseUrl(): string
	{
		return 'http://127.0.0.1:' . $this->port;
	}

	public function appDir(): string
	{
		return $this->root . '/hashover';
	}

	public function pagesDir(string $thread): string
	{
		return $this->appDir() . '/pages/' . $thread;
	}

	/** Remove every comment thread */
	public function resetPages(): void
	{
		foreach (glob($this->appDir() . '/pages/*', GLOB_ONLYDIR) ?: [] as $dir) {
			array_map('unlink', glob($dir . '/*') ?: []);
			rmdir($dir);
		}
	}

	/** Write a host page into the document root */
	public function writePage(string $name, string $content): void
	{
		file_put_contents($this->root . '/' . $name, $content);
	}

	private static function start(): self
	{
		$root = sys_get_temp_dir() . '/hashover-test-' . bin2hex(random_bytes(6));
		$source = dirname(__DIR__, 2);

		self::copyTree($source, $root . '/hashover');
		mkdir($root . '/hashover/pages', 0755, true);

		$secrets = $root . '/hashover/scripts/secrets.php';
		file_put_contents($secrets, strtr((string) file_get_contents($secrets), [
			"= '8CharKey';" => "= '" . self::ENCRYPTION_KEY . "';",
			"= 'example@example.com';" => "= '" . self::NOTIFICATION_EMAIL . "';",
			"= 'admin';" => "= '" . self::ADMIN_NAME . "';",
			"= 'passwd';" => "= '" . self::ADMIN_PASSWORD . "';",
		]));

		$embed_js = '<div id="hashover"></div><script src="/hashover/comments.php"></script>';
		file_put_contents($root . '/js.html', '<!doctype html><title>JS mode</title>' . $embed_js);
		file_put_contents($root . '/php.php', '<!doctype html><title>PHP mode</title><?php $mode = \'php\'; include __DIR__ . \'/hashover/comments.php\';');

		$port = self::freePort();
		$command = [
			PHP_BINARY,
			'-d', 'sendmail_path=/usr/bin/true',
			'-d', 'display_errors=stderr',
			'-d', 'error_reporting=-1',
			'-S', '127.0.0.1:' . $port,
			'-t', $root,
		];

		$process = proc_open($command, [
			0 => ['file', '/dev/null', 'r'],
			1 => ['file', $root . '/server.log', 'a'],
			2 => ['file', $root . '/server.log', 'a'],
		], $pipes);

		if (!is_resource($process)) {
			throw new RuntimeException('Could not start the PHP built-in server');
		}

		$server = new self($root, $port);
		$server->process = $process;

		for ($attempt = 0; $attempt < 100; $attempt++) {
			$socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);

			if ($socket !== false) {
				fclose($socket);
				register_shutdown_function([$server, 'stop']);

				return $server;
			}

			usleep(50_000);
		}

		throw new RuntimeException('The PHP built-in server did not start');
	}

	/** PHP warnings, notices and deprecations logged by the server */
	public function phpErrors(): string
	{
		$log = (string) @file_get_contents($this->root . '/server.log');

		$lines = array_filter(
			explode("\n", $log),
			static fn (string $line): bool => preg_match('/(Warning|Notice|Deprecated|Fatal error|Parse error):/', $line) === 1
		);

		return implode("\n", $lines);
	}

	public function stop(): void
	{
		if (is_resource($this->process)) {
			proc_terminate($this->process);
			proc_close($this->process);
		}

		self::removeTree($this->root);
	}

	private static function freePort(): int
	{
		$socket = stream_socket_server('tcp://127.0.0.1:0');

		if ($socket === false) {
			throw new RuntimeException('No free port');
		}

		$name = (string) stream_socket_get_name($socket, false);
		fclose($socket);

		return (int) substr($name, strrpos($name, ':') + 1);
	}

	private static function copyTree(string $from, string $to): void
	{
		$skip = ['.git', 'vendor', 'tests', 'pages', '.phpunit.cache', 'node_modules'];

		mkdir($to, 0755, true);

		foreach (scandir($from) ?: [] as $entry) {
			if ($entry === '.' || $entry === '..' || in_array($entry, $skip, true)) {
				continue;
			}

			$path = $from . '/' . $entry;

			if (is_dir($path)) {
				self::copyTree($path, $to . '/' . $entry);
			} else {
				copy($path, $to . '/' . $entry);
			}
		}
	}

	private static function removeTree(string $dir): void
	{
		if (!is_dir($dir)) {
			return;
		}

		foreach (scandir($dir) ?: [] as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}

			$path = $dir . '/' . $entry;
			is_dir($path) && !is_link($path) ? self::removeTree($path) : unlink($path);
		}

		rmdir($dir);
	}
}
