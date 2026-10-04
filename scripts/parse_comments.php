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


	// Pattern matching URLs in comments
	const COMMENT_URL_PATTERN = '(?:ftp|https?):\/\/[a-zA-Z0-9\-@:%_+.~#?&\/=;]+';

	// Format a comment's date as "N days ago" and so on
	function short_date(string $date): string
	{
		$date_parts = explode(' - ', $date);

		try {
			$interval = (new DateTime($date_parts[0]))->diff(new DateTime(date('m/d/Y')));
		} catch (Exception) {
			return $date;
		}

		return match (true) {
			$interval->y > 0 => $interval->y . ' year' . ($interval->y !== 1 ? 's' : '') . ' ago',
			$interval->m > 0 => $interval->m . ' month' . ($interval->m !== 1 ? 's' : '') . ' ago',
			$interval->d > 0 => $interval->d . ' day' . ($interval->d !== 1 ? 's' : '') . ' ago',
			default => ($date_parts[1] ?? '') . ' today'
		};
	}

	// Read a comment file and add it to the comments, as an array in PHP
	// mode, or as JavaScript object literals in JavaScript mode
	function parse_comments(string $file, array|string $variable, string $check): array|string
	{
		global $mode, $root_dir, $ref_path, $text, $icons, $icon_size, $short_dates, $top_likes, $popular, $indention, $script_query;

		$file_id = basename($file, '.xml');

		if (!is_comment_id($file_id) || ($script_query && query('count_link') !== '')) {
			return $variable;
		}

		if (($read_cmt = load_comment($file)) === null) {
			return $variable;
		}

		// Generate permalink
		$permalink = 'c' . str_replace('-', 'r', $file_id) . (($check === 'yes') ? '' : '_pop');
		$file_parts = explode('-', $file_id);
		$permatext = end($file_parts);
		$is_reply = str_contains($file_id, '-');

		// Calculate CSS padding for reply indention
		$dashes = substr_count($file_id, '-');
		$indent = ($dashes > 0 && $check === 'yes') ? (((int) $icon_size + 4) * $dashes) + 16 : 0;

		$likes = (int) $read_cmt['likes'];
		$name = (string) $read_cmt->name;
		$email = decrypt_email((string) $read_cmt->email);
		$notifications = (string) $read_cmt['notifications'];

		if ($likes >= (int) $popular) {
			$top_likes[$likes] = $file;
		}

		$name_at = str_starts_with($name, '@') ? '@' : '';
		$name_class = ($name_at !== '') ? ' at' : '';
		$admin_login = is_admin();

		// Legacy comments have no login verifier, they still need a password to be edited
		$user_login = owns_comment($read_cmt)
			|| ((string) $read_cmt->login === '' && (string) $read_cmt->passwd !== '' && cookie('name') !== '' && cookie('name') === $name);

		// "Like" cookie
		$like_cookie = md5(($_SERVER['SERVER_NAME'] ?? '') . $ref_path . '/' . $file_id);

		// Names and websites are stored with some characters as entities
		$display_name = str_replace('@identica', '<span style="display: none;">@identica</span>', h(preg_replace('/^@/', '', $name), false));
		$website = safe_url(html_entity_decode((string) $read_cmt->website, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

		if ($website === '') {
			if (preg_match('/^@[a-zA-Z0-9_@]{1,29}$/D', $name) === 1) {
				$profile = (preg_match('/@identica/i', $name) !== 1) ? 'twitter.com/' : 'identi.ca/';
				$variable_name = $name_at . '<a rel="nofollow noopener" id="opt-website-' . $permalink . '" href="https://' . $profile . h(str_replace(['@identica', '@'], '', $name)) . '" target="_blank">' . $display_name . '</a>';
			} else {
				$variable_name = $display_name;
			}
		} else {
			$variable_name = $name_at . '<a rel="nofollow noopener" id="opt-website-' . $permalink . '" href="' . h($website) . '" target="_blank">' . $display_name . '</a>';
		}

		// Format date and time
		$cmt_date = h(($short_dates === 'yes') ? short_date((string) $read_cmt->date) : (string) $read_cmt->date, false);

		// Get avatar icons
		if ($icons === 'yes') {
			$avatar = get_user_avatar(($email !== '') ? md5(strtolower(trim($email))) : '');
			$avatar_icon = '<img width="' . (int) $icon_size . '" height="' . (int) $icon_size . '" src="' . h($avatar) . '" alt="#' . $permatext . '" style="vertical-align: top;">';
		} else {
			$avatar_icon = '<a rel="nofollow" href="#' . $permalink . '" title="Permalink">#' . $permatext . '</a>';
		}

		// Setup "Like" link
		$like_onclick = 'like(\'' . $permalink . '\', \'' . $file_id . '\'); ';
		$liked = cookie($like_cookie) === 'liked';
		$like_title = $liked ? $text['liked_cmt'] : $text['like_cmt'];
		$like_class = $liked ? 'liked' : 'like';

		// Define "Reply" link with appropriate tooltip
		$is_poster = same_email(safe_email(cookie('email')), $email);

		if ($email !== '' && $notifications === 'yes') {
			$email_indicator = $is_poster ? $text['op_cmt_note'] . '" class="no-email"' : h($name, false) . ' ' . $text['subbed_note'] . '" class="has-email"';
		} else {
			$email_indicator = h($name, false) . ' ' . $text['unsubbed_note'] . '" class="no-email"';
		}

		// Add HTML anchor tag to URLs
		$clean_code = preg_replace('/(' . COMMENT_URL_PATTERN . ')(\s*)/i', '<a rel="nofollow noopener" href="$1" target="_blank">$1</a>', (string) $read_cmt->body);

		// Replace [img] tags with external image placeholder if enabled
		$clean_code = preg_replace_callback('/\[img\]<a.*?>(' . COMMENT_URL_PATTERN . ')<\/a>\[\/img\]/i', function (array $arr) use ($root_dir): string {
			if (in_array(strtolower(pathinfo($arr[1], PATHINFO_EXTENSION)), ['jpeg', 'jpg', 'png', 'gif'], true)) {
				return '<br><br><img src="' . $root_dir . 'images/place-holder.png" title="' . $arr[1] . '" alt="Loading..." onClick="((this.src==this.title) ? this.src=\'' . $root_dir . 'images/place-holder.png\' : this.src=this.title);"><br><br>';
			}

			return '<a rel="nofollow noopener" href="' . $arr[1] . '" target="_blank">' . $arr[1] . '</a>';
		}, $clean_code);

		// Remove repetitive and trailing HTML <br> tags
		$clean_code = preg_replace('/^(<br><br>)/', '', preg_replace('/(<br><br>)$/', '', preg_replace('/(<br>){2,}/i', '<br><br>', $clean_code)));

		// Comment properties
		$entry = [
			'permalink' => $permalink,
			'cmtclass' => $is_reply ? 'cmtdiv reply' : 'cmtdiv',
			'avatar' => $avatar_icon,
			'indent' => ($indention === 'right') ? '16px ' . $indent . 'px 12px 0px' : '16px 0px 12px ' . $indent . 'px',
			'name' => '<b class="cmtfont' . $name_class . '" id="opt-name-' . $permalink . '">' . $variable_name . '</b>'
		];

		if ($is_reply) {
			$entry['thread'] = '<a rel="nofollow" href="#' . preg_replace('/^(.*)r.*$/', '$1', $permalink) . '" title="' . $text['thread_tip'] . '" style="float: right;">' . $text['thread'] . '</a>';
		}

		$entry['date'] = '<a rel="nofollow" href="#' . str_replace('_pop', '', $permalink) . '" title="Permalink">' . $cmt_date . '</a>';

		if ($likes > 0) {
			$entry['likes'] = $likes . ' Like' . (($likes !== 1) ? 's' : '');
		}

		$entry['sort_name'] = $name;
		$entry['sort_date'] = (string) strtotime(str_replace('- ', '', (string) $read_cmt->date));
		$entry['sort_likes'] = (string) $likes;

		// Define "Like" link for everyone except original poster
		if (!$user_login && !$is_poster) {
			$entry['like_link'] = '<a rel="nofollow" href="#" id="like-' . $permalink . '" onClick="' . $like_onclick . 'return false;" title="' . $like_title . '" class="' . $like_class . '">Like</a>';
		}

		if ($mode === 'php') {
			$entry['notifications'] = $notifications;

			// Define "Edit" link if proper login cookie set
			if ($user_login || $admin_login) {
				$entry['edit_link'] = '<a rel="nofollow" href="?hashover_edit=' . $permalink . '#' . $permalink . '" title="' . $text['edit_your_cmt'] . '" class="edit">Edit</a>';
			}

			$entry['reply_link'] = '<a rel="nofollow" href="?hashover_reply=' . $permalink . '#' . $permalink . '" title="' . $text['reply_to_cmt'] . ' - ' . $email_indicator . '>Reply</a>';
			$entry['comment'] = str_replace('\n', PHP_EOL, $clean_code);

			$variable = is_array($variable) ? $variable : [];
			$variable[] = $entry;

			return $variable;
		}

		// Define "Edit" link if proper login cookie set
		if ($user_login || $admin_login) {
			$entry['edit_link'] = '<a rel="nofollow" href="#" onClick="editcmt(\'' . $permalink . '\', \'' . $file_id . '\', \'' . (($notifications !== 'no') ? '1' : '0') . '\'); return false;" title="' . $text['edit_your_cmt'] . '" class="edit">Edit</a>';
		}

		$entry['reply_link'] = '<a rel="nofollow" href="#" onClick="reply(\'' . $permalink . '\', \'' . $file_id . '\'); return false;" title="' . $text['reply_to_cmt'] . ' - ' . $email_indicator . '>Reply</a>';
		$entry['comment'] = str_replace('\n', "\n", $clean_code);

		return $variable . "\t" . js_value($entry) . ',' . PHP_EOL . PHP_EOL;
	}
