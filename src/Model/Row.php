<?php

declare(strict_types=1);

namespace HashOver\Model;

/**
 * Typed access to database rows.
 *
 * @internal
 */
final class Row
{
    /**
     * @param array<string, mixed> $row
     */
    public static function int(array $row, string $column): int
    {
        $value = $row[$column] ?? null;

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/D', $value) === 1) {
            return (int) $value;
        }

        throw new \UnexpectedValueException(sprintf('Column "%s" is not an integer.', $column));
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function nullableInt(array $row, string $column): ?int
    {
        return ($row[$column] ?? null) === null ? null : self::int($row, $column);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function string(array $row, string $column): string
    {
        $value = $row[$column] ?? null;

        if (!is_string($value)) {
            throw new \UnexpectedValueException(sprintf('Column "%s" is not a string.', $column));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function nullableString(array $row, string $column): ?string
    {
        return ($row[$column] ?? null) === null ? null : self::string($row, $column);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function date(array $row, string $column): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::string($row, $column), new \DateTimeZone('UTC'));
    }
}
