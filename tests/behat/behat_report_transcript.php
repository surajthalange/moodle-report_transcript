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

// phpcs:disable moodle.NamingConventions.ValidVariableName.VariableNameLowerCase

use report_transcript\local\issuer;
use report_transcript\local\settings;
use report_transcript\local\transcript_builder;

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

/**
 * Steps for what core's generators do not cover: completing a course, and issuing.
 *
 * @package    report_transcript
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_report_transcript extends behat_base {
    /**
     * Mark a course complete for a user, and make sure the course total is aggregated.
     *
     * @Given the user :username completed the course :shortname on :date
     * @param string $username
     * @param string $shortname
     * @param string $date anything strtotime() reads; noon is used so timezones cannot move the day
     */
    public function the_user_completed_the_course_on(string $username, string $shortname, string $date): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/completionlib.php');
        require_once($CFG->libdir . '/gradelib.php');

        $user = $DB->get_record('user', ['username' => $username], '*', MUST_EXIST);
        $course = $DB->get_record('course', ['shortname' => $shortname], '*', MUST_EXIST);
        $time = strtotime($date . ' 12:00');

        $cc = new completion_completion(['course' => $course->id, 'userid' => $user->id]);
        $cc->mark_inprogress($time - WEEKSECS * 8);
        $cc->mark_complete($time);

        // Grades created by the generator are not aggregated into the course total until
        // something asks for it; a transcript reads the total directly, so ask now.
        grade_regrade_final_grades($course->id);
    }

    /**
     * Issue a transcript with a known code, so the verify page can be tested.
     *
     * @Given a transcript has been issued for :username with code :code
     * @param string $username
     * @param string $code
     */
    public function a_transcript_has_been_issued_for_with_code(string $username, string $code): void {
        global $DB;

        $user = $DB->get_record('user', ['username' => $username], '*', MUST_EXIST);
        $settings = settings::from_config();
        $transcript = (new transcript_builder($settings))->build($user);
        $issue = (new issuer())->issue($transcript, $settings, (int) $user->id);
        $DB->set_field(issuer::TABLE, 'code', issuer::normalise_code($code), ['id' => $issue->id]);
    }
}
