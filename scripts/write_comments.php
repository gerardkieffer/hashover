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


	// URL back to comment; always a local path, never another website
	$kickback = '/' . ltrim($parse_url['path'], '/\\') . (($parse_url['query'] !== '') ? '?' . $parse_url['query'] : '');

	// Redirect visitor back to the page and stop
	function kick_back(string $fragment): never
	{
		global $kickback;

		header('Location: ' . $kickback . '#' . $fragment);
		exit;
	}

	// Remove characters that aren't allowed in XML
	function xml_sanitize(string $string): string
	{
		$string = mb_scrub($string, 'UTF-8');

		return (string) preg_replace('/[^\x{0009}\x{000A}\x{000D}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '?', $string);
	}

	// Write a new comment file without overwriting an existing one; a
	// comment posted at the same time as another gets the next number
	function write_new_comment(\SimpleXMLElement $comment, string $dir, string $file_id): ?string
	{
		if (!is_dir($dir) && !mkdir($dir, 0755) && !is_dir($dir)) {
			return null;
		}

		$parts = explode('-', $file_id);

		for ($attempt = 0; $attempt < 100; $attempt++) {
			$file = $dir . '/' . implode('-', $parts) . '.xml';
			$handle = @fopen($file, 'x');

			if ($handle !== false) {
				$written = fwrite($handle, (string) $comment->asXML()) !== false;
				fclose($handle);

				if (!$written) {
					unlink($file);
					return null;
				}

				chmod($file, 0600);
				return $file;
			}

			if (!file_exists($file)) {
				return null;
			}

			$parts[count($parts) - 1]++;
		}

		return null;
	}

	// Save changes to an existing comment file
	function save_comment(\SimpleXMLElement $comment, string $file): bool
	{
		return file_put_contents($file, (string) $comment->asXML(), LOCK_EX) !== false;
	}

	// Whether the visitor may edit or delete a comment; upgrades legacy password hashes
	function may_modify_comment(\SimpleXMLElement $comment, string $password): bool
	{
		if (is_admin() || is_admin_password($password)) {
			return true;
		}

		$stored = (string) $comment->passwd;

		if (verify_password($password, $stored)) {
			if (password_needs_upgrade($stored)) {
				$comment->passwd = hash_password($password);
				$comment->login = login_verifier(login_token((string) $comment->name, $password));
			}

			return true;
		}

		return owns_comment($comment);
	}

	$posted_password = post('password');
	$posted_password = ($posted_password !== $text['password']) ? $posted_password : '';
	$cmtfile = post('cmtfile');
	$reply_to = post('reply_to');

	// Ignore malformed comment references
	$cmtfile = is_comment_id($cmtfile) ? $cmtfile : '';
	$reply_to = is_comment_id($reply_to) ? $reply_to : '';

	// The administrator's own cookies shouldn't change when editing someone else's comment
	$update_cookies = !isset($_POST['delete']) && !(isset($_POST['edit']) && is_admin());

	// Clean up name, set name cookie
	if (trim(post('name')) !== '' && post('name') !== $text['nickname']) {
		$name = mb_substr(str_replace($search, $replace, post('name')), 0, 30);

		if ($update_cookies) {
			set_cookie('name', $name, $expire);
		}
	}

	// Default email headers
	$header = "From: $noreply_email\r\nReply-To: $noreply_email";
	$email = '';

	// Clean up email, set email cookie
	if (trim(post('email')) !== '' && post('email') !== $text['email']) {
		$email = safe_email(str_replace($search, '', post('email')));

		if ($email !== '') {
			// Never send as the commenter, that would fail SPF/DMARC checks
			$header = "From: $noreply_email\r\nReply-To: $email";

			if ($update_cookies) {
				set_cookie('email', $email, $expire);
			}
		}
	}

	// Clean up web address, set website cookie
	$website = '';

	if (trim(post('website')) !== '' && post('website') !== $text['website']) {
		$website = trim(post('website'));
		$website = (!preg_match('/^https?:\/\//i', $website)) ? 'http://' . $website : $website;
		$website = safe_url($website);

		if ($website !== '' && $update_cookies) {
			set_cookie('website', $website, $expire);
		}
	}

	// Delete comment
	if (isset($_POST['delete'])) {
		$del_file = $dir . '/' . $cmtfile . '.xml';

		if ($cmtfile === '' || ($get_pass = load_comment($del_file)) === null) {
			kick_back('comments');
		}

		// Check if password matches the one in the file
		if (may_modify_comment($get_pass, $posted_password)) {
			unlink($del_file); // Delete the comment file

			// Kick visitor back to comment
			$parts = explode('-', $cmtfile);
			$parts[count($parts) - 1]++;

			if (file_exists($dir . '/' . implode('-', $parts) . '.xml') || file_exists($dir . '/' . $cmtfile . '-1.xml')) {
				kick_back('c' . str_replace('-', 'r', $cmtfile));
			}

			set_cookie('message', $text['cmt_deleted'], $expire);
			kick_back('comments');
		}

		kick_back('c' . str_replace('-', 'r', $cmtfile));
	}

	// Check trap fields
	foreach (['summary', 'middlename', 'lastname', 'address', 'zip'] as $trap) {
		if (!empty($_POST[$trap])) {
			$is_spam = true;
		}
	}

	// Check if a comment has been entered, clean comment, replace HTML, create hyperlinks
	if (!isset($is_spam) && isset($_POST['comment'])) {
		if (isset($_POST['login'])) {
			if (is_admin_name($name) && is_admin_password($posted_password)) {
				set_cookie('hashover-login', admin_token(), $expire);
			} elseif ($posted_password !== '') {
				set_cookie('hashover-login', login_token($name, $posted_password), $expire);
			} else {
				clear_cookie('hashover-login');
			}

			set_cookie('message', $text['logged_in'], $expire);
			kick_back('comments');
		}

		$comment = post('comment');

		// Only the administrator may use the administrator's nickname
		if (is_admin_name($name) && !is_admin() && !is_admin_password($posted_password) && !isset($_POST['edit'])) {
			set_cookie('message', $text['post_fail'], $expire);
			kick_back('comments');
		}

		if (trim($comment, " \r\n") !== '' && mb_strlen($comment) <= (int) ($max_comment ?? 20000) && stripos($comment, $text['comment_form']) === false && stripos($comment, $text['reply_form']) === false) {
			// Characters to search for and replace with in comments
			$data_search = ['\\', '"', '<', '>', "\n\r", "\n", "\r", '  ', '&lt;b&gt;', '&lt;/b&gt;', '&lt;u&gt;', '&lt;/u&gt;', '&lt;i&gt;', '&lt;/i&gt;', '&lt;s&gt;', '&lt;/s&gt;', '&lt;pre&gt;', '&lt;/pre&gt;', '&lt;code&gt;', '&lt;/code&gt;', '&lt;ul&gt;', '&lt;/ul&gt;', '&lt;ol&gt;', '&lt;/ol&gt;', '&lt;li&gt;', '&lt;/li&gt;', '&lt;blockquote&gt;', '&lt;/blockquote&gt;'];
			$data_replace = ['&#92;', '&quot;', '&lt;', '&gt;', '<br>', '', '<br>', ' &nbsp;', '<b>', '</b>', '<u>', '</u>', '<i>', '</i>', '<s>', '</s>', '<pre>', '</pre>', '<code>', '</code>', '<ul>', '</ul>', '<ol>', '</ol>', '<li>', '</li>', '<blockquote>', '</blockquote>'];

			$clean_code = preg_replace('/(' . COMMENT_URL_PATTERN . ')/i', '$1 ', $comment); // Add space to end of URLs to separate '&' characters from escaped HTML tags
			$clean_code = str_ireplace($data_search, $data_replace, preg_replace('/\n{2,}/', "\n\r\n", preg_replace('/^\s+$/m', '', rtrim($clean_code, " \r\n")))); // Escape HTML tags; remove trailing new lines
			$clean_code = preg_replace('/^(<br><br>)/', '', preg_replace('/(<br><br>)$/', '', preg_replace('/(<br>){2,}/i', '<br><br>', $clean_code))); // Remove repetitive and trailing HTML <br> tags

			// HTML tags to automatically close
			$tags = ['code', 'b', 'i', 'u', 's', 'li', 'pre', 'blockquote', 'ul', 'ol'];
			$cleantags = ['blockquote', 'ul', 'ol'];

			// Check if all allowed HTML tags have been closed, if not add them at the end
			foreach ($tags as $tag) {
				$without_code = strtolower(preg_replace('/<code>.*?<\/code>/i', '', $clean_code));
				$open_tags = substr_count($without_code, '<' . $tag . '>');
				$close_tags = substr_count($without_code, '</' . $tag . '>');

				while ($open_tags > $close_tags) {
					$clean_code .= '</' . $tag . '>';
					$close_tags++;
				}

				while ($close_tags > $open_tags) {
					$clean_code = preg_replace('/<\/' . $tag . '>/i', '', $clean_code, 1);
					$close_tags--;
				}

				if (in_array($tag, $cleantags, true)) {
					$clean_code = str_ireplace(['<' . $tag . '><br>', '</' . $tag . '><br>'], ['<' . $tag . '>\n', '</' . $tag . '>\n'], $clean_code);
				}
			}

			$clean_code = str_ireplace(['<code><br>', '<br></code>'], ['<code>', '</code>'], $clean_code);
			$clean_code = str_ireplace(['<pre><br>', '<br></pre>'], ['<pre>', '</pre>'], $clean_code);
			$clean_code = preg_replace_callback('/(<code>)(.*?)(<\/code>){1,}/i', fn (array $arr): string => '<code style="white-space: pre;">' . str_ireplace('&lt;br&gt;', '<br>', htmlspecialchars(preg_replace('/(<br>){1,}<img.*?title="(.*?)".*?>(<br>){1,}/', '$2', preg_replace('/<\/?a(\s+.*?>|>)/', '', $arr[2])), ENT_NOQUOTES, 'UTF-8', false)) . $arr[3], $clean_code);
			$clean_code = preg_replace_callback('/(<pre>)(.*?)(<\/pre>){1,}/i', fn (array $arr): string => $arr[1] . preg_replace('/(<br>){1,}<img.*?title="(.*?)".*?>(<br>){1,}/', '$2', $arr[2]) . $arr[3], $clean_code);
			$clean_code = str_replace(['<blockquote>\n<br>', '<br><br></blockquote>'], ['<blockquote>\n', '\n</blockquote>'], $clean_code);
			$clean_code = str_ireplace('</li><br>', '</li>\n', $clean_code);

			// Open comment template; prepare data
			$write_cmt = load_comment('template.xml') ?? hashover_error('file "template.xml" could not be read');
			$write_cmt->name = xml_sanitize(trim($name));
			$write_cmt->passwd = hash_password($posted_password);
			$write_cmt->login = ($posted_password !== '') ? login_verifier(login_token(trim($name), $posted_password)) : '';
			$write_cmt->email = encrypt_email(xml_sanitize($email));
			$write_cmt->website = xml_sanitize($website);
			$write_cmt->date = date('m/d/Y - g:ia');
			$write_cmt['likes'] = '0';
			$write_cmt['notifications'] = 'yes';
			$write_cmt['ipaddr'] = ($ip_addrs === 'yes') ? (string) ($_SERVER['REMOTE_ADDR'] ?? '') : '';
			$write_cmt->body = xml_sanitize($clean_code); // Final comment body

			// Edit comment
			if (isset($_POST['edit'])) {
				$edit_file = $dir . '/' . $cmtfile . '.xml';

				if ($cmtfile !== '' && ($edit_cmt = load_comment($edit_file)) !== null && may_modify_comment($edit_cmt, $posted_password)) {
					$acting_admin = is_admin() || is_admin_password($posted_password);

					// Only the administrator may use the administrator's nickname
					if (!is_admin_name((string) $write_cmt->name) || $acting_admin) {
						$edit_cmt->name = (string) $write_cmt->name;
					}

					if (!$acting_admin) {
						$edit_cmt->email = (string) $write_cmt->email;
					}

					$edit_cmt->website = (string) $write_cmt->website;
					$edit_cmt['notifications'] = (post('notify') === 'on') ? 'yes' : 'no';

					// A new password given by the commenter replaces the old one
					if ($posted_password !== '' && !is_admin_password($posted_password)) {
						$edit_cmt->passwd = (string) $write_cmt->passwd;
						$edit_cmt->login = (string) $write_cmt->login;
					}

					$edit_cmt->body = (string) $write_cmt->body;
					save_comment($edit_cmt, $edit_file);

					// Set "Login" cookie
					if (!$acting_admin && $posted_password !== '') {
						set_cookie('hashover-login', login_token((string) $edit_cmt->name, $posted_password), $expire);
					}
				}

				// Kick visitor back to comment
				kick_back(($cmtfile !== '') ? 'c' . str_replace('-', 'r', $cmtfile) : 'comments');
			}

			// Read comments without output
			if (is_dir($dir)) {
				read_comments($dir, 'no');
			}

			// Get file name for reply or new comment
			$reply_dir = $dir . '/' . $reply_to . '.xml';
			$is_reply = $reply_to !== '' && file_exists($reply_dir);

			if ($reply_to !== '' && !$is_reply) {
				kick_back('comments');
			}

			$file_id = $is_reply ? $reply_to . '-' . max(1, (int) ($subfile_count[$reply_dir] ?? 1)) : (string) $cmt_count;

			// Write comment to file
			if (($cmt_file = write_new_comment($write_cmt, $dir, $file_id)) !== null) {
				// Send notification e-mails
				$permalink = 'c' . str_replace('-', 'r', basename($cmt_file, '.xml'));
				$mail_name = str_replace(["\r", "\n"], '', html_entity_decode((string) $write_cmt->name, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
				$from_email = ($email !== '' && $user_reply === 'yes') ? $mail_name . ' <' . $email . '>' : $mail_name;
				$reverse_datasearch = ['&quot;', '&lt;', '&gt;', '<br>\n', '\n', '<br>', '&nbsp;', '\r'];
				$reverse_datareplace = ['"', '<', '>', PHP_EOL, PHP_EOL, "\r", '  ', "\r"];
				$mail_url = str_replace(["\r", "\n"], '', $page_url);
				$to_webmaster = '';

				// Notify commenter of reply
				if ($is_reply && ($get_cmt = load_comment($reply_dir)) !== null) {
					$to_commenter = "\nIn reply to:\n\n\t" . str_replace($reverse_datasearch, $reverse_datareplace, strip_tags((string) $get_cmt->body)) . "\n\n";
					$to_webmaster = "\nIn reply to " . $get_cmt->name . ":\n\n\t" . str_replace($reverse_datasearch, $reverse_datareplace, strip_tags((string) $get_cmt->body)) . "\n\n";
					$decryto = decrypt_email((string) $get_cmt->email);

					if ($decryto !== '' && !same_email($decryto, $notification_email) && !same_email($decryto, $email) && (string) $get_cmt['notifications'] === 'yes') {
						$reply_header = ($user_reply === 'yes') ? $header : "From: $noreply_email\r\nReply-To: $noreply_email";
						mail($decryto, $domain . ' - New Reply', "From $from_email:\n\n\t" . strip_tags($comment) . "\n\n$to_commenter----\nPermalink: $mail_url" . '#' . $permalink . "\nPage: $mail_url", $reply_header);
					}
				}

				// Notify webmaster via e-mail
				if (!same_email($email, $notification_email)) {
					mail($notification_email, 'New Comment', "From $from_email:\n\n\t" . str_replace($reverse_datasearch, $reverse_datareplace, strip_tags($clean_code)) . "\n\n$to_webmaster----\nPermalink: $mail_url" . '#' . $permalink . "\nPage: $mail_url", $header);
				}

				// Set blank cookie for successful comment, kick visitor back to comment
				clear_cookie('replied');

				if (is_admin_name($name) && is_admin_password($posted_password)) {
					set_cookie('hashover-login', admin_token(), $expire);
				} elseif ($posted_password !== '') {
					set_cookie('hashover-login', login_token($name, $posted_password), $expire);
				}

				kick_back($permalink);
			}

			set_cookie('message', $text['post_fail'], $expire);
			kick_back('comments');
		}

		// Set failed comment cookie
		set_cookie('success', 'no', $expire);

		// Set message cookie to comment or reply requirement notice
		if ($reply_to !== '') {
			set_cookie('replied', $reply_to, $expire);
			set_cookie('message', $text['reply_needed'], $expire);
		} else {
			set_cookie('message', $text['cmt_needed'], $expire);
		}

		// Kick visitor back to comment form
		kick_back('comments');
	}
