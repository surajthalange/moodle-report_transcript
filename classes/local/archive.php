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

use context_course;
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
 * So each transcript line is captured here: when a course is completed, again immediately
 * before the course is deleted so the last known grades win, and in bulk by the backfill.
 * Rows are read back only for courses that no longer exist, which is what keeps a corrected
 * grade in a live course authoritative.
 *
 * Every status the live transcript can show is kept, not only completions. In-progress and
 * untracked lines appear on transcripts whenever the matching setting is on, so leaving them
 * out meant a line that was visible before a course was deleted vanished afterwards. Which
 * statuses are actually shown is decided when the archive is READ, from the settings as they
 * stand then, so turning a setting on later needs no second backfill.
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
        $builder = new transcript_builder(settings::from_config());
        $cc = self::completion_record($userid, $courseid);

        $status = self::status_for($builder, $course, $userid, $cc);
        if ($status === null) {
            return false;
        }
        if (!$builder->is_learner($courseid, $userid, $status)) {
            // The same test the live transcript makes. A completion record alone does not
            // put a course on someone's transcript if the gradebook would not call them a
            // learner there, and the archive must not disagree with the page it stands in for.
            return false;
        }
        $row = $builder->row_for(
            $user,
            $course,
            $status,
            $cc,
            self::enrolment_start($userid, $courseid)
        );

        self::upsert($userid, $course, $row);
        return true;
    }

    /**
     * The status the live transcript would give this course for this learner, or null if it
     * would not list the course at all.
     *
     * This mirrors build()'s three rules deliberately. The two settings that decide whether
     * in-progress and untracked lines are listed are NOT consulted here: the archive keeps
     * the row either way and the read path applies the settings of the moment, so an
     * administrator turning one on does not have to backfill again to make old courses
     * appear.
     *
     * @param transcript_builder $builder
     * @param stdClass $course
     * @param int $userid
     * @param stdClass|null $cc the learner's completion record for this course, if any
     * @return string|null a grade_presenter::STATUS_* constant, or null
     */
    private static function status_for(
        transcript_builder $builder,
        stdClass $course,
        int $userid,
        ?stdClass $cc
    ): ?string {
        if ($cc && $cc->timecompleted) {
            return grade_presenter::STATUS_COMPLETED;
        }

        // Not completed, so the live transcript lists it only on an active enrolment in a
        // course the learner could actually see.
        if (!is_enrolled(context_course::instance((int) $course->id), $userid, '', true)) {
            return null;
        }
        if (!$builder->learner_can_see_course($course, $userid)) {
            return null;
        }

        return !empty($course->enablecompletion)
            ? grade_presenter::STATUS_INPROGRESS
            : grade_presenter::STATUS_UNTRACKED;
    }

    /**
     * Capture every learner whose transcript lists this course.
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
        $written = 0;
        $captured = [];
        foreach (self::candidate_userids($courseid) as $userid) {
            if (self::capture($userid, $courseid)) {
                $written++;
                $captured[] = $userid;
            }
        }

        // Anything still archived for this course that no longer earns a transcript line has
        // to go: a completion since revoked, or a learner since unenrolled. The live
        // transcript has already stopped showing those, and letting the course be deleted
        // must not quietly bring them back.
        self::forget_not_captured($courseid, $captured);

        return $written;
    }

    /**
     * The archived rows for a learner, for courses that no longer exist.
     *
     * Only those: a course that still exists is the live transcript's business, whether it
     * ends up on the page or is filtered out of it.
     *
     * @param int $userid
     * @param array $excludedcategories category ids the settings exclude, keyed by id
     * @param settings|null $settings the settings to apply; the site's own when omitted
     * @return transcript_row[]
     */
    public static function rows_for(
        int $userid,
        array $excludedcategories = [],
        ?settings $settings = null
    ): array {
        global $DB;

        $settings = $settings ?? settings::from_config();

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
            if (!self::status_shown((string) $record->status, $settings)) {
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
     * Whether a stored row's status is one the site currently lists.
     *
     * Applied on read rather than on capture, so that turning a setting on makes archived
     * rows appear without a second backfill, and turning it off hides them again.
     *
     * @param string $status a grade_presenter::STATUS_* constant
     * @param settings $settings
     * @return bool
     */
    private static function status_shown(string $status, settings $settings): bool {
        return match ($status) {
            grade_presenter::STATUS_INPROGRESS => $settings->includeinprogress,
            grade_presenter::STATUS_UNTRACKED => $settings->includeuntracked,
            default => true,
        };
    }

    /**
     * Whether an archived row falls under a category the settings exclude.
     *
     * Tested against the stored ancestor path rather than the leaf id, because by the time
     * a row is read its categories may have been deleted as well, and an excluded parent
     * has to keep excluding.
     *
     * @param stdClass $record
     * @param array $excludedcategories category ids the settings exclude, keyed by id
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
     * Everyone who might have a transcript line in this course.
     *
     * Completions alone were swept before 1.1.1, which is why an in-progress line
     * disappeared when its course was deleted. Active enrolments supply the in-progress and
     * untracked lines, so they have to be swept as well.
     *
     * @param int $courseid
     * @return int[] distinct user ids
     */
    private static function candidate_userids(int $courseid): array {
        global $DB;

        $completed = $DB->get_fieldset_select(
            'course_completions',
            'DISTINCT userid',
            'course = :courseid AND timecompleted IS NOT NULL AND timecompleted > 0',
            ['courseid' => $courseid]
        );

        $enrolled = array_keys(get_enrolled_users(
            context_course::instance($courseid),
            '',
            0,
            'u.id',
            null,
            0,
            0,
            true
        ));

        $ids = array_map('intval', array_merge($completed, $enrolled));

        return array_values(array_unique($ids));
    }

    /**
     * Drop archived rows for learners in this course who no longer earn a transcript line.
     *
     * Before 1.1.1 this only considered revoked completions. It now covers anyone the sweep
     * did not capture, which includes a learner since unenrolled from a course they had not
     * completed, because the live transcript has stopped listing them either way.
     *
     * @param int $courseid
     * @param int[] $captured user ids just captured for this course
     */
    private static function forget_not_captured(int $courseid, array $captured): void {
        global $DB;

        if (!$captured) {
            $DB->delete_records(self::TABLE, ['courseid' => $courseid]);
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($captured, SQL_PARAMS_NAMED, 'u', false);
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
