<?php

declare(strict_types=1);

namespace HashOver\Tests\Http;

use HashOver\Tests\Support\TestConfig;

/**
 * Serves HashOver with PHP's built-in web server: "public" as /hashover/,
 * next to host pages embedding the comments with JavaScript and with PHP.
 */
final class TestServer
{
    private static ?self $instance = null;

    /** @var resource */
    private $process;

    private function __construct(
        public readonly string $root,
        public readonly int $port,
    ) {}

    public static function get(): self
    {
        return self::$instance ??= self::start();
    }

    public function url(string $path = ''): string
    {
        return 'http://localhost:' . $this->port . $path;
    }

    /** PHP warnings, notices and errors logged by the server */
    public function phpErrors(): string
    {
        $log = (string) file_get_contents($this->root . '/server.log');
        $lines = array_filter(explode("\n", $log), static fn(string $line): bool => preg_match('/PHP (Warning|Notice|Deprecated|Fatal error|Parse error)|HashOver:/', $line) === 1);

        return implode("\n", $lines);
    }

    public function stop(): void
    {
        proc_terminate($this->process);
        proc_close($this->process);

        foreach (['data/hashover.sqlite', 'data/hashover.sqlite-wal', 'data/hashover.sqlite-shm', 'config.php', 'server.log', 'js.html', 'page.php', 'router.php'] as $file) {
            @unlink($this->root . '/' . $file);
        }

        @unlink($this->root . '/hashover');
        array_map(unlink(...), self::glob($this->root . '/data/cache/twig/*/*'));
        array_map(rmdir(...), self::glob($this->root . '/data/cache/twig/*'));
        @rmdir($this->root . '/data/cache/twig');
        @rmdir($this->root . '/data/cache');
        @rmdir($this->root . '/data');
        @rmdir($this->root);
    }

    private static function start(): self
    {
        $root = sys_get_temp_dir() . '/hashover-http-' . bin2hex(random_bytes(4));
        $repository = dirname(__DIR__, 2);
        $port = self::freePort();

        mkdir($root . '/data', 0o700, true);
        symlink($repository . '/public', $root . '/hashover');

        $config = ['allowed_hosts' => ['localhost:' . $port], 'data_directory' => $root . '/data', 'notification_email' => ''] + TestConfig::values();
        file_put_contents($root . '/config.php', '<?php return ' . var_export($config, true) . ';');

        file_put_contents($root . '/js.html', '<!DOCTYPE html><html lang="en"><title>JS page</title><link rel="stylesheet" href="/hashover/hashover.css"><main><div id="hashover"></div></main><script type="module" src="/hashover/hashover.js"></script>');
        file_put_contents($root . '/page.php', '<!DOCTYPE html><html lang="en"><title>PHP page</title><main><?php require ' . var_export($repository . '/embed.php', true) . '; echo HashOver\Embed::thread(title: "PHP page"); ?></main>');

        // Like a real server: serve files, run PHP scripts and index.php in directories
        file_put_contents($root . '/router.php', '<?php return false;');

        $process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=-1', '-d', 'log_errors=0', '-S', 'localhost:' . $port, '-t', $root],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $root . '/server.log', 'a'], 2 => ['file', $root . '/server.log', 'a']],
            $pipes,
            null,
            ['HASHOVER_CONFIG' => $root . '/config.php'] + getenv(),
        );

        if (!is_resource($process)) {
            throw new \RuntimeException('Could not start the PHP built-in server.');
        }

        $server = new self($root, $port);
        $server->process = $process;

        for ($attempt = 0; $attempt < 100; $attempt++) {
            $socket = @fsockopen('localhost', $port, $errno, $errstr, 0.1);

            if ($socket !== false) {
                fclose($socket);
                register_shutdown_function($server->stop(...));

                return $server;
            }

            usleep(50_000);
        }

        throw new \RuntimeException('The PHP built-in server did not start.');
    }

    /**
     * @return list<string>
     */
    private static function glob(string $pattern): array
    {
        $files = glob($pattern);

        return $files === false ? [] : $files;
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');

        if ($socket === false) {
            throw new \RuntimeException('No free port.');
        }

        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }
}
