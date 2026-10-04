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


	// Function for adding deletion notice to output
	function deletion_notice(string $file, string $check): void
	{
		global $dir, $show_cmt, $indention, $root_dir, $mode, $text, $subfile_count, $total_count, $cmt_count, $deleted_cmt, $deleted_total, $icons, $icon_size;

		// Extensionless file basename
		$file_basename = basename($file, '.xml');
		$is_reply = str_contains($file_basename, '-');

		// Check whether to generate output
		if ($check === 'yes' || $check === 'true') {
			// Generate permalink
			$del_permatext_parts = explode('-', $file_basename);
			$del_permatext = end($del_permatext_parts);
			$del_permalink = 'c' . str_replace('-', 'r', $file_basename);

			// Calculate CSS padding for reply indention
			$del_dashes = substr_count($file_basename, '-');
			$del_indent = ($del_dashes > 0 && $check === 'yes') ? (((int) $icon_size + 4) * $del_dashes) + 16 : 0;

			if ($icons === 'yes') {
				$icon_fmt = '<img width="' . (int) $icon_size . '" height="' . (int) $icon_size . '" src="' . $root_dir . 'images/delicon.png" alt="#' . $del_permatext . '" align="left">';
			} else {
				$icon_fmt = '<a rel="nofollow" href="#' . $del_permalink . '" title="Permalink">#' . $del_permatext . '</a>&nbsp;';
			}

			$entry = [
				'permalink' => $del_permalink,
				'cmtclass' => ($is_reply ? 'cmtdiv reply' : 'cmtdiv') . ' deleted',
				'indent' => ($indention === 'right') ? '16px ' . $del_indent . 'px 12px 0px' : '16px 0px 12px ' . $del_indent . 'px',
				'deletion_notice' => '<span class="cmtnote cmtnumber">' . $icon_fmt . '</span><div style="height: ' . (int) $icon_size . 'px;" class="cmtbubble">' . "\n" . '<b class="cmtnote cmtfont">' . $text['del_note'] . '</b>' . "\n" . '</div>' . "\n"
			];

			if ($mode === 'php') {
				$show_cmt = is_array($show_cmt) ? $show_cmt : [];
				$show_cmt[] = $entry;
			} else {
				$show_cmt .= "\t" . js_value($entry) . ',' . PHP_EOL . PHP_EOL;
			}

			if ($is_reply) {
				$deleted_total++;
				$total_count++;
			}
		}

		// Count deleted comment
		if ($is_reply) {
			$thread = $dir . '/' . substr($file_basename, 0, (int) strrpos($file_basename, '-')) . '.xml';
			$subfile_count[$thread] = ($subfile_count[$thread] ?? 0) + 1;
		} else {
			$deleted_total++;
			$deleted_cmt++;

			$total_count++;
			$cmt_count++;
		}
	}
