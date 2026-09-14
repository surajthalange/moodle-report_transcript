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

use grade_item;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/gradelib.php');

/**
 * Turns one resolved course grade into what the transcript shows for it.
 *
 * Visibility is decided before this class is called (see course_total); it receives the
 * grade the learner is allowed to see, or the fact that it is withheld. No writes, no
 * lookups beyond what grade_format_gradevalue() needs, so every branch is pinned by a test.
 *
 *  1. A withheld grade shows nothing for grade or outcome, for every viewer. Pass or Fail
 *     would give the grade away.
 *  2. Rows that are not completed carry their status as the outcome.
 *  3. A completed course with a grade to pass is Pass or Fail; without one, Completed.
 *
 * @package    report_transcript
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grade_presenter {
    /** @var string Row status: completion recorded. */
    public const STATUS_COMPLETED = 'completed';

    /** @var string Row status: active enrolment, completion tracked, not yet completed. */
    public const STATUS_INPROGRESS = 'inprogress';

    /** @var string Row status: active enrolment, completion not tracked. */
    public const STATUS_UNTRACKED = 'untracked';

    /**
     * Present a course grade.
     *
     * @param grade_item $item the course's grade item, bounds already adjusted if needed
     * @param float|null $finalgrade the grade the learner may see; null when there is none
     * @param bool $withheld true when the gradebook is hiding the grade from the learner
     * @param string $status one of the STATUS_* constants
     * @param settings $settings
     * @return grade_presentation
     */
    public static function present(
        grade_item $item,
        ?float $finalgrade,
        bool $withheld,
        string $status,
        settings $settings
    ): grade_presentation {
        $displaytype = $settings->gradedisplay === settings::GRADEDISPLAY_COURSE
            ? (int) $item->get_displaytype()
            : $settings->gradedisplay;
        $gradepass = (float) $item->gradepass;
        $dash = get_string('notavailable', 'report_transcript');

        // Rule 1.
        if ($withheld) {
            return new grade_presentation($dash, outcome::NONE, true, null, $gradepass, $displaytype);
        }

        $gradetext = $dash;
        if ($finalgrade !== null) {
            $formatted = grade_format_gradevalue($finalgrade, $item, true, $displaytype);
            // An empty string means the item has no numeric grade type; '-' means no grade.
            if ($formatted !== '' && $formatted !== '-') {
                $gradetext = $formatted;
            }
        }

        // Rule 2.
        if ($status === self::STATUS_INPROGRESS) {
            return new grade_presentation($gradetext, outcome::INPROGRESS, false, $finalgrade, $gradepass, $displaytype);
        }
        if ($status === self::STATUS_UNTRACKED) {
            return new grade_presentation($gradetext, outcome::UNTRACKED, false, $finalgrade, $gradepass, $displaytype);
        }

        // Rule 3.
        if ($gradepass > 0 && $finalgrade !== null) {
            $outcome = $finalgrade >= $gradepass ? outcome::PASS : outcome::FAIL;
        } else {
            $outcome = outcome::COMPLETED;
        }

        return new grade_presentation($gradetext, $outcome, false, $finalgrade, $gradepass, $displaytype);
    }
}
