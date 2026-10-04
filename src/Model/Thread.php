<?php

declare(strict_types=1);

namespace HashOver\Model;

/** The comment thread of one web page */
final readonly class Thread
{
    public function __construct(
        public int $id,
        public string $pageKey,
        public string $pageUrl,
        public string $title,
    ) {}

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: Row::int($row, 'id'),
            pageKey: Row::string($row, 'page_key'),
            pageUrl: Row::string($row, 'page_url'),
            title: Row::string($row, 'title'),
        );
    }
}
