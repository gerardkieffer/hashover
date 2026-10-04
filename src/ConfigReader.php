<?php

declare(strict_types=1);

namespace HashOver;

use HashOver\Exception\ConfigException;

/**
 * Reads typed values from the configuration array, with clear errors.
 *
 * @internal
 */
final readonly class ConfigReader
{
    /**
     * @param array<mixed> $values
     */
    public function __construct(
        private array $values,
    ) {
        foreach (array_keys($values) as $key) {
            if (!is_string($key)) {
                throw new ConfigException('Configuration keys must be strings.');
            }
        }
    }

    public function string(string $key, ?string $default = null): string
    {
        $value = $this->values[$key] ?? $default;

        if (!is_string($value)) {
            throw new ConfigException(sprintf('"%s" must be a string.', $key));
        }

        return $value;
    }

    public function bool(string $key, bool $default): bool
    {
        $value = $this->values[$key] ?? $default;

        if (!is_bool($value)) {
            throw new ConfigException(sprintf('"%s" must be true or false.', $key));
        }

        return $value;
    }

    public function int(string $key, int $default, int $minimum, int $maximum): int
    {
        $value = $this->values[$key] ?? $default;

        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new ConfigException(sprintf('"%s" must be a whole number between %d and %d.', $key, $minimum, $maximum));
        }

        return $value;
    }

    /**
     * @return array<mixed>
     */
    public function array(string $key): array
    {
        $value = $this->values[$key] ?? [];

        if (!is_array($value)) {
            throw new ConfigException(sprintf('"%s" must be an array.', $key));
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    public function stringList(string $key): array
    {
        $list = [];

        foreach ($this->array($key) as $value) {
            if (!is_string($value) || trim($value) === '') {
                throw new ConfigException(sprintf('"%s" must be a list of non-empty strings.', $key));
            }

            $list[] = trim($value);
        }

        return $list;
    }
}
