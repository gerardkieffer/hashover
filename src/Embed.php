<?php

declare(strict_types=1);

namespace HashOver;

use HashOver\Exception\UserError;
use HashOver\Http\Request;
use HashOver\Page\Page;
use HashOver\View\ThreadState;

/**
 * Server-side embedding: prints the comments of the current page, which then
 * work without JavaScript (hashover.js enhances them when loaded).
 *
 *     <?php require '/path/to/hashover/embed.php'; echo HashOver\Embed::thread(); ?>
 */
final class Embed
{
    private static ?Application $application = null;

    /**
     * @param string|null $url canonical URL of the page; defaults to the requested URL
     * @param string $title page title used in e-mails and the RSS feed
     * @param string|null $language interface language, one of Config::LANGUAGES;
     *     defaults to the configured one. Several translations of a page can
     *     share one thread by passing the same $url with different languages.
     */
    public static function thread(?string $url = null, string $title = '', ?string $language = null): string
    {
        try {
            $request = Request::fromGlobals();
            $application = $language !== null ? self::application()->withLanguage($language) : self::application();
            $page = Page::fromUrl($url ?? $request->url(), $application->config());
            $state = ThreadState::fromQuery($request, 'hashover_');
            $state = new ThreadState($state->sort, $state->replyTo, $state->edit, $state->delete, $state->message, $state->errorField, $title);
            $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE);
            $languageAttribute = $language !== null && in_array($language, Config::LANGUAGES, true)
                ? ' data-hashover-language="' . $escape($language) . '"'
                : '';

            return '<div id="hashover" class="hashover" data-hashover-url="' . $escape($page->url) . '"' . $languageAttribute . '>'
                . $application->renderThread($request, $page, $state)
                . '</div>';
        } catch (UserError $error) {
            error_log('HashOver: ' . $error->key . ' for ' . ($url ?? 'the current page'));

            return '';
        } catch (\Throwable $error) {
            // Never break the page that includes the comments
            error_log('HashOver: ' . $error);

            return '';
        }
    }

    public static function application(): Application
    {
        return self::$application ??= Application::fromConfigFile(Config::defaultFile());
    }
}
