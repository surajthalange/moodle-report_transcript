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

namespace report_transcript\local;

use completion_completion;
use grade_item;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->libdir . '/completionlib.php');

/**
 * Tests for transcript_builder: every inclusion, exclusion, date and ordering rule in
 * PRD sections 6.1 and 6.2, asserted against exact rows rather than counts.
 *
 * Metadata stays in doc-comments rather than PHP attributes: attributes arrived in
 * PHPUnit 10 and Moodle 4.5, this plugin's floor, ships PHPUnit ^9.6.34.
 *
 * @package    report_transcript
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \report_transcript\local\transcript_builder
 * @covers     \report_transcript\local\transcript
 * @covers     \report_transcript\local\transcript_row
 */
final class transcript_builder_test extends \advanced_testcase {
    /** @var \stdClass The learner. */
    private \stdClass $user;

    /**
     * One learner; courses are created per test.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->user = $this->getDataGenerator()->create_user(['firstname' => 'Asha', 'lastname' => 'Rao']);
        // mark_complete() notifies the learner; keep that out of the output.
        $this->redirectMessages();
    }

    /**
     * A course, with completion tracking on unless told otherwise.
     *
     * @param array $options course generator options
     * @return \stdClass
     */
    private function course(array $options = []): \stdClass {
        return $this->getDataGenerator()->create_course($options + ['enablecompletion' => 1]);
    }

    /**
     * Enrol the learner and mark the course complete.
     *
     * @param \stdClass $course
     * @param int $timecompleted
     * @param string $role
     * @param int|null $timestarted
     */
    private function complete(\stdClass $course, int $timecompleted, string $role = 'student', ?int $timestarted = null): void {
        $this->getDataGenerator()->enrol_user($this->user->id, $course->id, $role);
        $cc = new completion_completion(['course' => $course->id, 'userid' => $this->user->id]);
        if ($timestarted !== null) {
            $cc->mark_inprogress($timestarted);
        }
        $cc->mark_complete($timecompleted);
    }

    /**
     * Build with the given settings overrides.
     *
     * @param array $overrides
     * @return transcript
     */
    private function build(array $overrides = []): transcript {
        return (new transcript_builder(settings::defaults($overrides)))->build($this->user);
    }

    /**
     * Course ids of the rows, in order.
     *
     * @param transcript $t
     * @return int[]
     */
    private function ids(transcript $t): array {
        return array_map(fn(transcript_row $r) => $r->courseid, $t->rows);
    }

    /**
     * A completed course appears with its dates and a completed outcome.
     */
    public function test_completed_course_appears(): void {
        $course = $this->course(['fullname' => 'Biology 101']);
        $this->complete($course, 1700000000, 'student', 1690000000);

        $t = $this->build();

        $this->assertSame([(int) $course->id], $this->ids($t));
        $row = $t->rows[0];
        $this->assertSame('Biology 101', $row->coursename);
        $this->assertSame(grade_presenter::STATUS_COMPLETED, $row->status);
        $this->assertSame(1690000000, $row->timestarted);
        $this->assertSame(1700000000, $row->timecompleted);
        $this->assertSame(outcome::COMPLETED, $row->grade->outcome);
        $this->assertSame('Asha Rao', $t->fullname);
        $this->assertSame(1, $t->count(grade_presenter::STATUS_COMPLETED));
    }

    /**
     * A completed course that was later hidden still appears: the learner completed it.
     */
    public function test_completed_hidden_course_still_appears(): void {
        $course = $this->course(['visible' => 0]);
        $this->complete($course, 1700000000);

        $this->assertSame([(int) $course->id], $this->ids($this->build()));
    }

    /**
     * An in-progress course appears when the setting is on and not when it is off.
     */
    public function test_in_progress_follows_the_setting(): void {
        $course = $this->course();
        $this->getDataGenerator()->enrol_user($this->user->id, $course->id, 'student');

        $on = $this->build(['includeinprogress' => true]);
        $this->assertSame([(int) $course->id], $this->ids($on));
        $this->assertSame(grade_presenter::STATUS_INPROGRESS, $on->rows[0]->status);
        $this->assertSame(outcome::INPROGRESS, $on->rows[0]->grade->outcome);
        $this->assertNull($on->rows[0]->timecompleted);

        $this->assertSame([], $this->ids($this->build(['includeinprogress' => false])));
    }

