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
- English, French, Spanish and Japanese
- Works without JavaScript; with JavaScript, everything happens without reloading the page

Security and privacy
---

- Only the `public` directory is served; code, configuration and data stay private
- All output escaped by default; comments are sanitized with the HTML5 parser and an allow-list
- Passwords hashed with `password_hash()`; e-mail addresses encrypted (XSalsa20-Poly1305)
- HttpOnly, SameSite cookies; CSRF tokens; same-origin checks; rate limiting; bot protection
- Compatible with a strict Content-Security-Policy (no inline scripts, styles or event handlers)
- Gravatar, storing IP addresses and stopforumspam.com are off by default; stored IP addresses are forgotten after a retention period

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

`HashOver\Embed::thread()` accepts the canonical `url` of the page and its `title` (used in e-mails and the RSS feed).

**Comment counts**, for example in a list of articles (with the script loaded on the page):

```html
<a href="/blog/post#hashover"><span data-hashover-count="/blog/post">Comments</span></a>
```

Customizing
---

- **Look:** the stylesheet uses CSS custom properties (`--hashover-accent`, `--hashover-danger`, …) on `.hashover-thread`; override them in your own stylesheet. Fonts and text colour are inherited from your page.
- **Markup:** copy files from `templates` to a directory of your own, edit them, and set `templates_directory`. Templates are [Twig](https://twig.symfony.com/) files and escape output automatically.
- **Language and dates:** `language`, `timezone` and `relative_dates` in the configuration.

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
