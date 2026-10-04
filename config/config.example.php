<?php

declare(strict_types=1);

/*
 * HashOver configuration.
 *
 * Copy this file to config/config.php, or let "bin/hashover setup" create it.
 * Never publish config.php: it contains the secret key.
 */

return [
    // Required ---------------------------------------------------------------

    // Random secret protecting logins and stored e-mail addresses (at least 32
    // characters). Changing it logs everyone out and makes stored e-mail
    // addresses unreadable. Generate one with:
    //   php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
    'secret_key' => '',

    // The administrator may edit and delete every comment, and nobody else may
    // post with this name. Generate the hash with "bin/hashover hash-password".
    'admin_name' => '',
    'admin_password_hash' => '',

    // Host names of the website, including a port other than 80/443
    'allowed_hosts' => ['example.com', 'www.example.com'],

    // Paths ------------------------------------------------------------------

    // URL path under which the "public" directory is served
    'base_url' => '/hashover/',

    // Where the SQLite database is stored; must not be reachable from the web
    'data_directory' => __DIR__ . '/../data',

    // A directory with your own copies of files from "templates", if any
    'templates_directory' => '',

    // E-mail -----------------------------------------------------------------

    // Receives a message for every new comment; empty to disable
    'notification_email' => '',

    // "From" address of notifications, e.g. "noreply@example.com"
    'sender_email' => '',

    // Let site owners and subscribers reply to commenters by e-mail
    'reply_to_commenter' => false,

    // Display ----------------------------------------------------------------

    'language' => 'en',              // de, en, es, fr or ja; pages may ask for another one
    'timezone' => 'UTC',             // for dates shown to visitors
    'default_name' => '',            // shown for comments without a name; empty: "Anonymous"
    'show_page_title' => true,       // "Post a comment on “Page title”"
    'relative_dates' => true,        // "3 days ago" instead of full dates
    'comment_rows' => 5,             // height of the comment field
    'popular_threshold' => 5,        // likes a comment needs to be "popular"
    'popular_limit' => 2,            // number of popular comments shown; 0 to disable
    'source_code_url' => 'https://github.com/gerardkieffer/hashover',

    // Limits -----------------------------------------------------------------

    'max_comment_length' => 20000,
    'max_name_length' => 30,
    'minimum_submit_seconds' => 3,   // forms sent faster than this are treated as spam

    // [maximum attempts, period in seconds] per visitor
    'rate_limits' => [
        'comment' => [5, 600],
        'auth' => [10, 900],         // wrong passwords
        'like' => [30, 60],
        'verify' => [10, 600],       // Turnstile checks
    ],

    // Spam and bot protection --------------------------------------------------
    //
    // Both services are off by default: they send visitors' data to third
    // parties, so mention them in your privacy policy. See README.md.

    // Akismet (akismet.com) checks new and edited comments and rejects spam.
    // Sends the comment, name, e-mail address, website, IP address and browser
    // details to Automattic. Free for personal, non-commercial sites only.
    // Empty to disable.
    'akismet_key' => '',

    // Cloudflare Turnstile (dash.cloudflare.com > Turnstile) asks visitors to
    // prove they are human before they post, edit or like. Needs hashover.js
    // (and therefore JavaScript) for every visitor, the administrator too.
    // Add every host name that shows comments to the widget in Cloudflare's
    // dashboard. Both keys empty to disable.
    'turnstile_site_key' => '',
    'turnstile_secret_key' => '',

    // How long one successful check lets a visitor post, edit and like
    // (1 to 1440 minutes); afterwards the check runs again
    'turnstile_pass_minutes' => 60,

    // Privacy ----------------------------------------------------------------

    // Show Gravatar images; sends a hash of commenters' e-mail addresses to Gravatar
    'gravatar' => false,

    // Store commenters' IP addresses (to find addresses to block), forgotten
    // after the retention period
    'store_ip_addresses' => false,
    'ip_retention_days' => 30,

    // Check commenters' IP addresses with stopforumspam.com (sends them there)
    'stop_forum_spam' => false,

    // IP addresses that may not comment or like
    'blocked_ips' => [],

    // Query parameters that don't change a page's content, such as tracking
    // parameters; pages differing only by them share one thread
    'ignored_query_parameters' => ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'fbclid', 'gclid', 'mc_cid', 'mc_eid'],

    // Send cookies over HTTPS only, even if PHP doesn't detect HTTPS (behind a proxy)
    'force_secure_cookies' => false,
];