    /**
     * An in-progress course the learner cannot see is not listed.
     */
    public function test_in_progress_hidden_course_is_excluded(): void {
        $course = $this->course(['visible' => 0]);
        $this->getDataGenerator()->enrol_user($this->user->id, $course->id, 'student');

        $this->assertSame([], $this->ids($this->build()));
    }

    /**
     * A course without completion tracking appears only when untracked courses are included.
     */
    public function test_untracked_follows_the_setting(): void {
        $course = $this->course(['enablecompletion' => 0]);
        $this->getDataGenerator()->enrol_user($this->user->id, $course->id, 'student');

        $this->assertSame([], $this->ids($this->build()), 'Off by default');

        $on = $this->build(['includeuntracked' => true]);
        $this->assertSame([(int) $course->id], $this->ids($on));
        $this->assertSame(grade_presenter::STATUS_UNTRACKED, $on->rows[0]->status);
        $this->assertSame(outcome::UNTRACKED, $on->rows[0]->grade->outcome);
    }

    /**
     * A suspended enrolment with no completion is not listed.
     */
    public function test_suspended_enrolment_is_excluded(): void {
        $course = $this->course();
        $this->getDataGenerator()->enrol_user($this->user->id, $course->id, 'student', 'manual', 0, 0, ENROL_USER_SUSPENDED);

        $this->assertSame([], $this->ids($this->build(['includeinprogress' => true, 'includeuntracked' => true])));
    }

    /**
     * Courses under an excluded category, including a subcategory, never appear.
     */
    public function test_excluded_category_includes_subcategories(): void {
        $generator = $this->getDataGenerator();
        $parent = $generator->create_category();
        $child = $generator->create_category(['parent' => $parent->id]);
        $elsewhere = $generator->create_category();

        $inparent = $this->course(['category' => $parent->id]);
        $inchild = $this->course(['category' => $child->id]);
        $kept = $this->course(['category' => $elsewhere->id]);
        foreach ([$inparent, $inchild, $kept] as $c) {
            $this->complete($c, 1700000000);
        }

        $t = $this->build(['excludedcategories' => [(int) $parent->id]]);

        $this->assertSame([(int) $kept->id], $this->ids($t));
    }

    /**
     * A course the user teaches does not belong on their transcript.
     */
    public function test_teachers_own_course_is_excluded(): void {
        $taught = $this->course();
        $this->complete($taught, 1700000000, 'editingteacher');

        $this->assertSame([], $this->ids($this->build()));
    }

    /**
     * A user who is both teacher and student in a course is a learner there.
     */
    public function test_user_with_both_roles_is_a_learner(): void {
        $course = $this->course();
        $this->getDataGenerator()->enrol_user($this->user->id, $course->id, 'editingteacher');
        $this->complete($course, 1700000000, 'student');

        $this->assertSame([(int) $course->id], $this->ids($this->build()));
    }

    /**
     * A completed course the learner has since been unenrolled from still appears, and its
     * start date falls back to the completion record.
     */
    public function test_unenrolled_after_completion_still_appears(): void {
        $course = $this->course();
        $this->complete($course, 1700000000, 'student', 1690000000);
        $plugin = enrol_get_plugin('manual');
        $instance = $this->enrol_instance($course);
        $plugin->unenrol_user($instance, $this->user->id);

        $t = $this->build();

        $this->assertSame([(int) $course->id], $this->ids($t));
        $this->assertSame(1690000000, $t->rows[0]->timestarted);
    }

    /**
     * The start date prefers timestarted, then timeenrolled, then the enrolment's start.
     */
    public function test_start_date_precedence(): void {
        $generator = $this->getDataGenerator();

        // Only an enrolment start is known.
        $enrolonly = $this->course();
        $generator->enrol_user($this->user->id, $enrolonly->id, 'student', 'manual', 1650000000);
        $cc = new completion_completion(['course' => $enrolonly->id, 'userid' => $this->user->id]);
        $cc->mark_complete(1700000000);
        // mark_complete() records timeenrolled itself; clear it to isolate the fallback.
        $this->clear_completion_times($enrolonly, ['timeenrolled' => 0, 'timestarted' => 0]);

        // timeenrolled known, timestarted not.
        $enrolled = $this->course();
        $generator->enrol_user($this->user->id, $enrolled->id, 'student');
        $cc = new completion_completion(['course' => $enrolled->id, 'userid' => $this->user->id]);
        $cc->mark_enrolled(1660000000);
        $cc->mark_complete(1700000001);
        $this->clear_completion_times($enrolled, ['timestarted' => 0]);

        // timestarted known and wins.
        $started = $this->course();
        $this->complete($started, 1700000002, 'student', 1670000000);

        $byid = [];
        foreach ($this->build()->rows as $row) {
            $byid[$row->courseid] = $row->timestarted;
        }

        $this->assertSame(1650000000, $byid[(int) $enrolonly->id], 'enrolment start');
        $this->assertSame(1660000000, $byid[(int) $enrolled->id], 'timeenrolled');
        $this->assertSame(1670000000, $byid[(int) $started->id], 'timestarted');
    }

