# Suggested improvements (not implemented)

These came up while doing the security hardening and the PHP 8.4 port. I left
them out because they change behavior, need a product decision, or are larger
refactors. They're ordered roughly by value within each section.

## Security & privacy

1. **Rate limiting / flood control.** Nothing stops a client from posting
   hundreds of comments, or from brute-forcing a comment's password through the
   edit and delete forms. bcrypt slows guessing down but doesn't stop it. A
   small per-IP counter (APCu, or a file in a non-public directory) with a
   per-minute limit would cover both.
2. **Like inflation.** A like is guarded only by a cookie, so clearing cookies
   gives unlimited likes. Options: store a salted hash of IP + comment, or
   require a "login" to like.
3. **Move data outside the web root.** Make `pages/` (and blocklist and ignore
   files) configurable so they can live outside the document root. Then
   `.htaccess` or nginx rules stop being the only line of defense.
4. **Separate HMAC secret from the email key**, and require at least 32 random
   bytes for new installs. Today one user-chosen key, possibly only 8 characters,
   protects both emails and login tokens. Add a `$secret_key` setting with an
   installer that generates it.
5. **Session-based admin with logout.** The admin token cookie is valid until
   the admin credentials change. A real logout action and a shorter admin
   cookie lifetime (e.g. 1 day) would be safer.
6. **CSRF tokens.** SameSite=Lax plus the Origin check is sufficient for modern
   browsers. A synchronizer token on edit and delete would also protect old
   browsers.
7. **Content-Security-Policy.** The markup relies on inline `onClick=` handlers
   and `document.write`, which blocks a strict CSP on host pages. Moving to
   `addEventListener` and DOM APIs would let site owners deploy one.
8. **Gravatar privacy.** Gravatar URLs leak an MD5 of each commenter's email,
   which can be reversed with dictionaries. Gravatar accepts SHA-256 now, so
   switch to it, or proxy and cache avatars locally, or make Gravatar opt-in.
9. **IP storage / GDPR.** When `$ip_addrs = 'yes'` there's no retention
   policy. Consider storing a keyed hash instead, or purging after N days.
   There's also no way for a user to request deletion of their data.
10. **Replace the hand-written HTML sanitizer.** The write-time "escape
    everything, then re-allow a tag list" approach is sound but brittle.
    `HTMLPurifier`, or the native `Dom\HTMLDocument` (PHP 8.4) with a strict
    allow-list, would be more robust and easier to audit.
11. **Spam.** stopforumspam alone is weak. Consider Akismet, a timing
    honeypot (reject forms submitted under ~2 seconds), and a moderation
    queue.

## Code quality / architecture

12. **Drop the global variables.** Everything communicates through `global`.
    A small `Config` value object plus `CommentRepository`, `Renderer`,
    `Auth` and `Mailer` classes with Composer PSR-4 autoloading would make the
    code testable.
13. **One renderer for both modes.** The PHP and JavaScript modes duplicate
    every form in two languages (`php-mode.php` / `javascript-mode.php`).
    Render the HTML once on the server, and have JS mode fetch it as JSON
    (`fetch()`) instead of `document.write`/`innerHTML` string building.
14. **Boolean settings.** `'yes'`/`'no'` strings should become real `bool`s,
    and `$rows`, `$icon_size` and `$popular` real `int`s. This needs a
    migration note for people who preserve `settings.php`.
15. **Storage format.** One XML file per comment, with numbering derived from
    directory listings, is slow for big threads and fragile. SQLite via PDO
    would give atomicity, indexes and easy moderation queries.
16. **Tests and CI.** Add PHPUnit tests for the sanitizer, crypto upgrade
    paths and authorization, PHPStan at level 8+ and PHP-CS-Fixer, plus a
    GitHub Actions matrix on PHP 8.1–8.4. The curl and browser checks from
    this audit would make a good first integration test.
17. **Error handling.** `exit()` inside an included file kills the embedding
    page in PHP mode. Throw exceptions and render a small error box instead.
18. **Dead features.** Identi.ca shut down in 2013. Twitter `@user` links point
    to x.com now. The `count_missing`, `hashover-php` special case and mobile
    user-agent sniffing could be replaced with CSS media queries.

## Frontend / UX

19. **Accessibility.** The markup uses layout tables, `<center>`, `align=`,
    `<a name>`, icon-only buttons with no accessible name (login, like, edit),
    and placeholders used as labels. It needs real `<label>`s, `<button>`s and
    ARIA live regions for like counts.
20. **Modern JavaScript.** Replace `XMLHttpRequest`, `document.write`, `var`
    and inline handlers. `document.write` also breaks the documented
    `async`/`defer` loading.
21. **Like feedback.** The UI updates optimistically and ignores the server
    response (403, 404, "Practice altruism!").
22. **Remember password-less editing.** Users who didn't set a password can
    never edit or delete. Consider emailing a one-time edit link to commenters
    who gave an email.
23. **Localization.** Several strings are hard-coded in English ("Be the first
    to comment!", "Comment(s)", "Like(s)", "N days ago", "Loading...").
24. **Time zones.** Dates are stored as `m/d/Y - g:ia` in the server time zone
    (hard-coded `America/Los_Angeles`). The RSS feed even appends "PST".
    Store ISO 8601 UTC timestamps and format them per locale.

## Project / maintenance

25. **Upstream status.** The README says this branch is unmaintained in favor
    of `hashover-next`. Decide whether this fork continues 1.0.x or migrates
    users. In any case, update the README notice and links.
26. **Packaging.** Ship `secrets.php` as `secrets.php.dist` (and
    `.gitignore` the real one) so upgrades can't overwrite secrets and
    secrets don't end up in git.
27. **Versioning.** Bump the version (e.g. 1.1.0) and tag the release, since
    upgrading needs actions (re-login, nginx rules, `like.php` is POST-only).
