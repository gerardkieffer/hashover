<?php

declare(strict_types=1);

namespace HashOver\Mail;

use HashOver\Config;
use HashOver\Model\Comment;
use HashOver\Model\Thread;
use HashOver\Security\EmailCipher;
use HashOver\View\Translator;

/**
 * E-mails about new comments: to the site owner, and to the author of the
 * comment that was replied to (when they subscribed).
 */
final readonly class Notifier
{
    public function __construct(
        private Config $config,
        private Mailer $mailer,
        private EmailCipher $cipher,
        private Translator $translator,
    ) {}

    public function commentPosted(Thread $thread, Comment $comment, string $authorEmail, ?Comment $parent): void
    {
        $permalink = $thread->pageUrl . '#' . $comment->anchor();
        $author = $comment->name !== '' ? $comment->name : $this->translator->translate('comment.anonymous');
        $page = $thread->title !== '' ? $thread->title : $thread->pageUrl;
        $replyTo = $this->config->replyToCommenter && $authorEmail !== '' ? $authorEmail : null;
        $parentEmail = $parent !== null && $parent->notify ? $this->cipher->decrypt($parent->email) : '';

        $body = $this->translator->translate('mail.body', [
            'author' => $author,
            'page' => $page,
            'comment' => $comment->body,
            'permalink' => $permalink,
        ]);

        if ($this->config->notificationEmail !== '' && !self::same($authorEmail, $this->config->notificationEmail)) {
            $this->mailer->send(
                $this->config->notificationEmail,
                $this->translator->translate('mail.subject_new', ['page' => $page]),
                $body,
                $replyTo,
            );
        }

        if ($parentEmail !== '' && !self::same($parentEmail, $authorEmail) && !self::same($parentEmail, $this->config->notificationEmail)) {
            $this->mailer->send(
                $parentEmail,
                $this->translator->translate('mail.subject_reply', ['page' => $page]),
                $body . "\n\n" . $this->translator->translate('mail.unsubscribe'),
                $replyTo,
            );
        }
    }

    private static function same(string $a, string $b): bool
    {
        return $a !== '' && strcasecmp(trim($a), trim($b)) === 0;
    }
}
