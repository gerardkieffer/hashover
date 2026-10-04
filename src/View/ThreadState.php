<?php

declare(strict_types=1);

namespace HashOver\View;

use HashOver\Http\Request;

/**
 * What the visitor asked to see: sort order, an open reply or edit form,
 * and a message to display after a form submission without JavaScript.
 */
final readonly class ThreadState
{
    public function __construct(
        public Sort $sort = Sort::Threaded,
        public ?int $replyTo = null,
        public ?int $edit = null,
        public ?int $delete = null,
        public ?string $message = null,
        public ?string $errorField = null,
        public string $title = '',
    ) {}

    /**
     * Read the state from query parameters: "sort", "reply", "edit",
     * "delete", "message" and "field", prefixed with "hashover_" on the host page
     */
    public static function fromQuery(Request $request, string $prefix): self
    {
        $id = static function (string $value): ?int {
            return ctype_digit($value) && strlen($value) < 19 ? (int) $value : null;
        };

        $message = $request->query($prefix . 'message');
        $field = $request->query($prefix . 'field');

        return new self(
            sort: Sort::fromQuery($request->query($prefix . 'sort')),
            replyTo: $id($request->query($prefix . 'reply')),
            edit: $id($request->query($prefix . 'edit')),
            delete: $id($request->query($prefix . 'delete')),
            message: preg_match('/^(message|error)\.[a-z_]+$/D', $message) === 1 ? $message : null,
            errorField: in_array($field, ['name', 'password', 'email', 'website', 'body'], true) ? $field : null,
            title: mb_substr($request->query('title'), 0, 200),
        );
    }
}
