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
 * Verify a transcript by its code. Public on purpose: the code is what a third party holds.
 *
 * Deliberately no require_login(), so the page stays reachable even on sites that force
 * login elsewhere. It reveals the learner's name and rows to anyone with the code, which
 * is the point of a verification code and is stated on the PDF that carries it.
 *
 * @package    report_transcript
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use report_transcript\local\issuer;
use report_transcript\local\rate_limiter;
use report_transcript\local\settings;
use report_transcript\output\transcript_page;

// Public by design; see the file comment.
require(__DIR__ . '/../../config.php'); // phpcs:ignore moodle.Files.RequireLogin.Missing

$code = optional_param('code', '', PARAM_RAW_TRIMMED);

$context = context_system::instance();
$PAGE->set_url(new moodle_url('/report/transcript/verify.php'));
$PAGE->set_context($context);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('verify:title', 'report_transcript'));
$PAGE->set_heading(get_string('verify:title', 'report_transcript'));

$data = [
    'formurl' => (new moodle_url('/report/transcript/verify.php'))->out(false),
    'code' => $code,
    'searched' => $code !== '',
    'limited' => false,
    'found' => false,
    'foundtext' => '',
    'transcript' => null,
];

if ($code !== '') {
    if (!rate_limiter::allow(getremoteaddr())) {
        $data['limited'] = true;
    } else {
        $issuer = new issuer();
        $issue = $issuer->find_by_code($code);
        if ($issue) {
            $transcript = $issuer->transcript_from($issue);
            $settings = settings::from_config();
            $data['found'] = true;
            $data['code'] = issuer::format_code($issue->code);
            $data['foundtext'] = get_string('verify:found', 'report_transcript', (object) [
                'date' => userdate((int) $issue->timecreated, get_string('strftimedate', 'langconfig')),
                'name' => $transcript->fullname,
            ]);
            $page = new transcript_page($transcript, $settings, false, null, [], null, false);
            // Before header() runs, $OUTPUT is still the bootstrap placeholder.
            $data['transcript'] = $page->export_for_template($PAGE->get_renderer('core'));
        }
    }
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('report_transcript/verify', $data);
echo $OUTPUT->footer();
