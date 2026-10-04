HashOver 2
==========

**HashOver** is a self-hosted comment system for PHP websites, a privacy-friendly alternative to services like Disqus. Visitors can comment anonymously: only the comment itself is required. HashOver is free software under the [GNU Affero General Public License](LICENSE).

HashOver 2 is a complete rewrite of HashOver 1.0 (2014–2019), focused on security, accessibility and maintainability. It is not compatible with 1.x installations.

Features
---

- Threaded replies, likes, "most popular" comments, four sort orders
- Editing and deleting own comments, from any browser, by logging in with the same name and password
- An administrator who can edit and delete every comment
- Limited formatting (`<b>`, `<i>`, `<code>`, lists, quotes…), automatic links, images shown on request
- E-mail notifications for the site owner and for replies
- RSS feed per page, comment counts for links
- English, French, German, Spanish and Japanese, chosen per page if needed
- Works without JavaScript; with JavaScript, everything happens without reloading the page
- Optional spam and bot protection with [Akismet](https://akismet.com/) and [Cloudflare Turnstile](https://www.cloudflare.com/application-services/products/turnstile/)

Security and privacy
---

- Only the `public` directory is served; code, configuration and data stay private
- All output escaped by default; comments are sanitized with the HTML5 parser and an allow-list
- Passwords hashed with `password_hash()`; e-mail addresses encrypted (XSalsa20-Poly1305)
- HttpOnly, SameSite cookies; CSRF tokens; same-origin checks; rate limiting; bot protection
- Compatible with a strict Content-Security-Policy (no inline scripts, styles or event handlers); Turnstile needs two extra entries, see [below](#content-security-policy)
- Gravatar, storing IP addresses, stopforumspam.com, Akismet and Turnstile are off by default; stored IP addresses are forgotten after a retention period

See [SECURITY.md](SECURITY.md) for the details and how to report a vulnerability.

Accessibility
---

HashOver targets WCAG 2.1 level AA (RAWeb 1.1 / EN 301 549): every field has a visible label, errors are linked to their field, status messages are announced to screen readers, every action works with the keyboard, focus moves predictably, and the layout reflows down to 320 pixels wide. Automated testing with axe-core reports no violations. Colours are derived from your page's text colour; keep enough contrast if you customize them.

Requirements
---

- PHP 8.4 or newer with the `dom`, `mbstring`, `pdo_sqlite` and `sodium` extensions (`intl` is optional, for localized dates)
- [Composer](https://getcomposer.org/), or a release archive that includes the `vendor` directory
- `mail()` configured on the server, if you want e-mail notifications

Installation
---

1. Put HashOver **outside** your website's document root, for example in `/srv/hashover`, and install the dependencies:

   ```
   composer install --no-dev --optimize-autoloader
   ```

2. Create the configuration, answering a few questions:

   ```
   bin/hashover setup
   bin/hashover check
   ```

   This writes `config/config.php` (readable only by you). Review it: every setting is explained there. Make sure the web server's PHP user can read it and can write to the `data` directory.

3. Serve the `public` directory as `/hashover/`:

   **Apache**

   ```
   Alias /hashover /srv/hashover/public
   <Directory /srv/hashover/public>
       Require all granted
   </Directory>
   ```

   **nginx** (with PHP-FPM)

   ```
   location /hashover/ {
       alias /srv/hashover/public/;
       index index.php;

       location ~ \.php$ {
           include fastcgi_params;
           fastcgi_param SCRIPT_FILENAME $request_filename;
           fastcgi_pass unix:/run/php/php8.4-fpm.sock;
       }
   }
   ```

   **Shared hosting:** if you can only upload into the document root, upload the whole `hashover` directory; the included `.htaccess` files deny access to everything except `public`, and set `'base_url' => '/hashover/public/'` in the configuration. Check that `https://example.com/hashover/config/config.example.php` returns an error.

   You may also keep the configuration elsewhere and point the `HASHOVER_CONFIG` environment variable to it.

Adding comments to a page
---

**With JavaScript**, on any page (static HTML too):

```html
<link rel="stylesheet" href="/hashover/hashover.css">

<div id="hashover"></div>
<script type="module" src="/hashover/hashover.js"></script>
```

HashOver uses the page's `<link rel="canonical">` (or its address) to find its comments. Set `data-hashover-url` on the `div` to choose another address. Query parameters listed in `ignored_query_parameters` (tracking parameters by default) don't create separate threads.

**With PHP**, the comments are part of the page and work without JavaScript; include the script as well to enhance them:

```php
<link rel="stylesheet" href="/hashover/hashover.css">

<?php require '/srv/hashover/embed.php'; echo HashOver\Embed::thread(title: 'Page title'); ?>
<script type="module" src="/hashover/hashover.js"></script>
```

`HashOver\Embed::thread()` accepts the canonical `url` of the page, its `title` and its `language`. The title is shown in the comment form's heading; the one sent with a thread's first comment is also stored and used in e-mails and the RSS feed. With JavaScript only, set `data-hashover-title` on the `div` (default: the document title).

**Multilingual pages:** the interface language is `language` from the configuration, unless the page asks for another one: `Embed::thread(language: 'de')` in PHP, or `data-hashover-language="de"` on the `div` with JavaScript. Translations of one article can share a single thread by passing the same `url` from each of them, each with its own `language`. E-mails to the site owner always use the configured language.

**Comment counts**, for example in a list of articles (with the script loaded on the page):

```html
<a href="/blog/post#hashover"><span data-hashover-count="/blog/post">Comments</span></a>
```

Spam and bot protection
---

Every form already has a hidden honeypot field and a signed timestamp, and every visitor is rate limited. Two optional services add more. Both are off by default, and both send visitors' data to a third party: mention them in your privacy policy.

Both are called from PHP, which needs `allow_url_fopen` and the `openssl` extension. Run `bin/hashover check` after setting them up: it asks each service whether it accepts your keys.

### Akismet: rejects spam

1. Get an API key at [akismet.com](https://akismet.com/). It is free for personal, non-commercial sites only; commercial sites need a paid plan.
2. Set `'akismet_key' => '…'` in `config/config.php`.

Akismet checks every new comment and every edit, except the administrator's. **Spam is rejected, not stored**: the visitor sees "Our spam filter flagged your comment, so it wasn't posted." With JavaScript, their text stays in the form; without it, it is lost, as with any other error. There is no moderation queue, so a false positive can't be approved later; the visitor has to rephrase.

If Akismet can't be reached or doesn't answer as expected (for example because the key is wrong), comments are **accepted** unchecked and the problem is written to PHP's error log.

Akismet receives the comment, the author's name, e-mail address and website, their IP address, browser (user agent) and referring page, and the page's address. Akismet is run by Automattic, in the United States.

### Cloudflare Turnstile: stops bots

1. In the [Cloudflare dashboard](https://dash.cloudflare.com/?to=/:account/turnstile), add a Turnstile widget. "Managed" mode is recommended: most visitors pass without doing anything. A Cloudflare account is needed, but your site doesn't have to use Cloudflare otherwise.
2. List **every host name that shows comments** in the widget's settings (e.g. `example.com` and `www.example.com`). HashOver also refuses tokens solved on other host names than `allowed_hosts`.
3. Set `turnstile_site_key` and `turnstile_secret_key` in `config/config.php`. Both must be set, or both empty.

How it works for visitors:

- The check is shown in the main comment form, above "Post comment". Until it is completed, the **Like**, **Reply**, **Post comment**, **Post reply** and **Save changes** buttons are grayed out, with a short note next to them pointing to the check. Clicking one of them moves to the check instead.
- Once the check succeeds, HashOver gives the visitor a "pass": a signed cookie (`hashover-human`), bound to their IP address, valid for `turnstile_pass_minutes` (60 by default). One check covers any number of comments and likes during that time.
- When the pass expires, the buttons are grayed out again and the check runs again, usually without the visitor doing anything.
- It applies to everyone, the administrator too. Reading comments, logging in, logging out and deleting don't need it.
- The server enforces it: posting, editing or liking without a valid pass is refused, whatever the browser shows.

Things to know:

- **JavaScript is required to post and like.** Turnstile only runs in the browser, and `hashover.js` loads it. Visitors without JavaScript can still read the comments; the form tells them that the check needs JavaScript. If you embed comments with PHP, include `hashover.js` too.
- **Turnstile fails closed:** if Cloudflare can't be reached, nobody can post or like until it can.
- Visitors' browsers load the widget from `challenges.cloudflare.com`, and your server sends their IP address to Cloudflare to check the result.
- The widget follows your page: light or dark depending on your text colour, in the language of the comments, and compact on narrow screens.
- **Testing:** Cloudflare's [test keys](https://developers.cloudflare.com/turnstile/troubleshooting/testing/) (site key `1x00000000000000000000AA`, secret key `1x0000000000000000000000000000000AA`) always pass and work on `localhost`. HashOver recognizes the test secret keys and then skips its host name check. Never use them on a live site; `bin/hashover check` reminds you.

### Content-Security-Policy

HashOver itself works with a strict policy. If your pages send a `Content-Security-Policy` header and Turnstile is enabled, allow Cloudflare's script and frame, in addition to what your site already allows:

```
script-src 'self' https://challenges.cloudflare.com;
frame-src https://challenges.cloudflare.com;
```

Without them, visitors see "The security check couldn't be loaded" and can't post or like. Content blockers that block `challenges.cloudflare.com` have the same effect, and visitors are told so. Akismet runs on the server and needs no change.

### Behind a proxy or CDN

If your site is behind a reverse proxy or a CDN (including Cloudflare's own proxy), configure the web server to pass the visitor's real address to PHP (`mod_remoteip`, nginx `real_ip`, with `CF-Connecting-IP` for Cloudflare). Otherwise all visitors share the proxy's address: Akismet judges everyone by it, Turnstile passes are bound to it, and rate limits are shared.

Customizing
---

- **Look:** the stylesheet uses CSS custom properties (`--hashover-accent`, `--hashover-danger`, …) on `.hashover-thread`; override them in your own stylesheet. Fonts and text colour are inherited from your page.
- **Markup:** copy files from `templates` to a directory of your own, edit them, and set `templates_directory`. Templates are [Twig](https://twig.symfony.com/) files and escape output automatically.
- **Language and dates:** `language` (de, en, es, fr or ja), `timezone` and `relative_dates` in the configuration.

Administration
---

Log in with the administrator name and password (the "Log in" button of the comment form) to edit or delete any comment. The administrator session lasts one day; "Log out" ends it. Nobody else can post with the administrator's name.

Command-line tools:

```
bin/hashover setup          # create the configuration
bin/hashover check          # validate the configuration and the database
bin/hashover hash-password  # hash a new administrator password
bin/hashover purge-ips      # forget IP addresses older than the retention period
```

Back up `data/hashover.sqlite` and `config/config.php`. Keep the `secret_key`: changing it logs everyone out and makes stored e-mail addresses unreadable.

Development
---

```
composer install
composer check      # code style, static analysis and all tests
composer test       # tests only
composer cs-fix     # fix code style
```

The test suite has unit tests, application tests that run requests in memory, and HTTP tests that run HashOver under PHP's built-in web server. Static analysis runs PHPStan at level 10 with strict rules; code follows PER Coding Style 2.0.

Project layout: `src` (PHP classes), `templates` (Twig), `locales` (translations), `public` (web root), `config`, `data` (SQLite database), `bin` (command-line tool), `tests`.

License
---

HashOver is free software: you can redistribute it and/or modify it under the terms of the GNU Affero General Public License, version 3 or later. If you modify HashOver on your website, offer its source code to your visitors; the "HashOver source code" link (`source_code_url`) does that.

Copyright © 2014–2019 Jacob Barkdull and contributors; HashOver 2 © 2026 its contributors.
