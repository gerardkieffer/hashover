<?php

declare(strict_types=1);

namespace HashOver\View;

/**
 * Interface text from locales/<language>.php, falling back to English.
 *
 * Plural messages are arrays with "one" and "other" forms; "{name}"
 * placeholders are replaced with parameters (escaping happens in templates).
 */
final class Translator
{
    /** @var array<string, string|array{one?: string, other: string}> */
    private array $messages;

    /** @var array<string, string|array{one?: string, other: string}> */
    private array $fallback;

    public function __construct(
        public readonly string $language,
        string $directory = __DIR__ . '/../../locales',
    ) {
        $this->fallback = self::load($directory . '/en.php');
        $this->messages = $language === 'en' ? $this->fallback : self::load($directory . '/' . $language . '.php');
    }

    public function has(string $key): bool
    {
        return isset($this->fallback[$key]);
    }

    /**
     * @param array<string, string|int> $parameters
     */
    public function translate(string $key, array $parameters = []): string
    {
        $message = $this->messages[$key] ?? $this->fallback[$key] ?? $key;

        if (is_array($message)) {
            $message = $message['other'];
        }

        return $this->replace($message, $parameters);
    }

    /**
     * @param array<string, string|int> $parameters
     */
    public function plural(string $key, int $count, array $parameters = []): string
    {
        $message = $this->messages[$key] ?? $this->fallback[$key] ?? $key;

        if (is_array($message)) {
            $message = $this->pluralForm($count) === 'one' ? ($message['one'] ?? $message['other']) : $message['other'];
        }

        return $this->replace($message, ['count' => $count] + $parameters);
    }

    /** CLDR plural category for the languages HashOver ships */
    private function pluralForm(int $count): string
    {
        return match ($this->language) {
            'ja' => 'other',
            'fr' => $count === 0 || $count === 1 ? 'one' : 'other',
            default => $count === 1 ? 'one' : 'other',
        };
    }

    /**
     * @param array<string, string|int> $parameters
     */
    private function replace(string $message, array $parameters): string
    {
        $replacements = [];

        foreach ($parameters as $name => $value) {
            $replacements['{' . $name . '}'] = (string) $value;
        }

        return strtr($message, $replacements);
    }

    /**
     * @return array<string, string|array{one?: string, other: string}>
     */
    private static function load(string $file): array
    {
        $messages = is_file($file) ? require $file : [];

        if (!is_array($messages)) {
            throw new \UnexpectedValueException(sprintf('Locale file "%s" must return an array.', $file));
        }

        /** @var array<string, string|array{one?: string, other: string}> */
        return $messages;
    }
}
