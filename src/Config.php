<?php

declare(strict_types=1);

namespace HashOver;

use HashOver\Exception\ConfigException;

/**
 * Validated application configuration.
 *
 * Created from the array returned by config/config.php; every value has a
 * sensible default except the secret key, the administrator credentials and
 * the allowed host names.
 */
final readonly class Config
{
    /**
     * @param non-empty-string $secretKey
     * @param list<string> $allowedHosts lower-cased "host" or "host:port"
     * @param list<string> $blockedIps
     * @param list<string> $ignoredQueryParameters
     * @param array<string, array{int, int}> $rateLimits bucket => [maximum hits, window in seconds]
     */
    public function __construct(
        public string $secretKey,
        public string $adminName,
        public string $adminPasswordHash,
        public array $allowedHosts,
        public string $baseUrl = '/hashover/',
        public string $dataDirectory = __DIR__ . '/../data',
        public string $notificationEmail = '',
        public string $senderEmail = '',
        public bool $replyToCommenter = false,
        public string $language = 'en',
        public string $timezone = 'UTC',
        public string $defaultName = '',
        public bool $showPageTitle = true,
        public bool $relativeDates = true,
        public bool $gravatar = false,
        public int $commentRows = 5,
        public int $popularThreshold = 5,
        public int $popularLimit = 2,
        public int $maxCommentLength = 20000,
        public int $maxNameLength = 30,
        public bool $storeIpAddresses = false,
        public int $ipRetentionDays = 30,
        public array $blockedIps = [],
        public array $ignoredQueryParameters = [],
        public bool $stopForumSpam = false,
        public int $minimumSubmitSeconds = 3,
        public array $rateLimits = [],
        public ?string $templatesDirectory = null,
        public string $sourceCodeUrl = 'https://github.com/gerardkieffer/hashover',
        public bool $forceSecureCookies = false,
        public string $akismetKey = '',
        public string $turnstileSiteKey = '',
        public string $turnstileSecretKey = '',
        public int $turnstilePassMinutes = 60,
    ) {}

    /** Default rate limits: [maximum hits, window in seconds] */
    public const array DEFAULT_RATE_LIMITS = [
        'comment' => [5, 600],
        'auth' => [10, 900],
        'like' => [30, 60],
        'verify' => [10, 600],
    ];

    public const array LANGUAGES = ['de', 'en', 'es', 'fr', 'ja'];

    /** Tracking parameters that never change a page's content */
    public const array DEFAULT_IGNORED_QUERY_PARAMETERS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'fbclid', 'gclid', 'mc_cid', 'mc_eid'];

    /**
     * Path of the configuration file: $HASHOVER_CONFIG, or config/config.php
     */
    public static function defaultFile(?string $root = null): string
    {
        $file = getenv('HASHOVER_CONFIG');

        return is_string($file) && $file !== '' ? $file : ($root ?? dirname(__DIR__)) . '/config/config.php';
    }

    /**
     * Load config/config.php (or the given file)
     */
    public static function fromFile(string $file): self
    {
        if (!is_file($file)) {
            throw new ConfigException(sprintf('Configuration file "%s" not found; run "bin/hashover setup" to create it.', $file));
        }

        $values = require $file;

        if (!is_array($values)) {
            throw new ConfigException(sprintf('Configuration file "%s" must return an array.', $file));
        }

        return self::fromArray($values);
    }

    /**
     * @param array<mixed> $values
     */
    public static function fromArray(array $values): self
    {
        $reader = new ConfigReader($values);

        $secretKey = $reader->string('secret_key');

        if (strlen($secretKey) < 32) {
            throw new ConfigException('"secret_key" must be at least 32 characters; generate one with "bin/hashover setup".');
        }

        $adminName = trim($reader->string('admin_name'));

        if ($adminName === '') {
            throw new ConfigException('"admin_name" must not be empty.');
        }

        $adminPasswordHash = $reader->string('admin_password_hash');

        if (password_get_info($adminPasswordHash)['algo'] === null) {
            throw new ConfigException('"admin_password_hash" must be a password hash; generate one with "bin/hashover hash-password".');
        }

        $allowedHosts = array_map(strtolower(...), $reader->stringList('allowed_hosts'));

        if ($allowedHosts === []) {
            throw new ConfigException('"allowed_hosts" must list the host names of the website, for example ["example.com", "www.example.com"].');
        }

        $language = $reader->string('language', 'en');

        if (!in_array($language, self::LANGUAGES, true)) {
            throw new ConfigException(sprintf('"language" must be one of: %s.', implode(', ', self::LANGUAGES)));
        }

        $timezone = $reader->string('timezone', 'UTC');

        if (!in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            throw new ConfigException(sprintf('"timezone" "%s" is not a valid time zone identifier.', $timezone));
        }

        foreach (['notification_email', 'sender_email'] as $key) {
            $email = $reader->string($key, '');

            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw new ConfigException(sprintf('"%s" must be a valid e-mail address.', $key));
            }
        }

        $rateLimits = self::DEFAULT_RATE_LIMITS;

        foreach ($reader->array('rate_limits') as $bucket => $limit) {
            if (!is_string($bucket) || !array_key_exists($bucket, self::DEFAULT_RATE_LIMITS)
                || !is_array($limit) || count($limit) !== 2
                || !is_int($limit[0] ?? null) || !is_int($limit[1] ?? null) || $limit[0] < 1 || $limit[1] < 1) {
                throw new ConfigException('"rate_limits" entries must look like \'comment\' => [5, 600] (hits, seconds).');
            }

            $rateLimits[$bucket] = [$limit[0], $limit[1]];
        }

        $baseUrl = $reader->string('base_url', '/hashover/');

        if (!str_starts_with($baseUrl, '/') || str_starts_with($baseUrl, '//')) {
            throw new ConfigException('"base_url" must be an absolute path such as "/hashover/".');
        }

        $templates = $reader->string('templates_directory', '');

        $turnstileSiteKey = trim($reader->string('turnstile_site_key', ''));
        $turnstileSecretKey = trim($reader->string('turnstile_secret_key', ''));

        if (($turnstileSiteKey === '') !== ($turnstileSecretKey === '')) {
            throw new ConfigException('"turnstile_site_key" and "turnstile_secret_key" must both be set to enable Turnstile, or both be empty.');
        }

        return new self(
            secretKey: $secretKey,
            adminName: $adminName,
            adminPasswordHash: $adminPasswordHash,
            allowedHosts: $allowedHosts,
            baseUrl: rtrim($baseUrl, '/') . '/',
            dataDirectory: $reader->string('data_directory', __DIR__ . '/../data'),
            notificationEmail: $reader->string('notification_email', ''),
            senderEmail: $reader->string('sender_email', ''),
            replyToCommenter: $reader->bool('reply_to_commenter', false),
            language: $language,
            timezone: $timezone,
            defaultName: $reader->string('default_name', ''),
            showPageTitle: $reader->bool('show_page_title', true),
            relativeDates: $reader->bool('relative_dates', true),
            gravatar: $reader->bool('gravatar', false),
            commentRows: $reader->int('comment_rows', 5, 1, 50),
            popularThreshold: $reader->int('popular_threshold', 5, 1, PHP_INT_MAX),
            popularLimit: $reader->int('popular_limit', 2, 0, 100),
            maxCommentLength: $reader->int('max_comment_length', 20000, 1, 1_000_000),
            maxNameLength: $reader->int('max_name_length', 30, 1, 200),
            storeIpAddresses: $reader->bool('store_ip_addresses', false),
            ipRetentionDays: $reader->int('ip_retention_days', 30, 1, 3650),
            blockedIps: $reader->stringList('blocked_ips'),
            ignoredQueryParameters: array_key_exists('ignored_query_parameters', $values)
                ? $reader->stringList('ignored_query_parameters')
                : self::DEFAULT_IGNORED_QUERY_PARAMETERS,
            stopForumSpam: $reader->bool('stop_forum_spam', false),
            minimumSubmitSeconds: $reader->int('minimum_submit_seconds', 3, 0, 3600),
            rateLimits: $rateLimits,
            templatesDirectory: $templates === '' ? null : $templates,
            sourceCodeUrl: $reader->string('source_code_url', 'https://github.com/gerardkieffer/hashover'),
            forceSecureCookies: $reader->bool('force_secure_cookies', false),
            akismetKey: trim($reader->string('akismet_key', '')),
            turnstileSiteKey: $turnstileSiteKey,
            turnstileSecretKey: $turnstileSecretKey,
            turnstilePassMinutes: $reader->int('turnstile_pass_minutes', 60, 1, 60 * 24),
        );
    }

    /** "host" or "host:port" of a URL, lower-cased; null if it has no host */
    public static function hostOf(string $url): ?string
    {
        $parts = parse_url($url);

        if (!is_array($parts) || !isset($parts['host'])) {
            return null;
        }

        return strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    /** Whether a URL points to one of the website's host names */
    public function allowsUrl(string $url): bool
    {
        $host = self::hostOf($url);

        return $host !== null && in_array($host, $this->allowedHosts, true);
    }

    public function databaseFile(): string
    {
        return rtrim($this->dataDirectory, '/') . '/hashover.sqlite';
    }

    /**
     * @return array{int, int} [maximum hits, window in seconds]
     */
    public function rateLimit(string $bucket): array
    {
        return $this->rateLimits[$bucket] ?? self::DEFAULT_RATE_LIMITS[$bucket] ?? [PHP_INT_MAX, 1];
    }
}
