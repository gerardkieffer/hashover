<?php

declare(strict_types=1);

namespace HashOver\View;

use HashOver\Model\Comment;

/** A comment with its replies, prepared for display */
final class CommentNode
{
    /** @var list<CommentNode> */
    public array $replies = [];

    public function __construct(
        public readonly Comment $comment,
        public readonly ?Comment $parent,
        public readonly bool $liked,
        public readonly bool $editable,
        public readonly bool $likeable,
    ) {}
}
