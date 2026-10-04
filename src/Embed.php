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
     */
    public static function thread(?string $url = null, string $title = ''): string
    {
        try {
            $request = Request::fromGlobals();
            $page = Page::fromUrl($url ?? $request->url(), self::application()->config());
            $state = ThreadState::fromQuery($request, 'hashover_');
            $state = new ThreadState($state->sort, $state->replyTo, $state->edit, $state->delete, $state->message, $state->errorField, $title);

            return '<div id="hashover" class="hashover" data-hashover-url="' . htmlspecialchars($page->url, ENT_QUOTES | ENT_SUBSTITUTE) . '">'
                . self::application()->renderThread($request, $page, $state)
                . '</div>';
        } catch (UserError $error) {
            error_log('HashOver: ' . $error->key . ' for ' . ($url ?? 'the current page'));

            return '';
        }
    }

    public static function application(): Application
    {
        return self::$application ??= Application::fromConfigFile(Config::defaultFile());
    }
}
