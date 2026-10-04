<?php

declare(strict_types=1);

namespace HashOver\Exception;

/**
 * A problem caused by the visitor's request, shown to them as a message.
 *
 * The message is a translation key; $field names the form field at fault.
 */
final class UserError extends \RuntimeException
{
    /**
     * @param array<string, string|int> $parameters
     */
    public function __construct(
        public readonly string $key,
        public readonly int $status = 400,
        public readonly ?string $field = null,
        public readonly array $parameters = [],
    ) {
        parent::__construct($key, $status);
    }
}
