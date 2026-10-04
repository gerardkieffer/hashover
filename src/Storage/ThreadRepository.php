<?php

declare(strict_types=1);

namespace HashOver\Storage;

use HashOver\Model\Thread;
use HashOver\Page\Page;

final readonly class ThreadRepository
{
    public function __construct(
        private Database $db,
    ) {}

    public function find(Page $page): ?Thread
    {
        $row = $this->db->one('SELECT * FROM threads WHERE page_key = :key', ['key' => $page->key]);

        return $row === null ? null : Thread::fromRow($row);
    }

    /** Get the thread of a page, creating it when the first comment is posted */
    public function findOrCreate(Page $page, string $title): Thread
    {
        $this->db->execute(
            'INSERT INTO threads (page_key, page_url, title, created_at) VALUES (:key, :url, :title, :now)
             ON CONFLICT (page_key) DO NOTHING',
            ['key' => $page->key, 'url' => $page->url, 'title' => mb_substr($title, 0, 200), 'now' => Clock::now()],
        );

        return $this->find($page) ?? throw new \LogicException('Thread was not created.');
    }
}
