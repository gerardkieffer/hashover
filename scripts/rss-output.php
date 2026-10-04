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


	// Set feed title
	$title = (query('title') !== '') ? query('title') : normalize_host($domain) . ': ' . basename($dir);
	$feed_url = safe_url(query('rss'));
	$rss_feed = '';

	// Read directory contents if conditions met
	if (is_dir($dir) && $feed_url !== '') {
		$comments = [];

		// Read comment files into array; convert date into UNIX timestamp
		foreach (glob($dir . '/*.xml', GLOB_NOSORT) ?: [] as $file) {
			$file_id = basename($file, '.xml');

			if (!is_comment_id($file_id) || ($comment = load_comment($file)) === null) {
				continue;
			}

			$comments[] = [
				'comment' => $comment,
				'file_id' => $file_id,
				'date' => (int) strtotime(str_replace(['- ', 'am', 'pm'], ['', ' AM PST', ' PM PST'], (string) $comment->date))
			];

			if (!str_contains($file_id, '-')) {
				$cmt_count++;
			}

			$total_count++;
		}

		// Sort by comment creation date
		usort($comments, fn (array $a, array $b): int => $b['date'] <=> $a['date']);

		foreach ($comments as $entry) {
			$rss_cmt = $entry['comment'];
			$permalink = 'c' . str_replace('-', 'r', $entry['file_id']);
			$name = str_replace('@identica', '', strip_tags(html_entity_decode((string) $rss_cmt->name, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
			$body = str_replace(['\n', '\r'], ' ', (string) $rss_cmt->body);
			$summary = strip_tags(html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
			$summary = (mb_strlen($summary) > 40) ? mb_substr($summary, 0, 40) . '...' : $summary;
			$email = decrypt_email((string) $rss_cmt->email);

			// Add avatar URLs to feed
			$rss_avatar = get_user_avatar(($email !== '') ? md5(strtolower(trim($email))) : '');

			$rss_feed .= "\t\t" . '<item>' . PHP_EOL;
			$rss_feed .= "\t\t\t" . '<title>' . xml_escape($name . ' : ' . $summary) . '</title>' . PHP_EOL;
			$rss_feed .= "\t\t\t" . '<nickname>' . xml_escape($name) . '</nickname>' . PHP_EOL;
			$rss_feed .= "\t\t\t" . '<description>' . xml_escape(strip_tags($body, '<br><a><b><i><u><s><blockquote><img>')) . '</description>' . PHP_EOL;
			$rss_feed .= "\t\t\t" . '<avatar>' . xml_escape($rss_avatar) . '</avatar>' . PHP_EOL;
			$rss_feed .= "\t\t\t" . '<likes>' . (int) $rss_cmt['likes'] . '</likes>' . PHP_EOL;
			$rss_feed .= "\t\t\t" . '<pubDate>' . date('D, d M Y H:i:s O', (int) strtotime(str_replace(' - ', ' ', (string) $rss_cmt->date))) . '</pubDate>' . PHP_EOL;
			$rss_feed .= "\t\t\t" . '<guid>' . xml_escape($feed_url . '#' . $permalink) . '</guid>' . PHP_EOL;
			$rss_feed .= "\t\t\t" . '<link>' . xml_escape($feed_url . '#' . $permalink) . '</link>' . PHP_EOL;
			$rss_feed .= "\t\t" . '</item>' . PHP_EOL;
		}
	} else {
		$rss_feed .= "\t\t" . '<item>' . PHP_EOL;
		$rss_feed .= "\t\t\t" . '<title>Error</title>' . PHP_EOL;
		$rss_feed .= "\t\t\t" . '<description>Please choose a comment thread via page URL.</description>' . PHP_EOL;
		$rss_feed .= "\t\t" . '</item>' . PHP_EOL;
	}

	$self_url = request_scheme() . '://' . $domain . ($_SERVER['SCRIPT_NAME'] ?? '') . '?rss=' . rawurlencode($feed_url) . '&title=' . rawurlencode($title);

	header('Content-Type: application/rss+xml; charset=UTF-8');
	header('X-Content-Type-Options: nosniff');
	header("Content-Security-Policy: default-src 'none'; sandbox");

	echo '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
	echo '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">' . PHP_EOL;
	echo "\t" . '<channel>' . PHP_EOL;
	echo "\t\t" . '<title>' . xml_escape($title) . '</title>' . PHP_EOL;
	echo "\t\t" . '<link>' . xml_escape($feed_url) . '</link>' . PHP_EOL;
	echo "\t\t" . '<description>' . xml_escape(html_entity_decode($text['showing_cmts'], ENT_QUOTES | ENT_HTML5, 'UTF-8')) . ' ' . ($total_count - 1) . ' Comments</description>' . PHP_EOL;
	echo "\t\t" . '<atom:link href="' . xml_escape($self_url) . '" rel="self"></atom:link>' . PHP_EOL;
	echo "\t\t" . '<language>en-us</language>' . PHP_EOL;
	echo "\t\t" . '<ttl>40</ttl>' . PHP_EOL;
	echo mb_scrub($rss_feed, 'UTF-8');
	echo "\t" . '</channel>' . PHP_EOL;
	exit('</rss>');
