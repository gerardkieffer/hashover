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
	//	Free / Open Source PHP comment system intended to replace Disqus. 
	//	Allows completely anonymous comments to be posted, the only 
	//	required information is the comment itself. The comments are stored 
	//	as individual XML files, example: "1.xml" is the first comment, 
	//	"2.xml" is the second, and "1-1.xml" is the first reply to the first 
	//	comment, "1-2.xml" is the second reply, and so on.
	//
	//	Features restricted use of HTML tags, automatic URL links, avatar 
	//	icons, replies, comment editing and deletion, notification emails, 
	//	comment RSS feeds, likes, popular comments, customizable CSS, 
	//	referrer checking, permalinks, and more!
	//
	//--------------------
	//
	// Change Log:
	//
	//	Please record your modifications to code:
	//	/hashover/changelog.txt


	// Whether this script was requested directly (JavaScript mode, RSS, posting)
	$script_query = basename((string) $_SERVER['SCRIPT_FILENAME']) === basename(__FILE__);

	// Use UTF-8 character set
	ini_set('default_charset', 'UTF-8');

	// Script execution starting time
	$exec_start = microtime(true);

	// Output for JavaScript mode; "'+name+'" placeholders reference JavaScript variables
	function jsAddSlashes(string $script, string $type = ''): string
	{
		global $mode;

		if (isset($mode) && $mode !== 'javascript') {
			return str_replace(['\n', '\r'], '', $script) . PHP_EOL;
		}

		// Literal "\n" sequences in markup are meant as JavaScript newline escapes
		$script = str_replace(['\n', '\r'], ["\n", "\r"], $script);
		$parts = preg_split("/'\\+([A-Za-z_][A-Za-z0-9_]*)\\+'/", $script, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$script];
		$expression = [];

		foreach ($parts as $index => $part) {
			if ($index % 2 === 1) {
				$expression[] = $part;
			} elseif ($part !== '') {
				$expression[] = js_value($part);
			}
		}

		$expression = implode(' + ', $expression ?: ["''"]);

		if ($type === 'single') {
			return 'document.write(' . $expression . ');' . PHP_EOL;
		}

		return 'show_cmt += ' . $expression . ';' . PHP_EOL;
	}

	// Display an error and stop
	function hashover_error(string $message): never
	{
		exit(jsAddSlashes('<b>HashOver - Error:</b> ' . $message, 'single'));
	}

	// Include settings, encryption key & notification e-mail, and helpers
	foreach (['settings.php', 'secrets.php', 'functions.php'] as $required) {
		if (!include(__DIR__ . '/scripts/' . $required)) {
			hashover_error('file "' . $required . '" is required');
		}
	}

	// Validate the domain, which defaults to the HTTP Host header
	if (($domain = clean_domain($domain)) === null) {
		hashover_error('Invalid domain name.');
	}

	// Exit if encryption key, notification email, or administrative nickname or password set to defaults
	if ($encryption_key === '8CharKey' || $notification_email === 'example@example.com' || $admin_nickname === 'admin' || $admin_password === 'passwd') {
		exit(jsAddSlashes('<b>HashOver:</b> The variable values in /hashover/scripts/secrets.php need to be UNIQUE.', 'single'));
	}

	// Exit if encryption key is too short
	if (strlen(str_replace(' ', '', $encryption_key)) < 8) {
		hashover_error('Key error, make sure it\'s at least 8 characters long.');
	}

	// Whether this request changes data
	$is_write_request = $script_query && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

	// Exit if visitor's IP address is blocked; querying stopforumspam.com
	// sends the visitor's IP address to a third party, so only do it when
	// the visitor attempts to post, edit or delete a comment
	if (is_blocked_visitor($is_write_request && in_array($spam_IP_check, ['php', 'javascript', 'both'], true))) {
		exit(jsAddSlashes('<b>HashOver:</b> You are blocked!', 'single'));
	}

	// Reject cross-site form submissions
	if ($is_write_request && !is_same_origin_request()) {
		http_response_code(403);
		exit('Cross-site request rejected.');
	}

	// Get user avatar URL by hash
	function get_user_avatar(string $hash): string
	{
		global $root_dir, $domain;

		// Default avatar URL
		$default_avatar = $root_dir . 'images/avatar.png';

		// Use Gravatar if e-mail cookie exists
		if ($hash !== '') {
			return 'https://gravatar.com/avatar/' . $hash . '.png?d=' . rawurlencode(request_scheme() . '://' . $domain . $default_avatar) . '&s=45&r=pg';
		}

		// Use default avatar
		return $default_avatar;
	}

	// Default scripts to be included
	$include_files = [
		'./scripts/encryption.php',
		'./scripts/urlwork.php',
		'./scripts/global_variables.php',
		'./scripts/locales.php'
	];

	// Load scripts for displaying comments or RSS feed
	if (!isset($_GET['rss'])) {
		array_push($include_files,
			'./scripts/parse_comments.php',
			'./scripts/deletion_notice.php',
			'./scripts/read_comments.php'
		);

		// Only handle form submissions sent directly to this script
		if ($is_write_request) {
			$include_files[] = './scripts/write_comments.php';
		}
	} else {
		$include_files[] = './scripts/rss-output.php';
	}

	// Actually include the scripts; display error on failure
	foreach ($include_files as $script) {
		if (!include($script)) {
			hashover_error('"' . h($script) . '" file could not be included!');
		}
	}

	// Function for displaying comment count
	function display_count(): string
	{
		global $cmt_count, $total_count, $deleted_cmt, $deleted_total, $count_missing;

		$cmt_count--;
		$total_count--;

		$cmt_copy = $cmt_count;
		$total_copy = $total_count;

		if ($count_missing === 'no') {
			$cmt_copy -= $deleted_cmt;
			$total_copy -= $deleted_total;
		}

		$show_count = $cmt_copy . ' Comment' . ($cmt_copy !== 1 ? 's' : '');

		if ($total_copy !== $cmt_copy) {
			$show_count .= ' (' . $total_copy . ' counting repl' . ($total_copy !== 2 ? 'ies)' : 'y)');
		}

		return $show_count;
	}

	// If the "count_link" query is set, echo comment count as link
	if ($script_query && query('count_link') !== '') {
		$count_link = h(safe_url(query('count_link')));

		if (is_dir($dir)) {
			read_comments($dir, 'no'); // Run read_comments function
		}

		if ($total_count > 1) {
			exit(jsAddSlashes('<a rel="nofollow" href="' . $count_link . '#comments">' . display_count() . '</a>', 'single'));
		}

		exit(jsAddSlashes('<a rel="nofollow" href="' . $count_link . '#comments">Post Comment</a>', 'single'));
	}

	// Clear message cookie
	if (cookie('message') !== '') {
		clear_cookie('message');
	}

	// Remove cookies set by older versions, which held passwords and login hashes
	foreach (array_keys($_COOKIE) as $cookie_name) {
		if ($cookie_name === 'password' || (str_starts_with((string) $cookie_name, 'hashover-') && $cookie_name !== 'hashover-login')) {
			clear_cookie((string) $cookie_name);
		}
	}

	// Check if either a comment or reply failed to post
	if (cookie('success') === 'no') {
		clear_cookie('success');

		if (cookie('replied') !== '') {
			$text['comment_form'] = $text['reply_form'];
			$text['post_button'] = $text['post_reply'];
			clear_cookie('replied');
		}
	}

	// Check if visitor is on mobile device
	$is_mobile = preg_match('/android|blackberry|phone/i', (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')) === 1 ? 'yes' : 'no';

	if (is_dir($dir)) {
		read_comments($dir, 'yes'); // Run read_comments function
	}

	krsort($top_likes); // Sort popular comments

	// Construct avatar image tag
	$email_cookie = safe_email(cookie('email'));
	$user_avatar = get_user_avatar($email_cookie !== '' ? md5(strtolower($email_cookie)) : '');
	$avatar_image = '<img align="left" width="' . (int) $icon_size . '" height="' . (int) $icon_size . '" src="' . h($user_avatar) . '">';

	if ($mode === 'php') {
		if (!include('./scripts/php-mode.php')) {
			hashover_error('file "php-mode.php" could not be included!');
		}
	} else {
		if (!include('./scripts/javascript-mode.php')) {
			hashover_error('file "javascript-mode.php" could not be included!');
		}
	}
