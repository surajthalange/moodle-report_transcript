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

use report_transcript\event\transcript_viewed;
use report_transcript\local\access;
use report_transcript\local\issuer;
use report_transcript\local\settings;
use report_transcript\local\transcript_builder;
use report_transcript\output\transcript_page;

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
$isother = access::is_viewing_other($userid);

$PAGE->set_url(new moodle_url('/report/transcript/index.php', ['userid' => $userid]));
$PAGE->set_context($context);
$PAGE->set_pagelayout('report');
$PAGE->set_title($settings->documenttitle);
$PAGE->set_heading(fullname($user));
if ($isother) {
    $PAGE->navbar->add(fullname($user), new moodle_url('/user/profile.php', ['id' => $userid]));
}
$PAGE->navbar->add(get_string('navigationlink', 'report_transcript'));

$transcript = (new transcript_builder($settings))->build($user);

transcript_viewed::create([
    'context' => $context,
    'relateduserid' => $userid,
])->trigger();

$page = new transcript_page(
    $transcript,
    $settings,
    $isother,
    new moodle_url('/report/transcript/pdf.php', ['userid' => $userid]),
    (new issuer())->issued_for($userid),
    null,
    false, // The page header already carries the learner's name.
);

echo $OUTPUT->header();
echo $OUTPUT->heading($settings->documenttitle);
echo $OUTPUT->render_from_template('report_transcript/transcript', $page->export_for_template($OUTPUT));
echo $OUTPUT->footer();
