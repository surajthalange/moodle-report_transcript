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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/completionlib.php');

/**
 * Tests for the archive: how rows get there, what keeps them out, and what removes them.
 *
 * Metadata stays in doc-comments rather than PHP attributes: attributes arrived in
 * PHPUnit 10 and Moodle 4.5, this plugin's floor, ships PHPUnit ^9.6.34.
 *
 * @package    report_transcript
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \report_transcript\local\archive
 * @covers     \report_transcript\observer
 * @covers     \report_transcript\local\backfill
 */
final class archive_test extends \advanced_testcase {
    /** @var \stdClass The learner. */
    private \stdClass $user;

    /**
     * One learner; courses are created per test.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->user = $this->getDataGenerator()->create_user(['firstname' => 'Asha', 'lastname' => 'Rao']);
        // Marking a course complete notifies the learner; keep that out of the output.
        $this->redirectMessages();
    }

    /**
     * A course with completion tracking on.
     *
     * @param array $options course generator options
     * @return \stdClass
     */
    private function course(array $options = []): \stdClass {
        return $this->getDataGenerator()->create_course($options + ['enablecompletion' => 1]);
    }

    /**
     * Enrol a user and mark the course complete for them.
     *
     * @param \stdClass $course
     * @param int $timecompleted
     * @param string $role
     * @param \stdClass|null $user defaults to the learner
     */
    private function complete(\stdClass $course, int $timecompleted, string $role = 'student', ?\stdClass $user = null): void {
        $user = $user ?? $this->user;
        $this->getDataGenerator()->enrol_user($user->id, $course->id, $role);
        $cc = new completion_completion(['course' => $course->id, 'userid' => $user->id]);
        $cc->mark_complete($timecompleted);
    }

    /**
     * The archived rows for the learner, whether or not the course still exists.
     *
     * @param \stdClass|null $user
     * @return array
     */
    private function archived(?\stdClass $user = null): array {
        global $DB;
        return $DB->get_records(archive::TABLE, ['userid' => ($user ?? $this->user)->id]);
    }

    /**
     * Completing a course archives it, without anyone asking.
     */
    public function test_completion_is_archived_by_the_observer(): void {
        $course = $this->course(['fullname' => 'Biology 101', 'shortname' => 'BIO101']);

        $this->complete($course, 1700000000);

        $rows = $this->archived();
        $this->assertCount(1, $rows);
        $row = reset($rows);
        $this->assertSame((int) $course->id, (int) $row->courseid);
        $this->assertSame('Biology 101', $row->coursename);
        $this->assertSame('BIO101', $row->shortname);
        $this->assertSame(1700000000, (int) $row->timecompleted);
        $this->assertSame(grade_presenter::STATUS_COMPLETED, $row->status);
    }

    /**
     * An unfinished course is not a transcript line, so it is not archived either.
     */
    public function test_an_incomplete_course_is_not_archived(): void {
        $course = $this->course();
        $this->getDataGenerator()->enrol_user($this->user->id, $course->id, 'student');

        $this->assertFalse(archive::capture((int) $this->user->id, (int) $course->id));
        $this->assertSame([], $this->archived());
    }

    /**
     * A teacher can hold a completion record. The live transcript keeps that off their own
     * transcript, and the archive has to agree, or deleting the course would put it back.
     */
    public function test_a_teacher_is_not_archived(): void {
        $teacher = $this->getDataGenerator()->create_user();
        $course = $this->course();
        $this->complete($course, 1700000000, 'editingteacher', $teacher);

        $this->assertSame([], $this->archived($teacher));
    }

    /**
     * Deleting a course sweeps every learner in it first.
     */
    public function test_deleting_a_course_archives_everyone_in_it(): void {
        $other = $this->getDataGenerator()->create_user();
        $course = $this->course(['fullname' => 'Chemistry']);
        $this->complete($course, 1700000000);
        $this->complete($course, 1700000500, 'student', $other);

        // Prove the sweep does the work rather than the observer: clear what it wrote.
        global $DB;
        $DB->delete_records(archive::TABLE);

        delete_course($course, false);

        $this->assertCount(1, $this->archived());
        $this->assertCount(1, $this->archived($other));
    }

    /**
     * A completion that was revoked must not come back when the course is deleted.
     */
    public function test_a_revoked_completion_is_forgotten(): void {
        global $DB;

        $course = $this->course();
        $this->complete($course, 1700000000);
        $this->assertCount(1, $this->archived());

        // The administrator removes the completion, as the completion report allows.
        $DB->delete_records('course_completions', ['course' => $course->id, 'userid' => $this->user->id]);

        delete_course($course, false);

        $this->assertSame([], $this->archived());
    }

    /**
     * The sweep refreshes what is already there, so the last grades before deletion win.
     */
    public function test_the_sweep_refreshes_an_existing_row(): void {
        global $DB;

        $course = $this->course(['fullname' => 'Original name']);
        $this->complete($course, 1700000000);

        $existing = $this->archived();
        $before = reset($existing);
        $this->assertSame('Original name', $before->coursename);

        $DB->set_field('course', 'fullname', 'Renamed before deletion', ['id' => $course->id]);
        rebuild_course_cache((int) $course->id, true);

        delete_course($course, false);

        $rows = $this->archived();
        $this->assertCount(1, $rows, 'the row is replaced, not duplicated');
        $this->assertSame('Renamed before deletion', reset($rows)->coursename);
    }

    /**
     * The backfill archives what happened before it existed, and can be run again safely.
     */
    public function test_backfill_is_idempotent(): void {
        global $DB;

        $one = $this->course();
        $two = $this->course();
        $this->complete($one, 1700000000);
        $this->complete($two, 1700000100);

        // Pretend the plugin was installed after these completions happened.
        $DB->delete_records(archive::TABLE);

        $first = backfill::run();
        $this->assertSame(2, $first['courses']);
        $this->assertSame(2, $first['rows']);
        $this->assertTrue($first['done']);
        $this->assertCount(2, $this->archived());

        $second = backfill::run();
        $this->assertSame(2, $second['rows']);
        $this->assertCount(2, $this->archived(), 'a second run writes no extra rows');
    }

    /**
     * The backfill reports where it stopped so a caller can carry on from there.
     */
    public function test_backfill_resumes(): void {
        global $DB;

        $one = $this->course();
        $two = $this->course();
        $this->complete($one, 1700000000);
        $this->complete($two, 1700000100);
        $DB->delete_records(archive::TABLE);

        $first = backfill::run(0, 1);
        $this->assertSame(1, $first['courses']);
        $this->assertFalse($first['done']);
        $this->assertCount(1, $this->archived());

        $second = backfill::run($first['lastcourseid']);
        $this->assertTrue($second['done']);
        $this->assertCount(2, $this->archived());
    }

    /**
     * Deleting a learner's data takes their archive with it.
     */
    public function test_delete_for_user(): void {
        $course = $this->course();
        $this->complete($course, 1700000000);
        $this->assertCount(1, $this->archived());

        archive::delete_for_user((int) $this->user->id);

        $this->assertSame([], $this->archived());
    }
}
