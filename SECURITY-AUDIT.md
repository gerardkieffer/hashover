# HashOver 1.0.3 — Security audit and PHP 8.4 port

> **Historical document.** This audit covers HashOver 1.0.x, which has since been replaced by the HashOver 2 rewrite. The current security design is described in [SECURITY.md](SECURITY.md).

Audit date: 2026-10-04. Base: `jacobwb/hashover` @ `19bdf11` (HashOver 1.0.3rc4).
Branch: `php84-security-hardening`.

This file lists every issue found, its impact, and what was changed. Severity
is a rough rating for a typical install (comments open to anonymous visitors).

## Critical

| # | Issue | Where (original) | Fix |
|---|---|---|---|
| C1 | **The code didn't run on PHP 8.** `like.php` used `$str{$i}` string offsets, which is a parse error. Likes were broken, and the endpoint printed a fatal error. | `scripts/like.php` | Rewrote it. It shares the crypto helpers now instead of keeping its own copy of `encrypt()`. |
| C2 | **Stored XSS via nickname.** Names went into `title="…"` attributes (the Reply tooltip) without escaping. Older PHP versions stored `"` unescaped, so a nickname like `"onmouseover=alert(1)//` ran script for every visitor. | `parse_comments.php` | All stored fields are HTML-escaped on output (`h()`). I tested this with a legacy file holding that exact payload. |
| C3 | **Stored XSS via website.** The only check was the unanchored regex `htt[p\|ps]://`, so `javascript:alert(1)//http://` passed and became the link on the commenter's name. | `write_comments.php`, `parse_comments.php` | Websites must be valid `http(s)` URLs (`safe_url()`), checked both when saving and when displaying. Legacy `javascript:` values are dropped. |
| C4 | **Comment data was publicly downloadable.** `pages/*/N.xml` is under the web root. It holds password hashes, emails, and IP addresses, and the email "encryption" was reversible (see H1). | install layout | Added `.htaccess` deny rules for `pages/`, for `scripts/` (except `like.php`), and for `blocklist.txt`, `ignore_queries.txt` and `template.xml`. The README has nginx rules. |
| C5 | **Path traversal.** `cmtfile`, `reply_to` and `like` were "sanitized" with `str_replace('../', '')`, which `....//` defeats. An admin could delete any `.xml` file on disk, and `like.php` could rewrite any XML file the web server can write. `reply_to` was only checked for "no letters", so `../..` passed. | `write_comments.php`, `like.php` | Strict whitelists: comment ids match `^\d+(-\d+)*$` and thread names `^[A-Za-z0-9%~@,;()-]+$`. |
| C6 | **Plaintext passwords in cookies.** The user's (and the admin's) password was stored in a non-HttpOnly `password` cookie and echoed back into the form's `value=""`. Admin rights were granted when the cookies `name == $admin_nickname` and `password == $admin_password`. | everywhere | Removed. Leftover `password` and `hashover-*` cookies from the old version are actively deleted. |

## High

| # | Issue | Fix |
|---|---|---|
| H1 | **Email "encryption" was a 5-bit XOR** with a repeating key. Known plaintext (`@`, `.com`) recovers the key immediately. | libsodium `crypto_secretbox` (authenticated encryption) with an HKDF-derived key, falling back to AES-256-GCM. Legacy values still decrypt and are re-encrypted when a comment is edited. |
| H2 | **Passwords stored as `md5(xor(password))`**: unsalted and fast. | `password_hash()` / `password_verify()`. Legacy MD5 hashes are verified once, then upgraded to bcrypt on the spot. |
| H3 | **Forgeable login cookies.** The "logged in" cookie was `ripemd160(name . stored_hash)`, computable by anyone who read a comment file, and valid forever. The admin cookie was likewise derived only from config values. | The login cookie is now `HMAC(server key, name ‖ password)`. Comment files store only `sha256(token)`, so a leaked file doesn't give a usable cookie. The admin token is an HMAC that changes when the admin credentials change. Comparisons use `hash_equals`. |
| H4 | **Reflected XSS in PHP mode** through `$_GET['pagetitle']`, `REQUEST_URI` (hidden `canon_url` input) and `hashover_reply`/`hashover_edit` values. | All escaped or validated. |
| H5 | **Reflected XSS in the RSS feed.** `?title=` and `PHP_SELF` were printed into an `application/xml` response unescaped. An XHTML-namespaced `<script>` runs in browsers. | Every value is escaped with `ENT_XML1`. The feed also gets `Content-Security-Policy: sandbox` and `nosniff`. |
| H6 | **Reflected XSS in `like.php`.** `File: "<user input>" non-existent!` was sent as `text/html`. | Fixed messages, `text/plain`, `nosniff`. |
| H7 | **DOM XSS.** `document.title` was concatenated into HTML in both modes, so a page title containing user content (forum topics, etc.) became script. The JS edit form copied the nickname's `innerHTML` into `value="…"`, so a `"` broke out. | `escapeHTML()` in JS, and `textContent` instead of `innerHTML`. Tested in a browser with a hostile title and a hostile nickname. |
| H8 | **Broken JS string escaping.** `jsAddSlashes()` only escaped `'` and then *un-escaped* `'+` / `+'` on purpose, so any value containing `'+…+'` became executable JS. Backslashes weren't escaped. | Rewritten. Literal parts go through `json_encode` (with `JSON_HEX_*`), and only `'+identifier+'` placeholders written by the code itself are kept as JS. Comment objects are emitted as JSON. |
| H9 | **CSRF.** Edit, delete and like used ambient cookies with no CSRF defense. Likes were plain GET requests. | Cookies are `SameSite=Lax`. Every POST checks `Origin`/`Referer` against the configured domain. Likes are POST-only. |
| H10 | **Admin impersonation.** Anyone could post as the admin nickname. | Only an authenticated admin may use the admin nickname, including when editing. |

