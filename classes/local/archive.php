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

use core_course_category;
use stdClass;

/**
 * The archive: what a completed course looked like, kept so the line survives the course.
 *
 * The transcript is built from core's own tables, and those tables join to {course}. Delete
 * the course and the line goes with it. Every document already issued is safe, because an
 * issue freezes its own snapshot, but a learner who never downloaded one would lose the
 * record entirely.
 *
 * So each completion is captured here: when it happens, again immediately before the course
 * is deleted so the last known grades win, and in bulk by the backfill for completions that
 * predate this feature. Rows are read back only for courses that no longer exist, which is
 * what keeps a corrected grade in a live course authoritative.
 *
 * Rows outlive the course but belong to the learner, so they live in the learner's user
 * context for privacy purposes, exactly as issued transcripts do.
 *
 * @package    report_transcript
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class archive {
    /** @var string The table. */
    public const TABLE = 'report_transcript_archive';

    /**
     * Capture one learner's completion of one course.
     *
     * @param int $userid the learner
     * @param int $courseid the course, which must still exist
     * @return bool whether a row was written
     */
    public static function capture(int $userid, int $courseid): bool {
        global $DB;

        $course = $DB->get_record('course', ['id' => $courseid]);
        if (!$course || (int) $course->id === SITEID) {
            return false;
        }
        $user = $DB->get_record('user', ['id' => $userid]);
        if (!$user) {
            return false;
        }
        $cc = self::completion_record($userid, $courseid);
        if (!$cc || !$cc->timecompleted) {
            // Only completed courses are archived. An unfinished course was never a line.
            return false;
        }

        $builder = new transcript_builder(settings::from_config());
        if (!$builder->is_learner($courseid, $userid, grade_presenter::STATUS_COMPLETED)) {
            // The same test the live transcript makes. A completion record alone does not
            // put a course on someone's transcript if the gradebook would not call them a
            // learner there, and the archive must not disagree with the page it stands in for.
            return false;
        }
        $row = $builder->row_for(
            $user,
            $course,
            grade_presenter::STATUS_COMPLETED,
            $cc,
            self::enrolment_start($userid, $courseid)
        );

        self::upsert($userid, $course, $row);
        return true;
    }

    /**
     * Capture every learner who has completed this course.
     *
     * Called immediately before the course is deleted, while the completion records and the
     * gradebook are still intact, and by the backfill.
     *
     * @param int $courseid
     * @return int how many rows were written
     */
    public static function capture_course(int $courseid): int {
        global $DB;

        if ($courseid === SITEID) {
            return 0;
        }
        $userids = $DB->get_fieldset_select(
            'course_completions',
            'DISTINCT userid',
            'course = :courseid AND timecompleted IS NOT NULL AND timecompleted > 0',
            ['courseid' => $courseid]
        );

        // A completion that has since been revoked must not survive in the archive. The
        // live transcript already stops showing it; without this the course being deleted
        // would quietly bring it back.
        self::forget_revoked($courseid, array_map('intval', $userids));

        $written = 0;
        foreach ($userids as $userid) {
            if (self::capture((int) $userid, $courseid)) {
                $written++;
            }
        }
        return $written;
    }

    /**
     * The archived rows for a learner, for courses that no longer exist.
     *
     * Only those: a course that still exists is the live transcript's business, whether it
     * ends up on the page or is filtered out of it.
     *
     * @param int $userid
     * @param array<int, true> $excludedcategories category ids the settings exclude
     * @return transcript_row[]
     */
    public static function rows_for(int $userid, array $excludedcategories = []): array {
        global $DB;

        $sql = "SELECT a.*
                  FROM {" . self::TABLE . "} a
                 WHERE a.userid = :userid
                       AND NOT EXISTS (SELECT 1 FROM {course} c WHERE c.id = a.courseid)";
        $records = $DB->get_records_sql($sql, ['userid' => $userid]);

        $rows = [];
        foreach ($records as $record) {
            if (self::is_excluded($record, $excludedcategories)) {
                continue;
            }
            $rows[] = self::to_row($record);
        }
        return $rows;
    }

    /**
     * Remove every archived row for a learner.
     *
     * @param int $userid
     */
    public static function delete_for_user(int $userid): void {
        global $DB;
        $DB->delete_records(self::TABLE, ['userid' => $userid]);
    }

    /**
     * Turn an archived record back into the row the transcript renders.
     *
     * @param stdClass $record
     * @return transcript_row
     */
    public static function to_row(stdClass $record): transcript_row {
        return new transcript_row(
            (int) $record->courseid,
            (string) $record->coursename,
            (string) $record->shortname,
            (string) $record->status,
            (int) $record->timestarted,
            $record->timecompleted === null ? null : (int) $record->timecompleted,
            new grade_presentation(
                (string) $record->gradetext,
                (string) $record->outcome,
                (bool) $record->hidden,
                $record->finalgrade === null ? null : (float) $record->finalgrade,
                (float) $record->gradepass,
                (int) $record->displaytype,
            ),
        );
    }

    /**
     * Write a row, replacing whatever was there for this learner and course.
     *
     * Idempotent on purpose: the completion observer, the sweep before deletion and the
     * backfill all write the same row, and the most recent capture is the one to keep.
     *
     * @param int $userid
     * @param stdClass $course the live course record
     * @param transcript_row $row
     */
    private static function upsert(int $userid, stdClass $course, transcript_row $row): void {
        global $DB;

        $now = time();
        $record = (object) [
            'userid' => $userid,
            'courseid' => (int) $course->id,
            'coursename' => $row->coursename,
            'shortname' => (string) $course->shortname,
            'idnumber' => (string) ($course->idnumber ?? ''),
            'categoryid' => (int) ($course->category ?? 0),
            'categorypath' => self::category_path($course),
            'categoryname' => self::category_name($course),
            'coursestartdate' => (int) ($course->startdate ?? 0),
            'courseenddate' => (int) ($course->enddate ?? 0),
            'status' => $row->status,
            'timestarted' => $row->timestarted,
            'timecompleted' => $row->timecompleted,
            'gradetext' => $row->grade->gradetext,
            'outcome' => $row->grade->outcome,
            'hidden' => $row->grade->hidden ? 1 : 0,
            'finalgrade' => $row->grade->finalgrade,
            'gradepass' => $row->grade->gradepass,
            'displaytype' => $row->grade->displaytype,
            'timemodified' => $now,
        ];

        $existing = $DB->get_record(self::TABLE, ['userid' => $userid, 'courseid' => (int) $course->id], 'id');
        if ($existing) {
            $record->id = $existing->id;
            $DB->update_record(self::TABLE, $record);
            return;
        }
        $record->timecreated = $now;
        $DB->insert_record(self::TABLE, $record);
    }

    /**
     * Whether an archived row falls under a category the settings exclude.
     *
     * Tested against the stored ancestor path rather than the leaf id, because by the time
     * a row is read its categories may have been deleted as well, and an excluded parent
     * has to keep excluding.
     *
     * @param stdClass $record
     * @param array<int, true> $excludedcategories
     * @return bool
     */
    private static function is_excluded(stdClass $record, array $excludedcategories): bool {
        if (!$excludedcategories) {
            return false;
        }
        if (isset($excludedcategories[(int) $record->categoryid])) {
            return true;
        }
        $path = (string) ($record->categorypath ?? '');
        foreach (array_keys($excludedcategories) as $categoryid) {
            if ($path !== '' && str_contains($path, '/' . (int) $categoryid . '/')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Drop archived rows for learners in this course who no longer have a completion.
     *
     * @param int $courseid
     * @param int[] $stillcompleted user ids that currently have a completion
     */
    private static function forget_revoked(int $courseid, array $stillcompleted): void {
        global $DB;

        if (!$stillcompleted) {
            $DB->delete_records(self::TABLE, ['courseid' => $courseid]);
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($stillcompleted, SQL_PARAMS_NAMED, 'u', false);
        $params['courseid'] = $courseid;
        $DB->delete_records_select(self::TABLE, "courseid = :courseid AND userid {$insql}", $params);
    }

    /**
     * Slash delimited ancestor ids ending with the course's own category.
     *
     * @param stdClass $course
     * @return string
     */
    private static function category_path(stdClass $course): string {
        $categoryid = (int) ($course->category ?? 0);
        if (!$categoryid) {
            return '';
        }
        $category = core_course_category::get($categoryid, IGNORE_MISSING, true);
        if (!$category) {
            return '/' . $categoryid . '/';
        }
        $ids = array_merge(array_map('intval', $category->get_parents()), [$categoryid]);
        return '/' . implode('/', $ids) . '/';
    }

    /**
     * The course's category name, formatted, or an empty string when it cannot be resolved.
     *
     * @param stdClass $course
     * @return string
     */
    private static function category_name(stdClass $course): string {
        $categoryid = (int) ($course->category ?? 0);
        if (!$categoryid) {
            return '';
        }
        $category = core_course_category::get($categoryid, IGNORE_MISSING, true);
        return $category ? $category->get_formatted_name() : '';
    }

    /**
     * The completion record for one learner and course.
     *
     * @param int $userid
     * @param int $courseid
     * @return stdClass|null
     */
    private static function completion_record(int $userid, int $courseid): ?stdClass {
        global $DB;

        $record = $DB->get_record(
            'course_completions',
            ['userid' => $userid, 'course' => $courseid],
            'id, timeenrolled, timestarted, timecompleted'
        );
        if (!$record) {
            return null;
        }
        return (object) [
            'timeenrolled' => (int) $record->timeenrolled,
            'timestarted' => (int) $record->timestarted,
            'timecompleted' => (int) $record->timecompleted,
        ];
    }

    /**
     * The learner's earliest enrolment time in one course, matching the builder's rule.
     *
     * @param int $userid
     * @param int $courseid
     * @return int timestamp, or 0
     */
    private static function enrolment_start(int $userid, int $courseid): int {
        global $DB;

        $sql = "SELECT MIN(CASE WHEN ue.timestart > 0 THEN ue.timestart ELSE ue.timecreated END) AS t
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                 WHERE ue.userid = :userid AND e.courseid = :courseid";
        return (int) $DB->get_field_sql($sql, ['userid' => $userid, 'courseid' => $courseid]);
    }
}
