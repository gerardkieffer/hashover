# Security

## Reporting a vulnerability

Please report vulnerabilities privately, through GitHub's "Report a vulnerability" button on the repository's Security tab, rather than in a public issue. Include the steps to reproduce the problem. You'll get an answer within a week.

## How HashOver 2 protects your site

### Deployment

- Only `public/` is meant to be served: a front controller, a stylesheet, a script and an image. Code, templates, configuration and the database live outside the document root, or behind `.htaccess` rules on shared hosting.
- `bin/hashover check` warns if the data directory is inside `public`.
- The SQLite database is created with mode `0600` in a `0700` directory; `bin/hashover setup` writes the configuration with mode `0600`.

### Secrets

- A single random `secret_key` (at least 32 characters, 64 hex digits by default) is expanded with HKDF-SHA256 into independent keys per purpose: e-mail encryption, login tokens, admin sessions, CSRF tokens, form timestamps, visitor hashes and Turnstile passes.
- The administrator password is only stored as a `password_hash()` hash.
- The Akismet key and the Turnstile secret key stay on the server; only the Turnstile site key, which is public by design, appears in pages.

### Commenters' data

- Passwords: `password_hash()` (bcrypt by default).
- E-mail addresses: encrypted with libsodium `crypto_secretbox` (XSalsa20-Poly1305, random nonce); never shown, only used for notifications and Gravatar (opt-in, SHA-256).
- IP addresses: not stored unless `store_ip_addresses` is enabled, then forgotten after `ip_retention_days`. Rate limiting, likes and Turnstile passes use a keyed hash of the address instead.
- Third parties, only when enabled: stopforumspam.com receives the IP address of commenters; Akismet receives each comment with its author's name, e-mail address, website, IP address, user agent and referrer (the administrator's comments aren't sent); Cloudflare receives the IP address of visitors who complete the Turnstile check, and its widget runs in their browser.

### Authentication

- Commenters: the login cookie holds HMAC(name, password). The database only stores SHA-256 of that token, so a leaked database gives no working cookie and no offline password check without the secret.
- Administrator: the cookie holds an expiry time and an HMAC over it, the admin name and the admin password hash. It expires after 24 hours and becomes invalid when the password changes. Logging out deletes it.
- Nobody but the authenticated administrator may use the administrator's name.
- Password attempts (administrator login, editing with a password) are rate limited before being checked.

### Requests

- Every state-changing request is a POST that must come from an allowed host (`Origin`, or `Referer`) and carry a CSRF token bound to the visitor's login cookies.
- Cookies are `HttpOnly`, `SameSite=Lax`, and `Secure` over HTTPS (or always, with `force_secure_cookies`).
- Pages are identified by URLs of the configured hosts only; threads are created when the first comment is posted, not when a page is viewed.
- Redirects only go to the page the comment belongs to.
- Rate limits per visitor: comments, password attempts and likes (`rate_limits`).
- Bots: a hidden honeypot field and an HMAC-signed form timestamp reject forms filled too fast or replayed after a week; stopforumspam.com can be enabled.
- Cloudflare Turnstile (opt-in): the token from the widget is sent once to Cloudflare's `siteverify` API with the visitor's IP address, and must come from HashOver's widget (`action`) on one of `allowed_hosts` (`hostname`); these two checks are skipped only for Cloudflare's documented test secret keys. A valid token earns a `hashover-human` cookie holding an expiry time and an HMAC over it and the visitor hash, so it can't be forged, extended or reused from another address. Posting, editing and liking require it on the server; the disabled buttons are only a hint. Token checks are rate limited (`rate_limits.verify`), and tokens longer than Cloudflare's 2048-character maximum aren't sent. If Cloudflare can't be reached, no pass is given (fails closed).
- Akismet (opt-in): new and edited comments flagged as spam are rejected before anything is stored or e-mailed. If Akismet can't be reached or gives an unexpected answer, the comment is accepted and the problem logged (fails open), so an outage or a wrong key never silences the site; the other protections still apply.

### Output

- Templates are rendered by Twig with automatic HTML escaping.
- Comments are stored as typed. On display, everything is escaped except attribute-less formatting tags; the result is re-parsed by PHP's HTML5 parser (which balances tags), URLs become `rel="nofollow ugc noopener noreferrer"` links, and a final allow-list pass removes any element or attribute that isn't expected. Only `http`/`https` links are created.
- Bidirectional override characters are removed from comments, so they can't disguise text.
- No inline scripts, styles or event handlers: host pages can use a strict Content-Security-Policy. With Turnstile, the policy must also allow `https://challenges.cloudflare.com` in `script-src` and `frame-src` (see README.md).
- Responses carry `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin` and `Cache-Control: no-store`; the RSS feed is sandboxed with CSP.

### Known limits

- Behind a reverse proxy, PHP sees the proxy's address: rate limits and likes then apply to all visitors together, Turnstile passes are bound to the proxy's address rather than the visitor's, and Akismet judges every comment by the proxy's address. Configure your web server to pass the real client address (e.g. `mod_remoteip`, nginx `real_ip`).
- A Turnstile pass is a bearer token for its lifetime: a person who solves the check can let a script post from the same address until it expires (rate limits still apply). Shorten `turnstile_pass_minutes` if that matters more to you than how often visitors see the check.
- Turnstile needs JavaScript and Cloudflare's availability; without them, visitors can read but not post or like.
- Comment passwords are as strong as commenters make them; rate limiting slows down guessing.

The audit of HashOver 1.0, which led to this rewrite, is kept in [SECURITY-AUDIT.md](SECURITY-AUDIT.md).
