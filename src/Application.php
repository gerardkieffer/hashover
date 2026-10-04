<?php

declare(strict_types=1);

namespace HashOver;

use HashOver\Content\Formatter;
use HashOver\Content\Website;
use HashOver\Exception\UserError;
use HashOver\Http\Client;
use HashOver\Http\Cookie;
use HashOver\Http\Request;
use HashOver\Http\Response;
use HashOver\Http\StreamClient;
use HashOver\Mail\Mailer;
use HashOver\Mail\Notifier;
use HashOver\Mail\PhpMailer;
use HashOver\Model\Comment;
use HashOver\Page\Page;
use HashOver\Security\Akismet;
use HashOver\Security\Auth;
use HashOver\Security\EmailCipher;
use HashOver\Security\FormGuard;
use HashOver\Security\Keys;
use HashOver\Security\Turnstile;
use HashOver\Security\Visitor;
use HashOver\Storage\CommentRepository;
use HashOver\Storage\Database;
use HashOver\Storage\RateLimiter;
use HashOver\Storage\ThreadRepository;
use HashOver\View\CommentNode;
use HashOver\View\DateFormatter;
use HashOver\View\Renderer;
use HashOver\View\Sort;
use HashOver\View\ThreadState;
use HashOver\View\ThreadView;
use HashOver\View\Translator;

/**
 * HashOver's request handler: renders threads and processes form submissions.
 *
 * GET  ?action=thread|form|count|rss
 * POST action=comment|edit|delete|like|login|logout|verify
 *
 * Either may carry "language" to answer in another of Config::LANGUAGES than
 * the configured one, e.g. for the translations of a multilingual page.
 */
