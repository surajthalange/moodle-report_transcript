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
 * Issue a transcript and stream it as a PDF.
 *
 * Every download issues afresh: a new code and a new snapshot. The PDF is never written
 * to disk; the snapshot is the record.
 *
 * @package    report_transcript
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use report_transcript\event\transcript_issued;
use report_transcript\local\access;
use report_transcript\local\issuer;
use report_transcript\local\pdf_renderer;
use report_transcript\local\settings;
use report_transcript\local\transcript_builder;

require(__DIR__ . '/../../config.php');

$userid = optional_param('userid', 0, PARAM_INT);

require_login(null, false);
if ($userid === 0) {
    $userid = (int) $USER->id;
}
access::require_view($userid);

$user = core_user::get_user($userid, '*', MUST_EXIST);
core_user::require_active_user($user);

$context = context_user::instance($userid);
$PAGE->set_url(new moodle_url('/report/transcript/pdf.php', ['userid' => $userid]));
$PAGE->set_context($context);

$settings = settings::from_config();
$transcript = (new transcript_builder($settings))->build($user);
$issue = (new issuer())->issue($transcript, $settings, (int) $USER->id);

transcript_issued::create([
    'context' => $context,
    'objectid' => $issue->id,
    'relateduserid' => $userid,
    'other' => ['code' => $issue->code, 'rows' => count($transcript->rows)],
])->trigger();

// No page is ever rendered here, so $OUTPUT is still core's bootstrap placeholder;
// ask for the real renderer.
$bytes = (new pdf_renderer($settings, $PAGE->get_renderer('core')))->render($transcript, $issue, $user);

$filename = get_string('pdf:filename', 'report_transcript', (object) [
    'username' => clean_filename($user->username),
    'code' => $issue->code,
]);

send_file($bytes, $filename, 0, 0, true, true, 'application/pdf');
