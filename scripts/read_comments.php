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


	// Read, count, and create deleted comment note
	function read_comments(string $dir, string $check): void
	{
		global $show_cmt, $cmt_count, $total_count, $subfile_count, $deleted_files;

		$files = [];

		// Read directory contents, put filenames in array, count files
		foreach (glob($dir . '/*.xml', GLOB_NOSORT) ?: [] as $file) {
			$file_id = basename($file, '.xml');

			if (!is_comment_id($file_id)) {
				continue;
			}

			$files[$file_id] = $file;
			$subfile_count[$file] = 0;
			$total_count++;

			if (!str_contains($file_id, '-')) {
				$cmt_count++;
			}
		}

		// Sort files ascending alphabetically
		uksort($files, 'strnatcasecmp');

		foreach ($files as $file) {
			$file_id = basename($file, '.xml');
			$cmt_tree = '';

			foreach (explode('-', $file_id) as $reply) {
				for ($i = 1; $i <= (int) $reply; $i++) {
					$expected = $dir . '/' . $cmt_tree . (($cmt_tree !== '') ? '-' : '') . $i . '.xml';

					if (!in_array($expected, $files, true) && !in_array($expected, $deleted_files, true)) {
						deletion_notice($expected, $check); // Display notice
						$deleted_files[] = $expected;
					}
				}

				$cmt_tree .= (($cmt_tree !== '') ? '-' : '') . $reply;
			}

			// Check whether to generate output
			if ($check === 'yes' || $check === 'true') {
				$show_cmt = parse_comments($file, $show_cmt, 'yes');
			}

			// Count comment
			if (str_contains($file_id, '-')) {
				$thread = $dir . '/' . substr($file_id, 0, (int) strrpos($file_id, '-')) . '.xml';
				$subfile_count[$thread] = ($subfile_count[$thread] ?? 0) + 1;
			}

			$subfile_count[$file]++; // Count comment
		}
	}
