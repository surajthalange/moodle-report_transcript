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
 * The transcript page: your own, or another user's with the capability.
 *
 * @package    report_transcript
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use report_transcript\local\access;
use report_transcript\local\settings;

require(__DIR__ . '/../../config.php');

$userid = optional_param('userid', 0, PARAM_INT);

require_login(null, false);
if ($userid === 0) {
    $userid = (int) $USER->id;
}
access::require_view($userid);

$user = core_user::get_user($userid, '*', MUST_EXIST);
core_user::require_active_user($user);

$settings = settings::from_config();
$context = context_user::instance($userid);

$PAGE->set_url(new moodle_url('/report/transcript/index.php', ['userid' => $userid]));
$PAGE->set_context($context);
$PAGE->set_pagelayout('report');
$PAGE->set_title($settings->documenttitle);
$PAGE->set_heading(get_string('transcriptfor', 'report_transcript', fullname($user)));

echo $OUTPUT->header();
echo $OUTPUT->heading($settings->documenttitle);
echo $OUTPUT->footer();
