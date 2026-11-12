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

namespace report_transcript\task;

use completion_completion;
use core\task\manager;
use report_transcript\local\archive;
use report_transcript\local\backfill;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/completionlib.php');

/**
 * Tests for the ad hoc backfill task.
 *
 * The task's own job, beyond calling the backfill, is to decide whether more work remains
 * and to queue itself again if it does. Getting that wrong either stops the backfill early,
 * leaving completions unarchived and still at risk, or queues for ever. Both are tested
 * here against the real task queue rather than a stub.
 *
 * Metadata stays in doc-comments rather than PHP attributes: attributes arrived in
 * PHPUnit 10 and Moodle 4.5, this plugin's floor, ships PHPUnit ^9.6.34.
 *
 * @package    report_transcript
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \report_transcript\task\backfill_archive
 */
final class backfill_archive_test extends \advanced_testcase {
    /** @var \stdClass The learner who completes everything. */
    private \stdClass $user;

    /**
     * One learner, reused across every course.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->user = $this->getDataGenerator()->create_user();
        // Marking a course complete notifies the learner; keep that out of the output.
        $this->redirectMessages();
    }

    /**
     * Create courses the learner has completed, without letting the observer archive them,
     * which is the state a site is in immediately after upgrading.
     *
     * @param int $count how many courses to create
     * @return int[] the course ids, in creation order
     */
    private function completed_courses(int $count): array {
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
            $this->getDataGenerator()->enrol_user($this->user->id, $course->id, 'student');
            (new completion_completion([
                'course' => $course->id,
                'userid' => $this->user->id,
            ]))->mark_complete(1700000000);
            $ids[] = (int) $course->id;
        }

        // The observer has already archived these. Clear them so the task has real work,
        // which is what a site upgrading from an earlier version actually looks like.
        archive::delete_for_user((int) $this->user->id);

        return $ids;
    }

    /**
     * How many backfill tasks are sitting in the queue.
     *
     * @return int
     */
    private function queued(): int {
        global $DB;
        return $DB->count_records('task_adhoc', ['classname' => '\\' . backfill_archive::class]);
    }

    /**
     * Run the task and swallow its progress output.
     *
     * @param backfill_archive $task
     */
    private function run_task(backfill_archive $task): void {
        ob_start();
        $task->execute();
        ob_end_clean();
    }

    /**
     * Work that fits in one batch is finished in one run, and nothing is queued after it.
     */
    public function test_a_short_batch_finishes_and_queues_nothing(): void {
        global $DB;

        $ids = $this->completed_courses(3);
        $this->assertSame(0, $DB->count_records(archive::TABLE), 'Nothing archived before the task runs');

        $this->run_task(new backfill_archive());

        $this->assertSame(3, $DB->count_records(archive::TABLE), 'Every completion archived');
        $this->assertSame(0, $this->queued(), 'A finished backfill must not queue itself again');

        foreach ($ids as $courseid) {
            $this->assertTrue(
                $DB->record_exists(archive::TABLE, ['userid' => $this->user->id, 'courseid' => $courseid]),
                "Course {$courseid} archived"
            );
        }
    }

    /**
     * A full batch means there may be more, so the task queues a successor that starts
     * after the last course it handled. Running that successor finishes the job and stops.
     */
    public function test_a_full_batch_queues_a_successor_that_finishes_the_job(): void {
        global $DB;

        // One more than a batch, so the first run fills a batch exactly and a second is needed.
        $ids = $this->completed_courses(backfill::BATCH + 1);

        $this->run_task(new backfill_archive());

        $this->assertSame(backfill::BATCH, $DB->count_records(archive::TABLE), 'First run archived one batch');
        $this->assertSame(1, $this->queued(), 'A full batch queues exactly one successor');

        // The successor must resume after the last course of the first batch, otherwise it
        // either repeats work for ever or skips the remainder.
        $queued = manager::get_adhoc_tasks('\\' . backfill_archive::class);
        $this->assertCount(1, $queued);
        $successor = reset($queued);
        $data = (array) $successor->get_custom_data();
        $this->assertSame($ids[backfill::BATCH - 1], (int) $data['after'], 'Resumes after the last course done');

        // Running it must finish the remaining course and stop queueing.
        $DB->delete_records('task_adhoc', ['classname' => '\\' . backfill_archive::class]);
        $this->run_task($successor);

        $this->assertSame(
            backfill::BATCH + 1,
            $DB->count_records(archive::TABLE),
            'Every completion archived once the successor has run'
        );
        $this->assertSame(0, $this->queued(), 'The finished successor queues nothing further');
    }

    /**
     * Running the task twice over the same courses changes nothing. Cron can retry a task,
     * so a second pass must be harmless rather than doubling rows.
     */
    public function test_running_twice_changes_nothing(): void {
        global $DB;

        $this->completed_courses(3);

        $this->run_task(new backfill_archive());
        $first = $DB->get_records(archive::TABLE, null, 'courseid ASC', 'courseid, userid, timecompleted');

        $this->run_task(new backfill_archive());
        $second = $DB->get_records(archive::TABLE, null, 'courseid ASC', 'courseid, userid, timecompleted');

        $this->assertSame(3, count($second), 'Still one row per course');
        $this->assertEquals($first, $second, 'A second run leaves the same rows');
    }
}
