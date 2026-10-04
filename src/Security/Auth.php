<?php

declare(strict_types=1);

namespace HashOver\Security;

use HashOver\Config;
use HashOver\Http\Cookie;
use HashOver\Http\Request;
use HashOver\Model\Comment;

/**
 * Login cookies for commenters and the administrator, and CSRF tokens.
 *
 * Commenters: the "hashover-login" cookie holds HMAC(name, password). Each
 * comment stores only a SHA-256 of that token, so the database never allows
 * a login by itself.
 *
 * Administrator: the "hashover-admin" cookie holds an expiry time and an HMAC
 * over it and the admin password hash; it expires after a day and becomes
 * invalid as soon as the admin password changes.
 */
final readonly class Auth
{
    public const string LOGIN_COOKIE = 'hashover-login';
    public const string ADMIN_COOKIE = 'hashover-admin';
    public const int LOGIN_LIFETIME = 60 * 60 * 24 * 30;
    public const int ADMIN_LIFETIME = 60 * 60 * 24;

    public function __construct(
        private Config $config,
        private Keys $keys,
    ) {}

    public function loginToken(string $name, string $password): string
    {
        return $this->keys->mac('login', mb_strtolower(trim($name)) . "\0" . $password);
    }

    /** One-way verifier of a login token, stored with the comment */
    public function loginVerifier(string $token): string
    {
        return hash('sha256', $token);
    }

    public function loginCookie(string $name, string $password, Request $request): Cookie
    {
        return new Cookie(self::LOGIN_COOKIE, $this->loginToken($name, $password), time() + self::LOGIN_LIFETIME, $this->secure($request));
    }

    public function isLoggedIn(Request $request): bool
    {
        return $request->cookie(self::LOGIN_COOKIE) !== '';
    }

    /** Whether the visitor's login cookie matches the comment's author */
    public function ownsComment(Request $request, Comment $comment): bool
    {
        $token = $request->cookie(self::LOGIN_COOKIE);

        return $token !== '' && $comment->loginVerifier !== null
            && hash_equals($comment->loginVerifier, $this->loginVerifier($token));
    }

    public function isAdminName(string $name): bool
    {
        return mb_strtolower(trim($name)) === mb_strtolower($this->config->adminName);
    }

    public function isAdminPassword(string $password): bool
    {
        return $password !== '' && password_verify($password, $this->config->adminPasswordHash);
    }

    public function adminCookie(Request $request): Cookie
    {
        $expires = time() + self::ADMIN_LIFETIME;

        return new Cookie(self::ADMIN_COOKIE, $expires . '.' . $this->adminSignature($expires), $expires, $this->secure($request));
    }

    public function isAdmin(Request $request): bool
    {
        $parts = explode('.', $request->cookie(self::ADMIN_COOKIE), 2);

        if (count($parts) !== 2 || !ctype_digit($parts[0]) || (int) $parts[0] < time()) {
            return false;
        }

        return hash_equals($this->adminSignature((int) $parts[0]), $parts[1]);
    }

    /**
     * @return list<Cookie>
     */
    public function logoutCookies(Request $request): array
    {
        return [
            Cookie::delete(self::LOGIN_COOKIE, $this->secure($request)),
            Cookie::delete(self::ADMIN_COOKIE, $this->secure($request)),
        ];
    }

    /**
     * CSRF token bound to the visitor's login cookies: a cross-site attacker
     * can't compute it without knowing those cookies.
     */
    public function csrfToken(Request $request): string
    {
        return $this->keys->mac('csrf', $request->cookie(self::LOGIN_COOKIE) . "\0" . $request->cookie(self::ADMIN_COOKIE));
    }

    public function isValidCsrfToken(Request $request): bool
    {
        return hash_equals($this->csrfToken($request), $request->post('csrf'));
    }

    public function secure(Request $request): bool
    {
        return $this->config->forceSecureCookies || $request->isHttps();
    }

    private function adminSignature(int $expires): string
    {
        return $this->keys->mac('admin', $expires . "\0" . $this->config->adminName . "\0" . $this->config->adminPasswordHash);
    }
}
