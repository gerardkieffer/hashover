<?php

declare(strict_types=1);

namespace HashOver\Cli;

use HashOver\Config;
use HashOver\Exception\ConfigException;
use HashOver\Storage\CommentRepository;
use HashOver\Storage\Database;

/**
 * Administration commands: bin/hashover <command>
 */
final readonly class Console
{
    /** @var resource */
    private mixed $input;

    /** @var resource */
    private mixed $output;

    /**
     * @param resource|null $input
     * @param resource|null $output
     */
    public function __construct(
        private string $root,
        mixed $input = null,
        mixed $output = null,
    ) {
        $this->input = $input ?? STDIN;
        $this->output = $output ?? STDOUT;
    }

    /**
     * @param list<string> $arguments
     */
    public function run(array $arguments): int
    {
        try {
            return match ($arguments[0] ?? 'help') {
                'setup' => $this->setup(),
                'hash-password' => $this->hashPassword(),
                'check' => $this->check(),
                'purge-ips' => $this->purgeIps(),
                default => $this->help(),
            };
        } catch (ConfigException $error) {
            $this->line('Configuration error: ' . $error->getMessage());

            return 1;
        }
    }

    private function help(): int
    {
        $this->line(<<<'TEXT'
            HashOver administration

            Usage: bin/hashover <command>

              setup          Create config/config.php
              hash-password  Hash a password for "admin_password_hash"
              check          Validate the configuration and the database
              purge-ips      Forget IP addresses older than "ip_retention_days"
            TEXT);

        return 0;
    }

    private function setup(): int
    {
        $file = $this->configFile();

        if (is_file($file)) {
            $this->line(sprintf('%s already exists; edit it or delete it first.', $file));

            return 1;
        }

        $hosts = $this->ask('Host names of your website, separated by spaces (e.g. "example.com www.example.com")');
        $adminName = $this->ask('Administrator name');
        $password = $this->askPassword('Administrator password (at least 12 characters)');

        if (mb_strlen($password) < 12) {
            $this->line('The password is too short.');

            return 1;
        }

        $email = trim($this->ask('E-mail address for new comment notifications (optional)'));

        $values = [
            'secret_key' => bin2hex(random_bytes(32)),
            'admin_name' => trim($adminName),
            'admin_password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'allowed_hosts' => self::words($hosts),
            'notification_email' => $email,
        ];

        // Fails with a clear message if a value is invalid
        Config::fromArray($values);

        $hostList = implode(', ', array_map(static fn(string $host): string => var_export($host, true), $values['allowed_hosts']));
        $config = strtr((string) file_get_contents($this->root . '/config/config.example.php'), [
            "'secret_key' => ''" => "'secret_key' => " . var_export($values['secret_key'], true),
            "'admin_name' => ''" => "'admin_name' => " . var_export($values['admin_name'], true),
            "'admin_password_hash' => ''" => "'admin_password_hash' => " . var_export($values['admin_password_hash'], true),
            "'allowed_hosts' => ['example.com', 'www.example.com']" => "'allowed_hosts' => [" . $hostList . ']',
            "'notification_email' => ''" => "'notification_email' => " . var_export($email, true),
        ]);

        $previous = umask(0o077);
        file_put_contents($file, $config);
        umask($previous);

        $this->line(sprintf('Created %s. Run "bin/hashover check" after reviewing it.', $file));

        return 0;
    }

    private function hashPassword(): int
    {
        $password = $this->askPassword('Password');

        if ($password === '') {
            return 1;
        }

        $this->line(password_hash($password, PASSWORD_DEFAULT));

        return 0;
    }

    private function check(): int
    {
        $config = Config::fromFile($this->configFile());
        $database = new Database($config->databaseFile());
        $count = $database->int('SELECT COUNT(*) FROM comments');

        $this->line(sprintf('Configuration is valid. The database has %d comment(s).', $count));

        $data = realpath($config->dataDirectory);
        $public = realpath($this->root . '/public');

        if ($data !== false && $public !== false && str_starts_with($data, $public)) {
            $this->line('Warning: the data directory is inside "public" and may be downloadable.');

            return 1;
        }

        return 0;
    }

    private function purgeIps(): int
    {
        $config = Config::fromFile($this->configFile());
        $purged = new CommentRepository(new Database($config->databaseFile()))->purgeIpAddresses($config->ipRetentionDays);

        $this->line(sprintf('Forgot %d IP address(es).', $purged));

        return 0;
    }

    /**
     * @return list<string>
     */
    private static function words(string $text): array
    {
        $words = preg_split('/[\s,]+/', strtolower(trim($text)));

        return $words === false ? [] : array_values(array_filter($words, static fn(string $word): bool => $word !== ''));
    }

    private function configFile(): string
    {
        return Config::defaultFile($this->root);
    }

    private function ask(string $question): string
    {
        fwrite($this->output, $question . ': ');

        return trim((string) fgets($this->input));
    }

    private function askPassword(string $question): string
    {
        $interactive = stream_isatty($this->input) && DIRECTORY_SEPARATOR === '/';

        if ($interactive) {
            shell_exec('stty -echo');
        }

        $answer = $this->ask($question);

        if ($interactive) {
            shell_exec('stty echo');
            fwrite($this->output, PHP_EOL);
        }

        return $answer;
    }

    private function line(string $text): void
    {
        fwrite($this->output, $text . PHP_EOL);
    }
}
