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


	// Set Canonical URL; only accept URLs on this website
	if (!isset($canon_url) && $script_query) {
		$canon_url = post('canon_url') !== '' ? post('canon_url') : query('canon_url');

		if ($canon_url !== '' && !preg_match('/^https?:\/\//i', $canon_url)) {
			$canon_url = 'http://' . $canon_url;
		}

		if ($canon_url === '' || safe_url($canon_url) === '' || !is_own_url($canon_url)) {
			unset($canon_url);
		}
	}

	// Comment permalinks look like "c1", "c1r2", and "c1r2_pop"
	function is_permalink(string $permalink): bool
	{
		return preg_match('/^c[1-9]\d{0,5}(?:r[1-9]\d{0,5}){0,30}(?:_pop)?$/', $permalink) === 1;
	}

	// Get full page URL or Canonical URL
	if ($mode === 'javascript') {
		if (!empty($_SERVER['HTTP_REFERER']) && !isset($_GET['rss'])) {
			// Check if the script was requested by this server
			if (!is_own_url((string) $_SERVER['HTTP_REFERER'])) {
				hashover_error('External use not allowed.');
			}

			$page_url = $canon_url ?? (string) $_SERVER['HTTP_REFERER'];
		} else {
			if (!isset($_GET['rss'])) {
				hashover_error('No way to get page URL, HTTP referrer not set.');
			}

			$page_url = query('rss');
		}
	} else {
		if (empty($canon_url)) {
			$page_url = request_scheme() . '://' . $domain . ($_SERVER['REQUEST_URI'] ?? '/');
		} else {
			$page_url = $canon_url;

			if (is_permalink(query('hashover_reply'))) {
				$page_url .= '?hashover_reply=' . query('hashover_reply');
			} elseif (is_permalink(query('hashover_edit'))) {
				$page_url .= '?hashover_edit=' . query('hashover_edit');
			}
		}
	}

	// Set URL to "count_link" query value
	if ($script_query && query('count_link') !== '') {
		$page_url = query('count_link');
	}

	// Characters that aren't allowed in directory names
	$reserved_characters = ['<', '>', ':', '"', '/', '\\', '|', '?', '&', '!', '*', '.', '=', '_', '+', ' '];

	// Clean URL for comment thread directory name
	$parse_url = parse_url($page_url); // Turn page URL into array

	if (!is_array($parse_url)) {
		hashover_error('Invalid page URL.');
	}

	$parse_url['path'] ??= '/';
	$ref_path = ($parse_url['path'] === '/') ? 'index' : str_replace($reserved_characters, '-', substr($parse_url['path'], 1));
	$ref_queries = isset($parse_url['query']) ? explode('&', $parse_url['query']) : [];
	$ignore_queries = ['hashover_reply', 'hashover_edit'];
	$parse_url['query'] = '';

	// Remove unwanted URL queries
	if (is_readable('./ignore_queries.txt')) {
		$ignore_queries = array_merge($ignore_queries, array_filter(array_map('trim', file('./ignore_queries.txt', FILE_IGNORE_NEW_LINES) ?: [])));
	}

	foreach ($ref_queries as $q => $ref_query) {
		if ($ref_query !== '' && !in_array($ref_query, $ignore_queries, true)) {
			$query_name = explode('=', $ref_query, 2)[0];

			if (!in_array($query_name, $ignore_queries, true)) {
				$parse_url['query'] .= ($q > 0 && $parse_url['query'] !== '') ? '&' . $ref_query : $ref_query;
			}
		}
	}

	// Append URL query to path
	if ($parse_url['query'] !== '') {
		$ref_path .= '-' . str_replace($reserved_characters, '-', $parse_url['query']);
	}

	// Only keep safe file name characters; remove multiple dashes
	$ref_path = (string) preg_replace(['/[^A-Za-z0-9%~@,;()-]/', '/-{2,}/'], ['-', '-'], $ref_path);

	// Remove leading and trailing dashes
	$ref_path = trim($ref_path, '-');

	// Keep directory names within file system limits
	if (strlen($ref_path) > 200) {
		$ref_path = substr($ref_path, 0, 160) . '-' . substr(hash('sha256', $ref_path), 0, 16);
	}

	// Page comments directory
	if ($ref_path !== 'hashover-php' && is_thread_name($ref_path)) {
		$dir = 'pages/' . $ref_path;
	} else {
		hashover_error('Failure setting comment directory name');
	}
