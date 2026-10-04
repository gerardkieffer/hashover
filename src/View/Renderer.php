<?php

declare(strict_types=1);

namespace HashOver\View;

use HashOver\Config;
use HashOver\Content\Formatter;
use HashOver\Model\Comment;
use HashOver\Security\EmailCipher;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\Markup;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Renders Twig templates with automatic HTML escaping.
 *
 * Comment bodies are the only HTML inserted unescaped, and only after
 * passing through the Formatter.
 */
final readonly class Renderer
{
    private Environment $twig;

    public function __construct(
        private Config $config,
        private Translator $translator,
        private DateFormatter $dates,
        private Formatter $formatter,
        private EmailCipher $cipher,
    ) {
        $paths = [__DIR__ . '/../../templates'];

        if ($config->templatesDirectory !== null) {
            array_unshift($paths, $config->templatesDirectory);
        }

        $cache = rtrim($config->dataDirectory, '/') . '/cache/twig';

        $this->twig = new Environment(new FilesystemLoader($paths), [
            'autoescape' => 'html',
            'strict_variables' => true,
            'cache' => (is_dir($cache) || @mkdir($cache, 0o700, true)) && is_writable($cache) ? $cache : false,
            'auto_reload' => true,
        ]);

        $this->twig->addFunction(new TwigFunction('t', $this->translator->translate(...)));
        $this->twig->addFunction(new TwigFunction('tp', $this->translator->plural(...)));
        $this->twig->addFunction(new TwigFunction('avatar', $this->avatar(...)));
        $this->twig->addFunction(new TwigFunction('author', $this->author(...)));
        $this->twig->addFilter(new TwigFilter('comment_html', $this->commentHtml(...)));
        $this->twig->addFilter(new TwigFilter('comment_html_source', fn(Comment $comment): string => $this->formatter->toHtml($comment->body)));
        $this->twig->addFilter(new TwigFilter('date_display', $this->dates->display(...)));
        $this->twig->addFilter(new TwigFilter('date_full', $this->dates->absolute(...)));
        $this->twig->addFilter(new TwigFilter('date_iso', $this->dates->iso(...)));
        $this->twig->addGlobal('config', $config);
        $this->twig->addGlobal('language', $translator->language);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function render(string $template, array $context = []): string
    {
        return $this->twig->render($template, $context);
    }

    /** Sanitized HTML of a comment */
    private function commentHtml(Comment $comment): Markup
    {
        return new Markup($this->formatter->toHtml($comment->body), 'UTF-8');
    }

    /** Name shown for a comment's author */
    private function author(Comment $comment): string
    {
        if ($comment->name !== '') {
            return $comment->name;
        }

        return $this->config->defaultName !== '' ? $this->config->defaultName : $this->translator->translate('comment.anonymous');
    }

    private function avatar(Comment $comment): string
    {
        $email = $this->config->gravatar ? $this->cipher->decrypt($comment->email) : '';

        if ($email !== '') {
            return 'https://gravatar.com/avatar/' . hash('sha256', strtolower(trim($email))) . '?s=96&d=mp';
        }

        return $this->config->baseUrl . 'images/avatar.png';
    }
}
