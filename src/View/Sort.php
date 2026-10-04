<?php

declare(strict_types=1);

namespace HashOver\View;

/** How comments are listed */
enum Sort: string
{
    /** Conversation order, replies nested under their comment */
    case Threaded = 'threaded';
    /** Newest first, without nesting */
    case Newest = 'newest';
    /** By commenter name, without nesting */
    case Name = 'name';
    /** Most liked first, without nesting */
    case Likes = 'likes';

    public static function fromQuery(string $value): self
    {
        return self::tryFrom($value) ?? self::Threaded;
    }

    public function isNested(): bool
    {
        return $this === self::Threaded;
    }
}
