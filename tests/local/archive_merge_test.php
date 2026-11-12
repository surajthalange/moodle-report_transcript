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
 * Tests for the read path: live data wins, the archive fills only the gaps, and a code
 * issued before a deletion still resolves afterwards.
 *
 * Metadata stays in doc-comments rather than PHP attributes: attributes arrived in
 * PHPUnit 10 and Moodle 4.5, this plugin's floor, ships PHPUnit ^9.6.34.
 *
 * @package    report_transcript
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \report_transcript\local\transcript_builder
 * @covers     \report_transcript\local\archive
 * @covers     \report_transcript\local\issuer
 */
final class archive_merge_test extends \advanced_testcase {
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
     * A completed course, with completion tracking on.
     *
     * @param array $options course generator options
     * @param int $timecompleted
     * @return \stdClass
     */
    private function completed_course(array $options = [], int $timecompleted = 1700000000): \stdClass {
        $course = $this->getDataGenerator()->create_course($options + ['enablecompletion' => 1]);
        $this->getDataGenerator()->enrol_user($this->user->id, $course->id, 'student');
        $cc = new completion_completion(['course' => $course->id, 'userid' => $this->user->id]);
        $cc->mark_complete($timecompleted);
        return $course;
    }

    /**
     * Build the transcript.
     *
     * @param array $overrides settings overrides
     * @return transcript
     */
    private function build(array $overrides = []): transcript {
        return (new transcript_builder(settings::defaults($overrides)))->build($this->user);
    }

    /**
     * A deleted course keeps its line, with the values it had when it went.
     */
    public function test_a_deleted_course_still_appears(): void {
        $course = $this->completed_course(['fullname' => 'Physics 200', 'shortname' => 'PHY200']);
        $courseid = (int) $course->id;

        $this->assertCount(1, $this->build()->rows);

        delete_course($course, false);

        $rows = $this->build()->rows;
        $this->assertCount(1, $rows, 'the line survives the course');
        $this->assertSame($courseid, $rows[0]->courseid);
        $this->assertSame('Physics 200', $rows[0]->coursename);
        $this->assertSame('PHY200', $rows[0]->shortname);
        $this->assertSame(1700000000, $rows[0]->timecompleted);
        $this->assertSame(grade_presenter::STATUS_COMPLETED, $rows[0]->status);
    }

    /**
     * While a course exists it is the live data that counts.
     *
     * The archived row is deliberately left stale here. If the merge ever preferred it, or
     * added it alongside the live row, this test says so.
     */
    public function test_live_data_wins_over_the_archive(): void {
        global $DB;

        $course = $this->completed_course(['fullname' => 'Original name']);

        // Leave the archive holding something the live course no longer says.
        $DB->set_field(archive::TABLE, 'coursename', 'Stale archived name', ['courseid' => $course->id]);
        $DB->set_field('course', 'fullname', 'Corrected name', ['id' => $course->id]);
        rebuild_course_cache((int) $course->id, true);

        $rows = $this->build()->rows;

        $this->assertCount(1, $rows, 'the archive must not add a second row for a live course');
        $this->assertSame('Corrected name', $rows[0]->coursename);
    }

    /**
     * A course excluded by category stays excluded once it is deleted.
     */
    public function test_an_excluded_category_stays_excluded_after_deletion(): void {
        $category = $this->getDataGenerator()->create_category();
        $course = $this->completed_course(['category' => $category->id]);

        $overrides = ['excludedcategories' => [(int) $category->id]];
        $this->assertSame([], $this->build($overrides)->rows);

        delete_course($course, false);

        $this->assertSame([], $this->build($overrides)->rows, 'deleting the course must not reveal it');
        $this->assertNotEmpty($this->build()->rows, 'and it is still there when nothing excludes it');
    }

    /**
     * A code issued before the deletion still resolves to the document that was issued.
     *
     * This held before the archive existed, because an issue freezes its own snapshot. The
     * test is here so that it keeps holding.
     */
    public function test_a_code_issued_before_deletion_still_verifies(): void {
        $course = $this->completed_course(['fullname' => 'History 101']);
        $settings = settings::defaults();

        $transcript = (new transcript_builder($settings))->build($this->user);
        $issuer = new issuer();
        $issue = $issuer->issue($transcript, $settings, (int) $this->user->id);

        delete_course($course, false);

        $found = $issuer->find_by_code($issue->code);
        $this->assertNotNull($found, 'the code still resolves');

        $restored = $issuer->transcript_from($found);
        $this->assertCount(1, $restored->rows);
        $this->assertSame('History 101', $restored->rows[0]->coursename);
        $this->assertSame('Asha Rao', $restored->fullname);
    }

    /**
     * Two courses, one deleted, come back as one ordered list rather than two groups.
     */
    public function test_live_and_archived_rows_sort_together(): void {
        $older = $this->completed_course(['fullname' => 'Older'], 1600000000);
        $newer = $this->completed_course(['fullname' => 'Newer'], 1700000000);

        delete_course($newer, false);

        $rows = $this->build()->rows;
        $this->assertCount(2, $rows);
        // Completed courses sort by completion date, newest first, whatever their source.
        $this->assertSame('Newer', $rows[0]->coursename);
        $this->assertSame('Older', $rows[1]->coursename);
        $this->assertSame((int) $older->id, $rows[1]->courseid);
    }
}