final readonly class Application
{
    public const string AUTHOR_COOKIE = 'hashover-author';

    private ThreadRepository $threads;
    private CommentRepository $comments;
    private RateLimiter $rateLimiter;
    private Auth $auth;
    private EmailCipher $cipher;
    private FormGuard $formGuard;
    private Visitor $visitor;
    private Turnstile $turnstile;
    private Akismet $akismet;
    private Translator $translator;
    private Renderer $renderer;
    private Notifier $notifier;

    /**
     * @param string|null $language interface language; defaults to the configured one
     * @param Client|null $http for Akismet and Turnstile
     */
    public function __construct(
        private Config $config,
        private Database $database,
        private ?Mailer $mailer = null,
        ?string $language = null,
        private ?Client $http = null,
    ) {
        $keys = new Keys($config);
        $this->threads = new ThreadRepository($database);
        $this->comments = new CommentRepository($database);
        $this->rateLimiter = new RateLimiter($database, $config);
        $this->auth = new Auth($config, $keys);
        $this->cipher = new EmailCipher($keys);
        $this->formGuard = new FormGuard($config, $keys);
        $this->visitor = new Visitor($config, $keys);
        $this->turnstile = new Turnstile($config, $keys, $this->visitor, $http ?? new StreamClient());
        $this->akismet = new Akismet($config, $http ?? new StreamClient());
        $this->translator = new Translator($language ?? $config->language);
        $this->renderer = new Renderer($config, $this->translator, new DateFormatter($this->translator, $config->timezone, $config->relativeDates), new Formatter(), $this->cipher);
        // The site owner is always written to in the configured language
        $ownerTranslator = $this->translator->language === $config->language ? $this->translator : new Translator($config->language);
        $this->notifier = new Notifier($config, $mailer ?? new PhpMailer($config->senderEmail), $this->cipher, $this->translator, $ownerTranslator);
    }

    public static function fromConfigFile(string $file): self
    {
        $config = Config::fromFile($file);

        return new self($config, new Database($config->databaseFile()));
    }

    public function config(): Config
    {
        return $this->config;
    }

    /**
     * The same application speaking another language; unknown languages are
     * ignored rather than refused, so a stale page never loses its comments
     */
    public function withLanguage(string $language): self
    {
        if ($language === $this->translator->language || !in_array($language, Config::LANGUAGES, true)) {
            return $this;
        }

        return new self($this->config, $this->database, $this->mailer, $language, $this->http);
    }

    public function handle(Request $request): Response
    {
        $language = $request->isPost() ? $request->post('language') : $request->query('language');

        if ($language !== '' && $language !== $this->translator->language) {
            $localized = $this->withLanguage($language);

            if ($localized !== $this) {
                return $localized->handle($request);
            }
        }

        try {
            if ($request->isPost()) {
                return $this->handlePost($request);
            }

            return match ($request->query('action', 'thread')) {
                'thread' => $this->thread($request),
                'form' => $this->form($request),
                'count' => $this->count($request),
                'rss' => $this->rss($request),
                default => throw new UserError('error.invalid_request', 404),
            };
        } catch (UserError $error) {
            return $this->error($request, $error);
        }
    }

    /** HTML of a page's comment thread, as included in the page */
    public function renderThread(Request $request, Page $page, ThreadState $state): string
    {
        return $this->renderer->render('thread.html.twig', $this->context($request, $page, $state));
    }

    // GET actions

    private function thread(Request $request): Response
    {
        $page = Page::fromUrl($request->query('url'), $this->config);

        return Response::html($this->renderThread($request, $page, ThreadState::fromQuery($request, '')))
            ->withHeader('Vary', 'Cookie');
    }

    /** Reply or edit form for a comment, loaded by hashover.js */
    private function form(Request $request): Response
    {
        $page = Page::fromUrl($request->query('url'), $this->config);
        $context = $this->context($request, $page, ThreadState::fromQuery($request, ''));

        if ($context['reply_to'] === null && $context['editing'] === null && $context['deleting'] === null) {
            throw new UserError('error.not_found', 404);
        }

        return Response::html($this->renderer->render('form_fragment.html.twig', $context));
    }

    private function count(Request $request): Response
    {
        $page = Page::fromUrl($request->query('url'), $this->config);
        $thread = $this->threads->find($page);
        $count = $thread === null ? ['comments' => 0, 'replies' => 0] : $this->comments->count($thread->id);

        return Response::json($count + ['text' => $this->countText($count['comments'], $count['replies'])]);
    }

    private function rss(Request $request): Response
    {
        $page = Page::fromUrl($request->query('url'), $this->config);
        $thread = $this->threads->find($page);
        $comments = $thread === null ? [] : array_filter($this->comments->forThread($thread->id), static fn(Comment $comment): bool => !$comment->deleted);

        $body = $this->renderer->render('rss.xml.twig', [
            'page' => $page,
            'thread' => $thread,
            'comments' => array_slice(array_reverse($comments), 0, 50),
            'self_url' => ($request->isHttps() ? 'https' : 'http') . '://' . $request->server('HTTP_HOST') . $this->config->baseUrl . 'index.php?action=rss&url=' . rawurlencode($page->url) . $this->languageParameter(),
        ]);

        return new Response($body, 200, 'application/rss+xml; charset=UTF-8')
            ->withHeader('Content-Security-Policy', "default-src 'none'; sandbox");
    }

    // POST actions

    private function handlePost(Request $request): Response
    {
        if (!$this->isSameSite($request)) {
            throw new UserError('error.cross_site', 403);
        }

        if ($this->visitor->isBlocked($request)) {
            throw new UserError('error.blocked', 403);
        }

        $page = Page::fromUrl($request->post('url'), $this->config);

        try {
            if (!$this->auth->isValidCsrfToken($request)) {
                throw new UserError('error.form_expired', 400);
            }

            return match ($request->post('action')) {
                'comment' => $this->postComment($request, $page),
                'edit' => $this->editComment($request, $page),
                'delete' => $this->deleteComment($request, $page),
                'like' => $this->like($request, $page),
                'login' => $this->login($request, $page),
                'logout' => $this->logout($request, $page),
                'verify' => $this->verify($request, $page),
                default => throw new UserError('error.invalid_request', 400),
            };
        } catch (UserError $error) {
            return $this->error($request, $error, $page);
        }
    }

    private function postComment(Request $request, Page $page): Response
    {
        $this->turnstile->requirePass($request);
        $visitor = $this->visitor->id($request);
        $this->rateLimiter->hit('comment', $visitor);
        $this->formGuard->check($request);

        $name = $this->singleLine($request->post('name'), $this->config->maxNameLength);
        $password = $request->post('password');
        $email = $this->email($request->post('email'));
        $website = $this->website($request->post('website'));
        $body = $this->body($request->post('body'));
        $actingAdmin = $this->auth->isAdmin($request);

        if ($this->auth->isAdminName($name) && !$actingAdmin) {
            // Count password attempts before checking them
            if ($password !== '') {
                $this->rateLimiter->hit('auth', $visitor);
                $actingAdmin = $this->auth->isAdminPassword($password);
            }

            if (!$actingAdmin) {
                throw new UserError('error.reserved_name', 403, 'name');
            }
        }

        // Validate everything before the thread is created by the first comment
        $parent = $this->parent($request->post('parent'), $page);

        if ($this->visitor->isKnownSpammer($request)) {
            throw new UserError('error.blocked', 403);
        }

        if (!$actingAdmin && $this->akismet->isSpam($request, $page, ['name' => $name, 'email' => $email, 'website' => $website, 'body' => $body, 'reply' => $parent !== null])) {
            throw new UserError('error.spam_filter', 403, 'body');
        }

        $thread = $this->threads->findOrCreate($page, $this->singleLine($request->post('title'), 200));

        $id = $this->comments->insert($thread->id, $parent?->id, [
            'name' => $name,
            'password_hash' => $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : null,
            'login_verifier' => $password !== '' ? $this->auth->loginVerifier($this->auth->loginToken($name, $password)) : null,
            'email' => $this->cipher->encrypt($email),
            'email_hash' => $this->cipher->fingerprint($email),
            'website' => $website,
            'body' => $body,
            'notify' => $request->hasPost('notify'),
            'ip_address' => $this->config->storeIpAddresses && $request->ip() !== '' ? $request->ip() : null,
        ]);

        if ($this->config->storeIpAddresses) {
            $this->comments->purgeIpAddresses($this->config->ipRetentionDays);
        }

        $comment = $this->comments->find($id) ?? throw new \LogicException('Comment was not saved.');
        $this->notifier->commentPosted($thread, $comment, $email, $parent);

        $cookies = [$this->authorCookie($request, $name, $email, $website)];

        if ($actingAdmin && !$this->auth->isAdmin($request)) {
            $cookies[] = $this->auth->adminCookie($request);
        } elseif ($password !== '' && !$actingAdmin) {
            $cookies[] = $this->auth->loginCookie($name, $password, $request);
        }

        return $this->success($request, $page, 'message.comment_posted', $comment->anchor(), $cookies);
    }

    private function editComment(Request $request, Page $page): Response
    {
        $this->turnstile->requirePass($request);
        $comment = $this->ownComment($request, $page);
        $name = $this->singleLine($request->post('name'), $this->config->maxNameLength);
        $actingAdmin = $this->auth->isAdmin($request);

        if ($this->auth->isAdminName($name) && !$actingAdmin) {
            throw new UserError('error.reserved_name', 403, 'name');
        }

        $fields = [
            'name' => $name,
            'website' => $this->website($request->post('website')),
            'body' => $this->body($request->post('body')),
            'notify' => $request->hasPost('notify'),
        ];

        // The administrator edits the text, never the author's e-mail address
        if (!$actingAdmin || $this->auth->ownsComment($request, $comment)) {
            $email = $this->email($request->post('email'));
            $fields['email'] = $this->cipher->encrypt($email);
            $fields['email_hash'] = $this->cipher->fingerprint($email);
        }

        // The administrator's edits aren't checked
        if (!$actingAdmin && $this->akismet->isSpam($request, $page, [
            'name' => $name,
            'email' => $this->email($request->post('email')),
            'website' => $fields['website'],
            'body' => $fields['body'],
            'reply' => $comment->isReply(),
        ])) {
            throw new UserError('error.spam_filter', 403, 'body');
        }

        $this->comments->update($comment->id, $fields);

        return $this->success($request, $page, 'message.comment_edited', $comment->anchor());
    }

    private function deleteComment(Request $request, Page $page): Response
    {
        $comment = $this->ownComment($request, $page);

        // Deleting can't be undone: ask for confirmation first
        if ($request->post('confirm') !== '1') {
            if ($request->wantsJson()) {
                throw new UserError('error.confirm_delete', 400);
            }

            return Response::redirect($page->urlWith(['hashover_delete' => $comment->id], 'hashover-form-' . $comment->id));
        }

        $this->comments->delete($comment->id);

        return $this->success($request, $page, 'message.comment_deleted', 'hashover');
    }

    private function like(Request $request, Page $page): Response
    {
        $this->turnstile->requirePass($request);
        $comment = $this->findComment($request->post('id'), $page);
        $authorEmail = $this->cipher->fingerprint($this->authorCookieValues($request)['email']);

        if ($this->auth->ownsComment($request, $comment) || $this->sameEmail($authorEmail, $comment)) {
            throw new UserError('error.own_comment', 403);
        }

        $visitor = $this->visitor->id($request);
        $this->rateLimiter->hit('like', $visitor);
        $liked = $this->comments->toggleLike($comment->id, $visitor);
        $likes = $this->comments->find($comment->id)->likes ?? 0;
        $message = $liked ? 'message.liked' : 'message.unliked';

        if ($request->wantsJson()) {
            return Response::json([
                'ok' => true,
                'liked' => $liked,
                'likes' => $likes,
                'likesText' => $this->translator->plural('comment.likes', $likes),
                'message' => $this->translator->translate($message),
            ]);
        }

        return Response::redirect($page->urlWith(['hashover_message' => $message], $comment->anchor()));
    }

    private function login(Request $request, Page $page): Response
    {
        $name = $this->singleLine($request->post('name'), $this->config->maxNameLength);
        $password = $request->post('password');

        if ($password === '') {
            throw new UserError('error.password_required', 400, 'password');
        }

        if ($this->auth->isAdminName($name)) {
            // Count password attempts before checking them
            $this->rateLimiter->hit('auth', $this->visitor->id($request));

            if (!$this->auth->isAdminPassword($password)) {
                throw new UserError('error.wrong_password', 403, 'password');
            }

            $cookie = $this->auth->adminCookie($request);
        } else {
            $cookie = $this->auth->loginCookie($name, $password, $request);
        }

        return $this->success($request, $page, 'message.logged_in', 'hashover', [$cookie, $this->authorCookie($request, $name, null, null)]);
    }

    private function logout(Request $request, Page $page): Response
    {
        return $this->success($request, $page, 'message.logged_out', 'hashover', $this->auth->logoutCookies($request));
    }

    /** Check a Turnstile token sent by hashover.js and hand out a pass */
    private function verify(Request $request, Page $page): Response
    {
        $this->rateLimiter->hit('verify', $this->visitor->id($request));
        $cookie = $this->turnstile->verify($request, $request->post('token'), $this->auth->secure($request));

        if (!$request->wantsJson()) {
            return Response::redirect($page->urlWith(['hashover_message' => 'message.verified'], 'hashover-form'))->withCookie($cookie);
        }

        return Response::json([
            'ok' => true,
            'message' => $this->translator->translate('message.verified'),
            'remaining' => $this->config->turnstilePassMinutes * 60,
        ])->withCookie($cookie);
    }

    // Helpers

    /**
     * Template variables for a thread
     *
     * @return array<string, mixed>
     */
    private function context(Request $request, Page $page, ThreadState $state): array
    {
        $thread = $this->threads->find($page);
        $comments = $thread === null ? [] : $this->comments->forThread($thread->id);
        $isAdmin = $this->auth->isAdmin($request);
        $liked = $thread === null ? [] : $this->comments->likedBy($thread->id, $this->visitor->id($request));
        $author = $this->authorCookieValues($request);
        $authorEmail = $this->cipher->fingerprint($author['email']);

        $makeNode = function (Comment $comment, ?Comment $parent) use ($request, $isAdmin, $liked, $authorEmail): CommentNode {
            $owns = $this->auth->ownsComment($request, $comment);
            $sameEmail = $this->sameEmail($authorEmail, $comment);

            return new CommentNode(
                comment: $comment,
                parent: $parent,
                liked: isset($liked[$comment->id]),
                editable: !$comment->deleted && ($isAdmin || $owns),
                likeable: !$comment->deleted && !$owns && !$sameEmail,
            );
        };

        $view = ThreadView::build($comments, $state->sort, $this->config->popularThreshold, $this->config->popularLimit, $makeNode);
        $byId = [];

        foreach ($comments as $comment) {
            $byId[$comment->id] = $comment;
        }

        $replyTo = $state->replyTo !== null && isset($byId[$state->replyTo]) && !$byId[$state->replyTo]->deleted ? $byId[$state->replyTo] : null;
        $editable = fn(?int $id): ?Comment => $id !== null && isset($byId[$id]) && !$byId[$id]->deleted
            && ($isAdmin || $this->auth->ownsComment($request, $byId[$id])) ? $byId[$id] : null;
        $editing = $editable($state->edit);
        $deleting = $editable($state->delete);

        return [
            'page' => $page,
            'thread' => $thread,
            'view' => $view,
            'sort' => $state->sort,
            'sorts' => Sort::cases(),
            // The page's own title first, so each translation of a shared
            // thread names itself; the title stored with the first comment
            // (used by e-mails and the feed) is only the fallback
            'title' => $state->title !== '' ? $state->title : ($thread->title ?? ''),
            'message' => $state->message !== null && $this->translator->has($state->message) ? $state->message : null,
            'error_field' => $state->errorField,
            'reply_to' => $replyTo,
            'editing' => $editing,
            'deleting' => $deleting,
            'editing_email' => $editing !== null && $this->auth->ownsComment($request, $editing) ? $this->cipher->decrypt($editing->email) : '',
            'is_admin' => $isAdmin,
            'is_logged_in' => $isAdmin || $this->auth->isLoggedIn($request),
            'author' => $author,
            'csrf' => $this->auth->csrfToken($request),
            'timestamp' => $this->formGuard->timestamp(),
            'endpoint' => $this->config->baseUrl . 'index.php',
            'rss_url' => $this->config->baseUrl . 'index.php?action=rss&url=' . rawurlencode($page->url) . $this->languageParameter(),
            'count_text' => $this->countText($view->commentCount, $view->replyCount),
            'honeypot' => FormGuard::HONEYPOT_FIELD,
            'turnstile' => $this->turnstile->isEnabled() ? [
                'site_key' => $this->config->turnstileSiteKey,
                'remaining' => $this->turnstile->remaining($request),
            ] : null,
        ];
    }

    /** "&language=…" for links to HashOver itself, when not in the configured language */
    private function languageParameter(): string
    {
        return $this->translator->language === $this->config->language ? '' : '&language=' . rawurlencode($this->translator->language);
    }

    private function countText(int $comments, int $replies): string
    {
        if ($replies === 0) {
            return $this->translator->plural('count.comments', $comments);
        }

        return $this->translator->plural('count.comments_and_replies', $comments, [
            'replies' => $this->translator->plural('count.replies', $replies),
        ]);
    }

    /**
     * @param list<Cookie> $cookies
     */
    private function success(Request $request, Page $page, string $message, string $focus, array $cookies = []): Response
    {
        $keyed = [];

        foreach ($cookies as $cookie) {
            $keyed[$cookie->name] = $cookie;
        }

        if ($request->wantsJson()) {
            // Render the thread as the visitor will see it with the new cookies
            $state = new ThreadState(Sort::fromQuery($request->post('sort')), title: $request->post('title'));

            $response = Response::json([
                'ok' => true,
                'message' => $this->translator->translate($message),
                'focus' => $focus,
                'html' => $this->renderThread($request->withCookies($keyed), $page, $state),
            ]);
        } else {
            $response = Response::redirect($page->urlWith(['hashover_message' => $message], $focus));
        }

        foreach ($keyed as $cookie) {
            $response->withCookie($cookie);
        }

        return $response;
    }

    private function error(Request $request, UserError $error, ?Page $page = null): Response
    {
        $text = $this->translator->translate($error->key, $error->parameters);

        if ($request->wantsJson()) {
            return Response::json(['ok' => false, 'message' => $text, 'field' => $error->field, 'error' => $error->key], $error->status);
        }

        // Without a known page to return to, show the message itself
        if ($page === null) {
            return Response::text($text, $error->status);
        }

        $parameters = ['hashover_message' => $error->key];
        $form = 'hashover-form';

        if ($error->field !== null) {
            $parameters['hashover_field'] = $error->field;
        }

        // Reopen the form that was submitted
        $action = $request->post('action');
        $id = $action === 'comment' ? $request->post('parent') : $request->post('id');

        if (ctype_digit($id) && in_array($action, ['comment', 'edit', 'delete'], true)) {
            $parameters[['comment' => 'hashover_reply', 'edit' => 'hashover_edit', 'delete' => 'hashover_delete'][$action]] = $id;
            $form .= '-' . $id;
        }

        return Response::redirect($page->urlWith($parameters, $form));
    }

    /** POST requests must come from a page of this website */
    private function isSameSite(Request $request): bool
    {
        $source = $request->header('Origin') !== '' ? $request->header('Origin') : $request->header('Referer');

        if ($source === '' || $source === 'null') {
            return $source === '';
        }

        return $this->config->allowsUrl($source);
    }

    /** Whether a comment was written with the e-mail address of the given fingerprint */
    private function sameEmail(?string $fingerprint, Comment $comment): bool
    {
        return $fingerprint !== null && $comment->emailHash !== null && hash_equals($comment->emailHash, $fingerprint);
    }

    /** A comment of this page the visitor may edit or delete */
    private function ownComment(Request $request, Page $page): Comment
    {
        $comment = $this->findComment($request->post('id'), $page);

        if ($this->auth->isAdmin($request) || $this->auth->ownsComment($request, $comment)) {
            return $comment;
        }

        $password = $request->post('password');

        if ($password !== '' && $comment->passwordHash !== null) {
            $this->rateLimiter->hit('auth', $this->visitor->id($request));

            if (password_verify($password, $comment->passwordHash)) {
                return $comment;
            }
        }

        throw new UserError($password === '' ? 'error.not_allowed' : 'error.wrong_password', 403, 'password');
    }

    private function findComment(string $id, Page $page): Comment
    {
        $thread = $this->threads->find($page);
        $comment = ctype_digit($id) ? $this->comments->find((int) $id) : null;

        if ($thread === null || $comment === null || $comment->threadId !== $thread->id || $comment->deleted) {
            throw new UserError('error.not_found', 404);
        }

        return $comment;
    }

    private function parent(string $id, Page $page): ?Comment
    {
        if ($id === '') {
            return null;
        }

        $thread = $this->threads->find($page);
        $parent = ctype_digit($id) ? $this->comments->find((int) $id) : null;

        if ($thread === null || $parent === null || $parent->threadId !== $thread->id || $parent->deleted) {
            throw new UserError('error.not_found', 404);
        }

        return $parent;
    }

    private function singleLine(string $value, int $maximumLength): string
    {
        $value = (string) preg_replace('/\s+/u', ' ', Formatter::normalize($value));

        return trim(mb_substr(trim($value), 0, $maximumLength));
    }

    private function email(string $value): string
    {
        $value = trim($value);

        if ($value !== '' && (filter_var($value, FILTER_VALIDATE_EMAIL) === false || strlen($value) > 254)) {
            throw new UserError('error.invalid_email', 400, 'email');
        }

        return $value;
    }

    private function website(string $value): string
    {
        return Website::normalize($value) ?? throw new UserError('error.invalid_website', 400, 'website');
    }

    private function body(string $value): string
    {
        $value = Formatter::normalize($value);

        if ($value === '') {
            throw new UserError('error.comment_required', 400, 'body');
        }

        if (mb_strlen($value) > $this->config->maxCommentLength) {
            throw new UserError('error.comment_too_long', 400, 'body', ['maximum' => $this->config->maxCommentLength]);
        }

        return $value;
    }

    private function authorCookie(Request $request, string $name, ?string $email, ?string $website): Cookie
    {
        $current = $this->authorCookieValues($request);
        $value = json_encode([
            'name' => $name,
            'email' => $email ?? $current['email'],
            'website' => $website ?? $current['website'],
        ], JSON_THROW_ON_ERROR);

        return new Cookie(self::AUTHOR_COOKIE, $value, time() + Auth::LOGIN_LIFETIME, $this->auth->secure($request));
    }

    /**
     * Name, e-mail and website remembered from the visitor's last comment
     *
     * @return array{name: string, email: string, website: string}
     */
    private function authorCookieValues(Request $request): array
    {
        $values = json_decode($request->cookie(self::AUTHOR_COOKIE), true);
        $values = is_array($values) ? $values : [];
        $string = static fn(string $key): string => is_string($values[$key] ?? null) ? $values[$key] : '';

        $email = $string('email');
        $website = $string('website');

        return [
            'name' => mb_substr($string('name'), 0, $this->config->maxNameLength),
            'email' => filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : '',
            'website' => Website::normalize($website) ?? '',
        ];
    }
}
