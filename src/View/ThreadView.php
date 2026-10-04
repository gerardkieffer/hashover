<?php

declare(strict_types=1);

namespace HashOver\View;

use HashOver\Model\Comment;

/**
 * Arranges a thread's comments for display.
 */
final readonly class ThreadView
{
    /**
     * @param list<CommentNode> $comments top-level nodes (Threaded) or all nodes (other sorts)
     * @param list<CommentNode> $popular
     */
    public function __construct(
        public array $comments,
        public array $popular,
        public int $commentCount,
        public int $replyCount,
    ) {}

    /**
     * @param list<Comment> $comments oldest first
     * @param \Closure(Comment, ?Comment): CommentNode $makeNode
     */
    public static function build(array $comments, Sort $sort, int $popularThreshold, int $popularLimit, \Closure $makeNode): self
    {
        $byId = [];

        foreach ($comments as $comment) {
            $byId[$comment->id] = $comment;
        }

        $nodes = [];

        foreach ($comments as $comment) {
            $nodes[$comment->id] = $makeNode($comment, $comment->parentId === null ? null : ($byId[$comment->parentId] ?? null));
        }

        $visible = array_values(array_filter($nodes, static fn(CommentNode $node): bool => !$node->comment->deleted));

        if ($sort->isNested()) {
            $list = [];

            foreach ($nodes as $node) {
                $parentId = $node->comment->parentId;

                if ($parentId !== null && isset($nodes[$parentId])) {
                    $nodes[$parentId]->replies[] = $node;
                } else {
                    $list[] = $node;
                }
            }
        } else {
            $list = $visible;
            usort($list, match ($sort) {
                Sort::Newest => static fn(CommentNode $a, CommentNode $b): int => $b->comment->id <=> $a->comment->id,
                Sort::Name => static fn(CommentNode $a, CommentNode $b): int => [mb_strtolower($a->comment->name), $a->comment->id] <=> [mb_strtolower($b->comment->name), $b->comment->id],
                Sort::Likes => static fn(CommentNode $a, CommentNode $b): int => [$b->comment->likes, $a->comment->id] <=> [$a->comment->likes, $b->comment->id],
                Sort::Threaded => static fn(CommentNode $a, CommentNode $b): int => 0,
            });
        }

        $popular = array_values(array_filter($visible, static fn(CommentNode $node): bool => $node->comment->likes >= $popularThreshold));
        usort($popular, static fn(CommentNode $a, CommentNode $b): int => [$b->comment->likes, $a->comment->id] <=> [$a->comment->likes, $b->comment->id]);

        $replyCount = count(array_filter($visible, static fn(CommentNode $node): bool => $node->comment->isReply()));

        return new self(
            comments: $list,
            popular: array_slice($popular, 0, $popularLimit),
            commentCount: count($visible) - $replyCount,
            replyCount: $replyCount,
        );
    }

    public function isEmpty(): bool
    {
        return $this->commentCount + $this->replyCount === 0;
    }
}
