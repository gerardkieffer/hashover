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
	//	This script reads a given comment file, retrieves the like count, 
	//	increases the count by one, then writes the file. Assuming the 
	//	visitor hasn't already liked the given comment before and the 
	//	visitor isn't the comment's original poster.


	header('Content-Type: text/plain; charset=UTF-8');
	header('X-Content-Type-Options: nosniff');
	header('Cache-Control: no-store');

	require __DIR__ . '/settings.php';
	require __DIR__ . '/secrets.php';
	require __DIR__ . '/functions.php';
	require __DIR__ . '/encryption.php';

	// Likes change data, so they must be POST requests from this website
	if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || ($domain = clean_domain($domain)) === null || !is_same_origin_request()) {
		http_response_code(403);
		exit('Forbidden');
	}

	if (is_blocked_visitor(false)) {
		http_response_code(403);
		exit('You are blocked!');
	}

	// Expect "thread-directory/comment-id"
	$like = post('like');
	$like_parts = explode('/', $like);

	if (count($like_parts) !== 2 || !is_thread_name($like_parts[0]) || !is_comment_id($like_parts[1])) {
		http_response_code(400);
		exit('Invalid comment');
	}

	$file = 'pages/' . $like_parts[0] . '/' . $like_parts[1] . '.xml';

	if (!is_file($file) || ($handle = fopen($file, 'r+')) === false) {
		http_response_code(404);
		exit('Comment not found');
	}

	// Lock the file so simultaneous likes aren't lost
	flock($handle, LOCK_EX);

	$previous = libxml_use_internal_errors(true);
	$comment = simplexml_load_string((string) stream_get_contents($handle), null, LIBXML_NONET);
	libxml_clear_errors();
	libxml_use_internal_errors($previous);

	// Save the like count and release the file
	$save = function (\SimpleXMLElement $comment) use ($handle): void {
		ftruncate($handle, 0);
		rewind($handle);
		fwrite($handle, (string) $comment->asXML());
		fflush($handle);
	};

	if ($comment === false) {
		flock($handle, LOCK_UN);
		fclose($handle);
		http_response_code(500);
		exit('Comment could not be read');
	}

	$likes = (int) $comment['likes'];

	if (same_email(safe_email(cookie('email')), decrypt_email((string) $comment->email)) || owns_comment($comment)) {
		flock($handle, LOCK_UN);
		fclose($handle);
		exit('Practice altruism!');
	}

	$like_cookie = md5(($_SERVER['SERVER_NAME'] ?? '') . $like);
	$like_expire = time() + 60 * 60 * 24 * 365 * 10;

	if (cookie($like_cookie) !== 'liked') {
		set_cookie($like_cookie, 'liked', $like_expire);
		$comment['likes'] = (string) ++$likes;
		$save($comment);
		$message = $likes . ' likes!';
	} else {
		set_cookie($like_cookie, 'unliked', $like_expire);

		if ($likes > 0) {
			$comment['likes'] = (string) --$likes;
			$save($comment);
		}

		$message = 'Unliked >;)';
	}

	flock($handle, LOCK_UN);
	fclose($handle);
	exit($message);
