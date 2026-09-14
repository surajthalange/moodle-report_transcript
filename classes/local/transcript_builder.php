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
use grade_item;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->libdir . '/enrollib.php');

/**
 * Builds a learner's transcript from core tables. Reads only; never writes.
 *
 * Which courses appear (PRD section 6.1):
 *
 *  1. Completed: every course_completions row with a completion time whose course still
 *     exists, regardless of course visibility. A course later hidden is still one the
 *     learner completed.
 *  2. In progress (setting): an active enrolment in a visible course that tracks completion,
 *     with no completion time yet.
 *  3. Untracked (setting, off by default): an active enrolment in a course that does not
 *     track completion.
 *
 * Always excluded: the front page; courses under an excluded category; courses where the
 * learner's only roles are not graded roles, using the site's gradebookroles setting, which
 * is the same rule the gradebook uses to decide who is a student. A teacher's own courses
 * do not belong on their transcript. A completed course in which the user no longer holds
 * any role at all is kept: the completion record is the evidence they were a learner there.
 *
 * @package    report_transcript
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class transcript_builder {
    /**
     * Constructor.
     *
     * @param settings $settings
     */
    public function __construct(
        private readonly settings $settings
    ) {
    }

    /**
     * Build the transcript for a user.
     *
     * @param stdClass $user the learner, with the fields fullname() needs
     * @return transcript
     */
    public function build(stdClass $user): transcript {
        $userid = (int) $user->id;
        $excluded = $this->excluded_category_ids();
        $completions = $this->completion_records($userid);
        $enrolmentstart = $this->earliest_enrolment_times($userid);

        $candidates = [];

        // Rule 1: completed courses, from the completion records.
        foreach ($completions as $courseid => $cc) {
            if ($cc->timecompleted) {
                $candidates[$courseid] = [$cc->course, grade_presenter::STATUS_COMPLETED];
            }
        }

        // Rules 2 and 3: active enrolments that are not completed.
        if ($this->settings->includeinprogress || $this->settings->includeuntracked) {
            $active = enrol_get_all_users_courses($userid, true, ['enablecompletion']);
            foreach ($active as $course) {
                $courseid = (int) $course->id;
                if (isset($candidates[$courseid])) {
                    continue;
                }
                if ($course->enablecompletion) {
                    if ($this->settings->includeinprogress && $course->visible) {
                        $candidates[$courseid] = [$course, grade_presenter::STATUS_INPROGRESS];
                    }
                } else if ($this->settings->includeuntracked) {
                    $candidates[$courseid] = [$course, grade_presenter::STATUS_UNTRACKED];
                }
            }
        }

        $rows = [];
        foreach ($candidates as $courseid => [$course, $status]) {
            if ((int) $course->id === SITEID || isset($excluded[(int) $course->category])) {
                continue;
            }
            if (!$this->is_learner($courseid, $userid, $status)) {
                continue;
            }

            $cc = $completions[$courseid] ?? null;
            $timestarted = $this->started($cc, $enrolmentstart[$courseid] ?? 0);
            $timecompleted = $status === grade_presenter::STATUS_COMPLETED ? (int) $cc->timecompleted : null;

            $item = grade_item::fetch_course_item($courseid);
            $resolved = (new course_total($courseid, $user))->resolve($item);
            $grade = grade_presenter::present($item, $resolved['finalgrade'], $resolved['withheld'], $status, $this->settings);

            $rows[] = new transcript_row(
                $courseid,
                format_string($course->fullname, true, ['context' => context_course::instance($courseid)]),
                $course->shortname,
                $status,
                $timestarted,
                $timecompleted,
                $grade,
            );
        }

        usort($rows, [self::class, 'compare']);

        return new transcript(
            $userid,
            fullname($user),
            $user->username,
            $rows,
            time(),
            $this->settings->documenttitle,
        );
    }

    /**
     * Display order: completed by completion date, newest first; then in progress by start
     * date, newest first; then untracked by name. Fixed, not a setting.
     *
     * @param transcript_row $a
     * @param transcript_row $b
     * @return int
     */
    public static function compare(transcript_row $a, transcript_row $b): int {
        $rank = [
            grade_presenter::STATUS_COMPLETED => 0,
            grade_presenter::STATUS_INPROGRESS => 1,
            grade_presenter::STATUS_UNTRACKED => 2,
        ];
        if ($rank[$a->status] !== $rank[$b->status]) {
            return $rank[$a->status] <=> $rank[$b->status];
        }
        return match ($a->status) {
            grade_presenter::STATUS_COMPLETED => ($b->timecompleted <=> $a->timecompleted) ?: strcoll($a->coursename, $b->coursename),
            grade_presenter::STATUS_INPROGRESS => ($b->timestarted <=> $a->timestarted) ?: strcoll($a->coursename, $b->coursename),
            default => strcoll($a->coursename, $b->coursename),
        };
    }

    /**
     * The start date for a row (PRD section 6.2): the completion record's timestarted, else
     * its timeenrolled, else the earliest enrolment start. Never 0 for a row that appears:
     * a completed course whose enrolment has since been removed and whose completion record
     * carries no start falls back to the completion time itself, the one date that is known.
     *
     * @param stdClass|null $cc the completion record
     * @param int $enrolmentstart earliest enrolment time, or 0
     * @return int
     */
    private function started(?stdClass $cc, int $enrolmentstart): int {
        if ($cc && $cc->timestarted) {
            return (int) $cc->timestarted;
        }
        if ($cc && $cc->timeenrolled) {
            return (int) $cc->timeenrolled;
        }
        if ($enrolmentstart) {
            return $enrolmentstart;
        }
        return $cc ? (int) $cc->timecompleted : 0;
    }

    /**
     * Is the user a learner in this course, by the gradebook's definition?
     *
     * @param int $courseid
     * @param int $userid
     * @param string $status the row's status
     * @return bool
     */
    private function is_learner(int $courseid, int $userid, string $status): bool {
        global $CFG;

        $gradebookroles = array_filter(array_map('intval', explode(',', (string) $CFG->gradebookroles)));
        if (!$gradebookroles) {
            return true;
        }

        $roles = get_user_roles(context_course::instance($courseid), $userid, true);
        if (!$roles) {
            // No role at all. A completion record is evidence enough; an active enrolment
            // with no role is not something the gradebook would list either.
            return $status === grade_presenter::STATUS_COMPLETED;
        }
        foreach ($roles as $ra) {
            if (in_array((int) $ra->roleid, $gradebookroles, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Every excluded category id, including all descendants.
     *
     * @return array<int, true>
     */
    private function excluded_category_ids(): array {
        $ids = [];
        foreach ($this->settings->excludedcategories as $categoryid) {
            $category = core_course_category::get($categoryid, IGNORE_MISSING, true);
            if (!$category) {
                continue;
            }
            $ids[(int) $category->id] = true;
            foreach ($category->get_all_children_ids() as $childid) {
                $ids[(int) $childid] = true;
            }
        }
        return $ids;
    }

    /**
     * The user's completion records, keyed by course id, with the course joined so that a
     * deleted course simply has no row. The front page is left out.
     *
     * @param int $userid
     * @return array<int, stdClass> each with ->course (the course record) and the completion times
     */
    private function completion_records(int $userid): array {
        global $DB;

        $sql = "SELECT cc.course AS courseid, cc.timeenrolled, cc.timestarted, cc.timecompleted,
                       c.id, c.fullname, c.shortname, c.category, c.visible, c.enablecompletion
                  FROM {course_completions} cc
                  JOIN {course} c ON c.id = cc.course
                 WHERE cc.userid = :userid AND c.id <> :siteid";
        $records = $DB->get_records_sql($sql, ['userid' => $userid, 'siteid' => SITEID]);

        $out = [];
        foreach ($records as $r) {
            $course = (object) [
                'id' => (int) $r->id,
                'fullname' => $r->fullname,
                'shortname' => $r->shortname,
                'category' => (int) $r->category,
                'visible' => (int) $r->visible,
                'enablecompletion' => (int) $r->enablecompletion,
            ];
            $out[(int) $r->courseid] = (object) [
                'course' => $course,
                'timeenrolled' => (int) $r->timeenrolled,
                'timestarted' => (int) $r->timestarted,
                'timecompleted' => (int) $r->timecompleted,
            ];
        }
        return $out;
    }

    /**
     * The earliest enrolment time per course: timestart when set, else when the enrolment
     * was created.
     *
     * @param int $userid
     * @return array<int, int> course id => timestamp
     */
    private function earliest_enrolment_times(int $userid): array {
        global $DB;

        $sql = "SELECT e.courseid,
                       MIN(CASE WHEN ue.timestart > 0 THEN ue.timestart ELSE ue.timecreated END) AS t
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                 WHERE ue.userid = :userid
              GROUP BY e.courseid";
        $out = [];
        foreach ($DB->get_records_sql($sql, ['userid' => $userid]) as $r) {
            $out[(int) $r->courseid] = (int) $r->t;
        }
        return $out;
    }
}
