<?php

declare(strict_types=1);

namespace HashOver\Storage;

use PDO;

/**
 * SQLite connection with automatic schema migrations.
 */
final class Database
{
    /** Schema changes, applied in order; never edit an entry once released */
    private const array MIGRATIONS = [
        1 => <<<'SQL'
            CREATE TABLE threads (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                page_key TEXT NOT NULL UNIQUE,
                page_url TEXT NOT NULL,
                title TEXT NOT NULL DEFAULT '',
                created_at TEXT NOT NULL
            );

            CREATE TABLE comments (
                -- AUTOINCREMENT: ids of deleted comments are never reused, so permalinks stay valid
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                thread_id INTEGER NOT NULL REFERENCES threads (id) ON DELETE CASCADE,
                parent_id INTEGER REFERENCES comments (id) ON DELETE CASCADE,
                name TEXT NOT NULL,
                password_hash TEXT,
                login_verifier TEXT,
                email TEXT,
                email_hash TEXT,
                website TEXT NOT NULL DEFAULT '',
                body TEXT NOT NULL,
                likes INTEGER NOT NULL DEFAULT 0,
                notify INTEGER NOT NULL DEFAULT 1,
                ip_address TEXT,
                deleted INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL,
                updated_at TEXT
            );

            CREATE INDEX comments_thread ON comments (thread_id, id);
            CREATE INDEX comments_parent ON comments (parent_id);
            CREATE INDEX comments_ip ON comments (created_at) WHERE ip_address IS NOT NULL;

            CREATE TABLE likes (
                comment_id INTEGER NOT NULL REFERENCES comments (id) ON DELETE CASCADE,
                voter TEXT NOT NULL,
                PRIMARY KEY (comment_id, voter)
            ) WITHOUT ROWID;

            CREATE TABLE rate_limits (
                bucket TEXT PRIMARY KEY,
                window_start INTEGER NOT NULL,
                hits INTEGER NOT NULL
            ) WITHOUT ROWID;
            SQL,
    ];

    public readonly PDO $pdo;

    public function __construct(string $file)
    {
        $directory = dirname($file);

        if ($file !== ':memory:' && !is_dir($directory) && !mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Could not create the data directory "%s".', $directory));
        }

        $isNew = $file === ':memory:' || !is_file($file);

        $this->pdo = new PDO('sqlite:' . $file, options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);

        if ($isNew && $file !== ':memory:') {
            chmod($file, 0o600);
        }

        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec('PRAGMA busy_timeout = 5000');

        if ($file !== ':memory:') {
            $this->pdo->exec('PRAGMA journal_mode = WAL');
        }

        $this->migrate();
    }

    /**
     * Run a callback in a transaction (IMMEDIATE, so concurrent writers wait)
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $this->pdo->exec('BEGIN IMMEDIATE');

        try {
            $result = $callback();
            $this->pdo->exec('COMMIT');

            return $result;
        } catch (\Throwable $error) {
            $this->pdo->exec('ROLLBACK');

            throw $error;
        }
    }

    /**
     * @param array<string, scalar|null> $parameters
     */
    public function execute(string $sql, array $parameters = []): \PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return $statement;
    }

    /**
     * @param array<string, scalar|null> $parameters
     * @return list<array<string, mixed>>
     */
    public function all(string $sql, array $parameters = []): array
    {
        /** @var list<array<string, mixed>> */
        return $this->execute($sql, $parameters)->fetchAll();
    }

    /**
     * @param array<string, scalar|null> $parameters
     * @return array<string, mixed>|null
     */
    public function one(string $sql, array $parameters = []): ?array
    {
        $row = $this->execute($sql, $parameters)->fetch();

        /** @var array<string, mixed>|null */
        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, scalar|null> $parameters
     */
    public function value(string $sql, array $parameters = []): mixed
    {
        return $this->execute($sql, $parameters)->fetchColumn();
    }

    /**
     * @param array<string, scalar|null> $parameters
     */
    public function int(string $sql, array $parameters = []): int
    {
        $value = $this->value($sql, $parameters);

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/D', $value) === 1) {
            return (int) $value;
        }

        throw new \UnexpectedValueException('The query did not return an integer.');
    }

    public function lastInsertId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }

    private function migrate(): void
    {
        $version = $this->int('PRAGMA user_version');

        foreach (self::MIGRATIONS as $target => $sql) {
            if ($target <= $version) {
                continue;
            }

            $this->transaction(function () use ($sql, $target): void {
                $this->pdo->exec($sql);
                $this->pdo->exec('PRAGMA user_version = ' . $target);
            });
        }
    }
}
