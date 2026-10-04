<?php

declare(strict_types=1);

namespace HashOver\Storage;

use HashOver\Model\Comment;
use HashOver\Model\Row;

final readonly class CommentRepository
{
    public function __construct(
        private Database $db,
    ) {}

    public function find(int $id): ?Comment
    {
        $row = $this->db->one('SELECT * FROM comments WHERE id = :id', ['id' => $id]);

        return $row === null ? null : Comment::fromRow($row);
    }

    /**
     * All comments of a thread, oldest first
     *
     * @return list<Comment>
     */
    public function forThread(int $threadId): array
    {
        return array_map(
            Comment::fromRow(...),
            $this->db->all('SELECT * FROM comments WHERE thread_id = :thread ORDER BY id', ['thread' => $threadId]),
        );
    }

    /**
     * @param array{name: string, password_hash: ?string, login_verifier: ?string, email: ?string, email_hash: ?string, website: string, body: string, notify: bool, ip_address: ?string} $fields
     */
    public function insert(int $threadId, ?int $parentId, array $fields): int
    {
        $this->db->execute(
            'INSERT INTO comments (thread_id, parent_id, name, password_hash, login_verifier, email, email_hash, website, body, notify, ip_address, created_at)
             VALUES (:thread, :parent, :name, :password_hash, :login_verifier, :email, :email_hash, :website, :body, :notify, :ip_address, :now)',
            ['thread' => $threadId, 'parent' => $parentId, 'now' => Clock::now(), 'notify' => (int) $fields['notify']] + $fields,
        );

        return $this->db->lastInsertId();
    }

    /**
     * @param array{name?: string, email?: ?string, email_hash?: ?string, website?: string, body?: string, notify?: bool} $fields
     */
    public function update(int $id, array $fields): void
    {
        $assignments = ['updated_at = :now'];
        $parameters = ['id' => $id, 'now' => Clock::now()];

        foreach ($fields as $column => $value) {
            $assignments[] = $column . ' = :' . $column;
            $parameters[$column] = is_bool($value) ? (int) $value : $value;
        }

        $this->db->execute('UPDATE comments SET ' . implode(', ', $assignments) . ' WHERE id = :id', $parameters);
    }

    /**
     * Delete a comment. A comment with replies is replaced by a "deleted"
     * placeholder so the replies stay in context; placeholders left without
     * replies are removed.
     */
    public function delete(int $id): void
    {
        $this->db->transaction(function () use ($id): void {
            $comment = $this->find($id);

            if ($comment === null) {
                return;
            }

            if ($this->hasReplies($id)) {
                $this->db->execute(
                    "UPDATE comments SET deleted = 1, name = '', password_hash = NULL, login_verifier = NULL, email = NULL, email_hash = NULL,
                     website = '', body = '', ip_address = NULL, notify = 0, updated_at = :now WHERE id = :id",
                    ['id' => $id, 'now' => Clock::now()],
                );

                return;
            }

            $this->db->execute('DELETE FROM comments WHERE id = :id', ['id' => $id]);

            // Remove placeholders that no longer have any replies
            $parentId = $comment->parentId;

            while ($parentId !== null) {
                $parent = $this->find($parentId);

                if ($parent === null || !$parent->deleted || $this->hasReplies($parentId)) {
                    break;
                }

                $this->db->execute('DELETE FROM comments WHERE id = :id', ['id' => $parentId]);
                $parentId = $parent->parentId;
            }
        });
    }

    public function hasReplies(int $id): bool
    {
        return $this->db->int('SELECT EXISTS (SELECT 1 FROM comments WHERE parent_id = :id)', ['id' => $id]) === 1;
    }

    /**
     * Number of comments and replies of a page, excluding deleted ones
     *
     * @return array{comments: int, replies: int}
     */
    public function count(int $threadId): array
    {
        $parameters = ['thread' => $threadId];

        return [
            'comments' => $this->db->int('SELECT COUNT(*) FROM comments WHERE thread_id = :thread AND deleted = 0 AND parent_id IS NULL', $parameters),
            'replies' => $this->db->int('SELECT COUNT(*) FROM comments WHERE thread_id = :thread AND deleted = 0 AND parent_id IS NOT NULL', $parameters),
        ];
    }

    /** Forget IP addresses older than the retention period */
    public function purgeIpAddresses(int $retentionDays): int
    {
        return $this->db->execute(
            'UPDATE comments SET ip_address = NULL WHERE ip_address IS NOT NULL AND created_at < :before',
            ['before' => Clock::ago($retentionDays * 86400)],
        )->rowCount();
    }

    /**
     * Toggle a visitor's like; returns whether the comment is now liked
     */
    public function toggleLike(int $commentId, string $voter): bool
    {
        return $this->db->transaction(function () use ($commentId, $voter): bool {
            $removed = $this->db->execute(
                'DELETE FROM likes WHERE comment_id = :comment AND voter = :voter',
                ['comment' => $commentId, 'voter' => $voter],
            )->rowCount() > 0;

            if (!$removed) {
                $this->db->execute('INSERT INTO likes (comment_id, voter) VALUES (:comment, :voter)', ['comment' => $commentId, 'voter' => $voter]);
            }

            $this->db->execute(
                'UPDATE comments SET likes = (SELECT COUNT(*) FROM likes WHERE comment_id = :comment) WHERE id = :comment',
                ['comment' => $commentId],
            );

            return !$removed;
        });
    }

    /**
     * Ids of the thread's comments liked by a visitor
     *
     * @return array<int, true>
     */
    public function likedBy(int $threadId, string $voter): array
    {
        $ids = $this->db->all(
            'SELECT likes.comment_id FROM likes JOIN comments ON comments.id = likes.comment_id
             WHERE comments.thread_id = :thread AND likes.voter = :voter',
            ['thread' => $threadId, 'voter' => $voter],
        );

        $liked = [];

        foreach ($ids as $row) {
            $liked[Row::int($row, 'comment_id')] = true;
        }

        return $liked;
    }
}
