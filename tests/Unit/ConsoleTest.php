<?php

declare(strict_types=1);

namespace HashOver\Tests\Unit;

use HashOver\Cli\Console;
use HashOver\Config;
use PHPUnit\Framework\TestCase;

final class ConsoleTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/hashover-console-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/config', 0o700, true);
        copy(dirname(__DIR__, 2) . '/config/config.example.php', $this->root . '/config/config.example.php');
    }

    protected function tearDown(): void
    {
        foreach (['config/config.php', 'config/config.example.php'] as $file) {
            @unlink($this->root . '/' . $file);
        }

        $files = glob($this->root . '/data/*');

        foreach ($files === false ? [] : $files as $file) {
            unlink($file);
        }

        @rmdir($this->root . '/data');
        rmdir($this->root . '/config');
        rmdir($this->root);
    }

    /**
     * @param list<string> $arguments
     * @return array{int, string}
     */
    private function console(array $arguments, string $input = ''): array
    {
        $in = fopen('php://memory', 'r+');
        $out = fopen('php://memory', 'r+');
        self::assertIsResource($in);
        self::assertIsResource($out);
        fwrite($in, $input);
        rewind($in);

        $status = new Console($this->root, $in, $out)->run($arguments);
        rewind($out);

        return [$status, (string) stream_get_contents($out)];
    }

    public function testSetupCreatesValidPrivateConfig(): void
    {
        [$status, $output] = $this->console(['setup'], "Example.com www.example.com\nAdmin\nlong admin password\nowner@example.com\n");

        self::assertSame(0, $status, $output);
        $file = $this->root . '/config/config.php';
        self::assertSame(0o600, fileperms($file) & 0o777);

        $values = require $file;
        self::assertIsArray($values);
        $config = Config::fromArray($values);
        self::assertSame(['example.com', 'www.example.com'], $config->allowedHosts);
        self::assertSame('Admin', $config->adminName);
        self::assertTrue(password_verify('long admin password', $config->adminPasswordHash));
        self::assertSame(64, strlen($config->secretKey));

        // Never overwrites
        self::assertSame(1, $this->console(['setup'])[0]);
    }

    public function testSetupRejectsShortPasswords(): void
    {
        self::assertSame(1, $this->console(['setup'], "example.com\nAdmin\nshort\n\n")[0]);
        self::assertFileDoesNotExist($this->root . '/config/config.php');
    }

    public function testHashPassword(): void
    {
        [$status, $output] = $this->console(['hash-password'], "secret\n");

        self::assertSame(0, $status);
        self::assertTrue(password_verify('secret', trim(substr($output, (int) strpos($output, '$')))));
    }

    public function testCheckReportsConfigErrors(): void
    {
        [$status, $output] = $this->console(['check']);

        self::assertSame(1, $status);
        self::assertStringContainsString('bin/hashover setup', $output);
    }
}
