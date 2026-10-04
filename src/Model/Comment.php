<?php

declare(strict_types=1);

namespace HashOver\Model;

/** A stored comment */
final readonly class Comment
{
    public function __construct(
        public int $id,
        public int $threadId,
        public ?int $parentId,
        public string $name,
        public ?string $passwordHash,
        public ?string $loginVerifier,
        public ?string $email,
        public ?string $emailHash,
        public string $website,
        public string $body,
        public int $likes,
        public bool $notify,
        public bool $deleted,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $updatedAt,
    ) {}

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: Row::int($row, 'id'),
            threadId: Row::int($row, 'thread_id'),
            parentId: Row::nullableInt($row, 'parent_id'),
            name: Row::string($row, 'name'),
            passwordHash: Row::nullableString($row, 'password_hash'),
            loginVerifier: Row::nullableString($row, 'login_verifier'),
            email: Row::nullableString($row, 'email'),
            emailHash: Row::nullableString($row, 'email_hash'),
            website: Row::string($row, 'website'),
            body: Row::string($row, 'body'),
            likes: Row::int($row, 'likes'),
            notify: Row::int($row, 'notify') === 1,
            deleted: Row::int($row, 'deleted') === 1,
            createdAt: Row::date($row, 'created_at'),
            updatedAt: Row::nullableString($row, 'updated_at') === null ? null : Row::date($row, 'updated_at'),
        );
    }

    public function isReply(): bool
    {
        return $this->parentId !== null;
    }

    /** HTML id of the comment, also its permalink fragment */
    public function anchor(): string
    {
        return 'hashover-c' . $this->id;
    }
}
