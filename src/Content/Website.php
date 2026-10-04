<?php

declare(strict_types=1);

namespace HashOver\Content;

/**
 * Commenters' website addresses
 */
final class Website
{
    private const int MAXIMUM_LENGTH = 500;

    /**
     * An absolute http(s) URL, with "https://" added when no scheme is given;
     * an empty string for no website, null for an invalid address
     */
    public static function normalize(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $value) !== 1) {
            $value = 'https://' . $value;
        }

        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

        if (filter_var($value, FILTER_VALIDATE_URL) === false || !in_array($scheme, ['http', 'https'], true) || strlen($value) > self::MAXIMUM_LENGTH) {
            return null;
        }

        return $value;
    }
}
