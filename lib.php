<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Callbacks.
 *
 * @package    report_transcript
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use report_transcript\local\access;
use report_transcript\local\archive;

/**
 * Add a "Transcript" link to a user's profile page, when the viewer may see it.
 *
 * @param core_user\output\myprofile\tree $tree
 * @param stdClass $user the profile being viewed
 * @param bool $iscurrentuser
 * @param stdClass|null $course
 */
function report_transcript_myprofile_navigation(core_user\output\myprofile\tree $tree, $user, $iscurrentuser, $course) {
    if (!access::can_view((int) $user->id)) {
        return;
    }
    $url = new moodle_url('/report/transcript/index.php', ['userid' => $user->id]);
    $node = new core_user\output\myprofile\node(
        'reports',
        'transcript',
        get_string('navigationlink', 'report_transcript'),
        null,
        $url
    );
    $tree->add_node($node);
}

/**
 * Serve the institution logo uploaded through the settings page.
 *
 * @param stdClass|null $course
 * @param stdClass|null $cm
 * @param context $context
 * @param string $filearea
 * @param array $args
 * @param bool $forcedownload
 * @param array $options
 * @return bool false if the file is not found
 */
function report_transcript_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    if ($context->contextlevel !== CONTEXT_SYSTEM || $filearea !== 'institutionlogo') {
        return false;
    }

    $fs = get_file_storage();
    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';
    $file = $fs->get_file($context->id, 'report_transcript', $filearea, 0, $filepath, $filename);
    if (!$file || $file->is_directory()) {
        return false;
    }

    // The logo is public: it is printed on documents that leave the site.
    send_stored_file($file, DAYSECS, 0, $forcedownload, $options);
    return true;
}

/**
 * Archive every completion in a course while the course still exists.
 *
 * Core calls this from delete_course() before anything is removed, so the completion
 * records and the gradebook are still intact and the grades captured here are the last
 * ones the course ever had.
 *
 * Failures are deliberately not swallowed. If this throws, the deletion stops and the
 * administrator sees why, which is recoverable: fix the problem and delete again. Catching
 * the error would let the course go while the transcripts it belonged to quietly lost a
 * line, and that silent loss is the exact failure this archive exists to prevent.
 *
 * @param stdClass $course the course about to be deleted
 */
function report_transcript_pre_course_delete(stdClass $course) {
    archive::capture_course((int) $course->id);
}
