<?php

declare(strict_types=1);

namespace HashOver\Tests\Unit;

use HashOver\Config;
use HashOver\View\Translator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TranslatorTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function languages(): iterable
    {
        foreach (Config::LANGUAGES as $language) {
            yield $language => [$language];
        }
    }

    #[DataProvider('languages')]
    public function testEveryLanguageHasTheSameMessagesAndPlaceholders(string $language): void
    {
        $directory = dirname(__DIR__, 2) . '/locales';
        $english = require $directory . '/en.php';
        $messages = require $directory . '/' . $language . '.php';
        self::assertIsArray($english);
        self::assertIsArray($messages);

        self::assertSame([], array_diff(array_keys($english), array_keys($messages)), 'Missing messages');
        self::assertSame([], array_diff(array_keys($messages), array_keys($english)), 'Unknown messages');

        foreach ($english as $key => $text) {
            self::assertSame(self::placeholders($text), self::placeholders($messages[$key]), $language . ': ' . $key);
        }
    }

    public function testPlurals(): void
    {
        self::assertSame('1 comment', new Translator('en')->plural('count.comments', 1));
        self::assertSame('0 comments', new Translator('en')->plural('count.comments', 0));
        self::assertSame('0 commentaire', new Translator('fr')->plural('count.comments', 0));
        self::assertSame('2 commentaires', new Translator('fr')->plural('count.comments', 2));
        self::assertSame('1 件のコメント', new Translator('ja')->plural('count.comments', 1));
    }

    public function testParametersAndFallback(): void
    {
        $translator = new Translator('en');

        self::assertSame('Reply to Alice', $translator->translate('form.heading_reply', ['name' => 'Alice']));
        self::assertSame('unknown.key', $translator->translate('unknown.key'));
        self::assertFalse($translator->has('unknown.key'));
    }

    /**
     * @return list<string>
     */
    private static function placeholders(mixed $message): array
    {
        $text = is_array($message) ? implode(' ', array_filter($message, is_string(...))) : (is_string($message) ? $message : '');
        preg_match_all('/\{[a-z_]+\}/', $text, $matches);
        $placeholders = array_values(array_unique($matches[0]));
        sort($placeholders);

        return $placeholders;
    }
}
