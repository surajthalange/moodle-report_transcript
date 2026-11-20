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

/**
 * Archives completions that happened before the archive existed.
 *
 * The observer only sees completions from the moment it is installed, so without this a
 * site would protect future learners and nobody else. One pass over every course that has
 * ever recorded a completion fixes that.
 *
 * Work is ordered by course id and reports where it stopped, so a caller can continue from
 * there rather than start again. Every write is an upsert, so running it twice, or running
 * it again after it was interrupted, changes nothing except the modification time.
 *
 * @package    report_transcript
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class backfill {
    /** @var int Courses per batch when a caller does not choose. */
    public const BATCH = 50;

    /**
     * Archive the completions of every course, or of one batch of courses.
     *
     * @param int $after process courses with an id greater than this
     * @param int $limit how many courses to process; 0 for all of them
     * @param callable|null $progress called with (int $courseid, int $rows, int $done, int $total)
     * @return array{lastcourseid: int, courses: int, rows: int, done: bool}
     */
    public static function run(int $after = 0, int $limit = 0, ?callable $progress = null): array {
        $courseids = self::course_ids($after, $limit);
        $total = count($courseids);

        $lastcourseid = $after;
        $rows = 0;
        $done = 0;

        foreach ($courseids as $courseid) {
            $written = archive::capture_course($courseid);
            $rows += $written;
            $lastcourseid = $courseid;
            $done++;
            if ($progress) {
                $progress($courseid, $written, $done, $total);
            }
        }

        return [
            'lastcourseid' => $lastcourseid,
            'courses' => $total,
            'rows' => $rows,
            // A short batch means there was nothing more to take.
            'done' => $limit === 0 || $total < $limit,
        ];
    }

    /**
     * The courses that have at least one completion, in id order.
     *
     * The front page is excluded, as it is everywhere else in this plugin.
     *
     * @param int $after courses with an id greater than this
     * @param int $limit 0 for all of them
     * @return int[]
     */
    public static function course_ids(int $after = 0, int $limit = 0): array {
        global $DB;

        // Courses with a completion, and courses with an active enrolment. The second half
        // was missing before 1.1.1, so the backfill never reached the in-progress and
        // untracked lines that the archive is now responsible for.
        $sql = "SELECT c.id
                  FROM {course} c
                 WHERE c.id > :after
                       AND c.id <> :siteid
                       AND (EXISTS (SELECT 1
                                      FROM {course_completions} cc
                                     WHERE cc.course = c.id
                                           AND cc.timecompleted IS NOT NULL
                                           AND cc.timecompleted > 0)
                            OR EXISTS (SELECT 1
                                         FROM {enrol} e
                                         JOIN {user_enrolments} ue ON ue.enrolid = e.id
                                        WHERE e.courseid = c.id
                                              AND e.status = :enrolenabled
                                              AND ue.status = :useractive))
              ORDER BY c.id ASC";
        $params = [
            'after' => $after,
            'siteid' => SITEID,
            'enrolenabled' => ENROL_INSTANCE_ENABLED,
            'useractive' => ENROL_USER_ACTIVE,
        ];
        $records = $DB->get_records_sql($sql, $params, 0, $limit ?: 0);

        return array_map('intval', array_keys($records));
    }

    /**
     * How many courses are still waiting.
     *
     * @param int $after courses with an id greater than this
     * @return int
     */
    public static function remaining(int $after = 0): int {
        return count(self::course_ids($after));
    }
}