    /**
     * Completed by completion date, newest first; then in progress by start date; then
     * untracked by name.
     */
    public function test_ordering(): void {
        $generator = $this->getDataGenerator();
        $older = $this->course(['fullname' => 'Older']);
        $newer = $this->course(['fullname' => 'Newer']);
        $this->complete($older, 1600000000);
        $this->complete($newer, 1700000000);

        $progressold = $this->course(['fullname' => 'Progress old']);
        $progressnew = $this->course(['fullname' => 'Progress new']);
        $generator->enrol_user($this->user->id, $progressold->id, 'student', 'manual', 1500000000);
        $generator->enrol_user($this->user->id, $progressnew->id, 'student', 'manual', 1550000000);

        $zeta = $this->course(['fullname' => 'Zeta', 'enablecompletion' => 0]);
        $alpha = $this->course(['fullname' => 'Alpha', 'enablecompletion' => 0]);
        $generator->enrol_user($this->user->id, $zeta->id, 'student');
        $generator->enrol_user($this->user->id, $alpha->id, 'student');

        $t = $this->build(['includeinprogress' => true, 'includeuntracked' => true]);

        $this->assertSame(
            [(int) $newer->id, (int) $older->id, (int) $progressnew->id, (int) $progressold->id, (int) $alpha->id, (int) $zeta->id],
            $this->ids($t)
        );
        $this->assertSame(2, $t->count(grade_presenter::STATUS_COMPLETED));
        $this->assertSame(2, $t->count(grade_presenter::STATUS_INPROGRESS));
        $this->assertSame(2, $t->count(grade_presenter::STATUS_UNTRACKED));
    }

    /**
     * The grade on a row comes through the presenter: a passing grade is a pass.
     */
    public function test_row_grade_is_presented(): void {
        $course = $this->course();
        $this->complete($course, 1700000000);
        $item = grade_item::fetch_course_item($course->id);
        $item->gradepass = 50;
        $item->update();
        $grade = new \grade_grade(['itemid' => $item->id, 'userid' => $this->user->id], false);
        $grade->finalgrade = 66;
        $grade->rawgrade = 66;
        $grade->overridden = time();
        $grade->insert();

        $row = $this->build()->rows[0];

        $this->assertSame(outcome::PASS, $row->grade->outcome);
        $this->assertSame('66.00', $row->grade->gradetext);
    }

    /**
     * A transcript survives a round trip through its snapshot.
     */
    public function test_snapshot_round_trip(): void {
        $course = $this->course(['fullname' => 'Chemistry']);
        $this->complete($course, 1700000000, 'student', 1690000000);
        $settings = settings::defaults();
        $original = (new transcript_builder($settings))->build($this->user);

        $rebuilt = transcript::from_snapshot(json_decode(json_encode($original->to_snapshot($settings)), true));

        $this->assertSame($original->fullname, $rebuilt->fullname);
        $this->assertSame($original->documenttitle, $rebuilt->documenttitle);
        $this->assertSame($original->timegenerated, $rebuilt->timegenerated);
        $this->assertEquals($original->rows, $rebuilt->rows);
    }

    /**
     * An empty transcript is empty, not an error.
     */
    public function test_empty_transcript(): void {
        $t = $this->build();

        $this->assertTrue($t->is_empty());
        $this->assertSame(0, $t->count(grade_presenter::STATUS_COMPLETED));
    }

    /**
     * The manual enrolment instance of a course.
     *
     * @param \stdClass $course
     * @return \stdClass
     */
    private function enrol_instance(\stdClass $course): \stdClass {
        global $DB;
        return $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
    }

    /**
     * Overwrite completion timestamps directly, to isolate a fallback.
     *
     * @param \stdClass $course
     * @param array $fields
     */
    private function clear_completion_times(\stdClass $course, array $fields): void {
        global $DB;
        $DB->update_record('course_completions', (object) ($fields + [
            'id' => $DB->get_field('course_completions', 'id', ['course' => $course->id, 'userid' => $this->user->id], MUST_EXIST),
        ]));
    }
}
