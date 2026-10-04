<?php

declare(strict_types=1);

	// Copyright (C) 2014-2019 Jacob Barkdull
	//
	//	This program is free software: you can redistribute it and/or modify
	//	it under the terms of the GNU Affero General Public License as
	//	published by the Free Software Foundation, either version 3 of the
	//	License, or (at your option) any later version.
	//
	//	This program is distributed in the hope that it will be useful,
	//	but WITHOUT ANY WARRANTY; without even the implied warranty of
	//	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	//	GNU Affero General Public License for more details.
	//
	//	You should have received a copy of the GNU Affero General Public License
	//	along with this program.  If not, see <http://www.gnu.org/licenses/>.
	//
	//--------------------
	//
	// Script Description:
	//
	//	Shared helpers for output escaping, input validation, cookies,
	//	request origin checks, and authentication tokens.


	// Escape a value for HTML text, HTML attributes, and JavaScript string
	// literals at once: quotes, angle brackets, ampersands, backslashes, and
	// line breaks are all turned into HTML character references
	function h(mixed $value, bool $double_encode = true): string
	{
		$escaped = htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', $double_encode);

		return str_replace(['\\', "\n", "\r"], ['&#92;', '&#10;', '&#13;'], $escaped);
	}

	// Escape a value for XML text and attributes (RSS feed)
	function xml_escape(mixed $value): string
	{
		return htmlspecialchars((string) $value, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
	}

	// Encode a PHP value as a JavaScript literal that is safe anywhere in a script
	function js_value(mixed $value): string
	{
		return json_encode($value,
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
			| JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			| JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
		);
	}

	// Whether the current request was made over HTTPS
	function is_https(): bool
	{
		$https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));

		return ($https !== '' && $https !== 'off') || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
	}

	// URL scheme of the current request
	function request_scheme(): string
	{
		return is_https() ? 'https' : 'http';
	}

	// Validate the configured domain (which defaults to the Host header)
	function clean_domain(string $domain): ?string
	{
		$domain = strtolower(trim($domain));

		if (preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?::\d{1,5})?$/D', $domain) === 1
		    || preg_match('/^\[[0-9a-f:.]+\](?::\d{1,5})?$/D', $domain) === 1) {
			return $domain;
		}

		return null;
	}

	// Normalize a "host[:port]" pair for comparison, ignoring a "www." prefix
	function normalize_host(string $host): string
	{
		$host = strtolower($host);

		return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
	}

	// Get "host[:port]" from a URL, or null if the URL has no host
	function url_host(string $url): ?string
	{
		$parts = parse_url($url);

		if (!is_array($parts) || empty($parts['host'])) {
			return null;
		}

		return $parts['host'] . (!empty($parts['port']) ? ':' . $parts['port'] : '');
	}

	// Whether a URL points to the configured domain
	function is_own_url(string $url): bool
	{
		global $domain;

		$host = url_host($url);

		return $host !== null && normalize_host($host) === normalize_host($domain);
	}

	// Whether a request appears to come from a page on the configured domain;
	// browsers always send an Origin header with cross-site POST requests
	function is_same_origin_request(): bool
	{
		if (!empty($_SERVER['HTTP_ORIGIN'])) {
			return is_own_url((string) $_SERVER['HTTP_ORIGIN']);
		}

		if (!empty($_SERVER['HTTP_REFERER'])) {
			return is_own_url((string) $_SERVER['HTTP_REFERER']);
		}

		return true;
	}

	// Accept only absolute HTTP(S) URLs
	function safe_url(string $url): string
	{
		$url = trim($url);

		if (filter_var($url, FILTER_VALIDATE_URL) === false) {
			return '';
		}

		$scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

		return in_array($scheme, ['http', 'https'], true) ? $url : '';
	}

	// Accept only valid e-mail addresses
	function safe_email(string $email): string
	{
		$email = trim($email);

		return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : '';
	}

	// Comment identifiers look like "1", "1-2", "1-2-3", and so on
	function is_comment_id(string $id): bool
	{
		return preg_match('/^[1-9]\d{0,5}(?:-[1-9]\d{0,5}){0,30}$/D', $id) === 1;
	}

	// Comment thread directory names are restricted to these characters
	function is_thread_name(string $name): bool
	{
		return preg_match('/^[A-Za-z0-9%~@,;()-]{1,200}$/D', $name) === 1;
	}

	// Domain attribute for cookies, shared by "www." and bare domain
	function cookie_domain(): string
	{
		global $domain;

		$host = normalize_host((string) preg_replace('/:\d+$/', '', $domain));

		// Browsers reject the domain attribute for single-label hosts and IP addresses
		if (!str_contains($host, '.') || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
			return '';
		}

		return $host;
	}

	// Set a cookie that is invisible to JavaScript and not sent cross-site
	function set_cookie(string $name, string $value, int $expires): void
	{
		setcookie($name, $value, [
			'expires' => $expires,
			'path' => '/',
			'domain' => cookie_domain(),
			'secure' => is_https(),
			'httponly' => true,
			'samesite' => 'Lax'
		]);
	}

	// Delete a cookie
	function clear_cookie(string $name): void
	{
		set_cookie($name, '', 1);
	}

	// Read a cookie as a string
	function cookie(string $name): string
	{
		$value = $_COOKIE[$name] ?? '';

		return is_string($value) ? $value : '';
	}

	// Read a POST field as a string
	function post(string $name): string
	{
		$value = $_POST[$name] ?? '';

		return is_string($value) ? $value : '';
	}

	// Read a GET field as a string
	function query(string $name): string
	{
		$value = $_GET[$name] ?? '';

		return is_string($value) ? $value : '';
	}

	// Derive a purpose-specific 256-bit key from the configured encryption key
	function derived_key(string $purpose): string
	{
		global $encryption_key;

		return hash_hkdf('sha256', $encryption_key, 32, 'hashover:' . $purpose);
	}

	// Login token for a name and password combination, stored in a cookie
	function login_token(string $name, string $password): string
	{
		return hash_hmac('sha256', mb_strtolower(trim($name)) . "\0" . $password, derived_key('login'));
	}

	// One-way verifier for a login token, stored in comment files; it can't be
	// turned back into a token, so reading a comment file doesn't allow a login
	function login_verifier(string $token): string
	{
		return hash('sha256', $token);
	}

	// Login token for the administrator, changes whenever the credentials do
	function admin_token(): string
	{
		global $admin_nickname, $admin_password;

		return hash_hmac('sha256', "admin\0" . $admin_nickname . "\0" . $admin_password, derived_key('admin'));
	}

	// Whether a password matches the configured administrator password,
	// which may be stored either as plain text or as a password_hash() hash
	function is_admin_password(string $password): bool
	{
		global $admin_password;

		if ($password === '') {
			return false;
		}

		if (password_get_info($admin_password)['algo'] !== null) {
			return password_verify($password, $admin_password);
		}

		return hash_equals($admin_password, $password);
	}

	// Whether a nickname is the administrator's nickname
	function is_admin_name(string $name): bool
	{
		global $admin_nickname;

		return mb_strtolower(trim($name)) === mb_strtolower(trim($admin_nickname));
	}

	// Whether the visitor holds a valid administrator login cookie
	function is_admin(): bool
	{
		$token = cookie('hashover-login');

		return $token !== '' && hash_equals(admin_token(), $token);
	}

	// Load a comment file; returns null on failure
	function load_comment(string $file): ?\SimpleXMLElement
	{
		$previous = libxml_use_internal_errors(true);
		$comment = is_file($file) ? simplexml_load_file($file, null, LIBXML_NONET) : false;
		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		return $comment === false ? null : $comment;
	}

	// Whether the visitor's login cookie matches a comment's login verifier
	function owns_comment(\SimpleXMLElement $comment): bool
	{
		$token = cookie('hashover-login');
		$verifier = (string) $comment->login;

		return $token !== '' && $verifier !== '' && hash_equals($verifier, login_verifier($token));
	}

	// Whether the visitor's IP address is blocked locally or by stopforumspam.com
	function is_blocked_visitor(bool $check_spam_database): bool
	{
		$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

		if (is_readable('./blocklist.txt')) {
			$blocked = array_filter(array_map('trim', file('./blocklist.txt', FILE_IGNORE_NEW_LINES) ?: []));

			if (in_array($ip, $blocked, true)) {
				return true;
			}
		}

		if ($check_spam_database && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
			$context = stream_context_create(['http' => ['timeout' => 3]]);
			$response = @file_get_contents('https://api.stopforumspam.org/api?json&ip=' . rawurlencode($ip), false, $context);

			if ($response !== false) {
				$result = json_decode($response, true);

				if (is_array($result) && !empty($result['ip']['appears'])) {
					return true;
				}
			}
		}

		return false;
	}