## Medium

| # | Issue | Fix |
|---|---|---|
| M1 | **Open redirect.** After posting, the user was sent to the path from a POSTed `canon_url`/Referer, and `//evil.com` was accepted. | The redirect target is always a local path (`'/' . ltrim(path, '/\\')`). `canon_url` must be an http(s) URL on the configured domain. |
| M2 | **Referer host check** used `preg_match('/' . $domain . '/i', $host)`: unanchored, with unescaped dots, so `example.com.evil.net` passed. | Exact host comparison (`www.` ignored). |
| M3 | **Host header trusted.** `$domain = $_SERVER['HTTP_HOST']` ended up in emails, URLs and regexes. | Validated against a hostname grammar. The README recommends hardcoding `$domain`. |
| M4 | **Email spoofing.** Notification emails used `From: <commenter's address>`, which fails SPF/DMARC and lets people send mail "from" any address through the site. | `From:` is always `$noreply_email`, with the commenter in `Reply-To`. Addresses are validated with `FILTER_VALIDATE_EMAIL`. |
| M5 | **Race condition**: two simultaneous posts got the same number and one silently overwrote the other. Simultaneous likes lost updates. | New files are created with `fopen(…, 'x')` and retry with the next number. Likes use `flock`. Edits use `LOCK_EX`. |
| M6 | **Disk-filling DoS**: every page view with a new Referer or `canon_url` created a directory, and comments had no size limit. | Directories are created only when a comment is written. Comments are limited by `$max_comment` (20,000 characters). Very long thread names are truncated with a hash suffix. |
| M7 | **Cookies** had no `HttpOnly`, `Secure` or `SameSite`, and the cookie domain included the port. | `set_cookie()` helper with all flags. Port and IP hosts are handled. |
| M8 | **The URL regex was wrong**: `[…0-9-@…]` is the range `9`–`@`, so it also matched `<`, `=`, `>`. | Fixed the character class (`COMMENT_URL_PATTERN`). |
| M9 | **stopforumspam** was called over plain HTTP with no timeout, on every page view, sending every visitor's IP to a third party. | HTTPS, JSON API, 3-second timeout, and only on write requests. |
| M10 | **Host-page POSTs**: in PHP mode, any POST to the embedding page that had a `comment` field was handled as a HashOver submission. | Writes are handled only when `comments.php` is requested directly with POST. |

## Low / hygiene

- `secrets.php?source` printed a template of the secrets file. Removed.
- Removed the `PHP_SELF` echo into a JS comment (CRLF/JS injection via PATH_INFO).
- The template name passes through `basename()` before `file_get_contents`.
- `rel="noopener"` added to `target="_blank"` links. External links use HTTPS. Gravatar uses HTTPS, and the default-avatar URL is URL-encoded.
- `LIBXML_NONET` is set when parsing comment files, and libxml errors are suppressed properly instead of with `@`.
- Error messages no longer echo user input.

## PHP 8.4 migration

- Fatal: `$str{$i}` → removed (`like.php`).
- PHP 8 semantic changes: `0 != ''` is now `true`, which made every date show "0 years ago". Fixed with integer comparisons. Arithmetic on `SimpleXMLElement` and non-numeric strings (TypeError) now uses explicit casts.
- Deprecated since 8.1: `null` passed to non-nullable internal parameters (`htmlspecialchars(…, null, null, false)`).
- Undefined index/variable warnings fixed (`HTTP_USER_AGENT`, `$subfile_count[...]`, missing URL path, `parse_url` failures, missing locale).
- Modernized: `declare(strict_types=1)`, parameter and return types (`never` and union types included), `[]` arrays, `??`/`??=`, `match`, arrow functions, `str_contains`/`str_starts_with`, `microtime(true)`, `mb_scrub`, `&&`/`||` instead of `and`/`or`, strict comparisons, no closing `?>` tags.
- A real bug fixed along the way: PHP mode's "Most Popular" box showed the first N comments instead of the most liked ones.

## Compatibility notes

- Existing comment files work unchanged: legacy password hashes and emails are read and upgraded on edit.
- `$encryption_key` **must not change** on an existing install.
- Users need to log in again (old cookies are deleted). Passwords aren't pre-filled any more, but the login cookie lets users edit their own comments without retyping the password.
- `like.php` only accepts POST, so custom templates that call it with GET must be updated.
- Thread directory names keep only `A-Z a-z 0-9 % ~ @ , ; ( ) -`. A URL containing other unusual characters (e.g. `'`, `$`) maps to a new directory.

## How it was verified

PHP 8.4.21 built-in server with `error_reporting=-1`. No warnings, notices or deprecations were logged during the run.

- Both modes render. Tested: posting, replies, login, editing via token, editing via password, deleting, admin delete, likes and unlikes, OP self-like refusal, RSS, `count_link`.
- Attack checks: cross-site POST (403), wrong-password delete, password-less edit by a stranger, admin impersonation, traversal in `cmtfile`/`reply_to`/`like`, the RSS XHTML-script payload, `javascript:` URLs in `count_link` and `rss`, a lookalike Referer host, and a malformed Host header.
- A legacy comment file (MD5 password, XOR'd email, raw `"` in name, `javascript:` website) rendered safely, and its password was upgraded on edit.
- Browser (JS mode): a hostile `document.title` and a hostile nickname didn't execute. The edit form round-trips correctly, and the like XHR works with no console errors.
