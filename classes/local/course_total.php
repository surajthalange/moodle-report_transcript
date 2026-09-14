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

use grade_grade;
use grade_item;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/gradelib.php');

/**
 * A learner's course total, exactly as the core overview report would show it to them.
 *
 * The transcript must never reveal more than the learner's own gradebook. The hardest case
 * is a course total that aggregates a hidden item: the overview report's setting "show
 * totals if they contain hidden items" decides whether the learner sees nothing, an
 * adjusted total, or the real one. The analysis of which totals a hidden item affects is
 * core's grade_grade::get_hiding_affected(); the decision on top of it mirrors
 * grade_report::blank_hidden_total_and_adjust_bounds(), which is not used directly because
 * it memoises per user and course in function-level statics, and a cache keyed that way
 * cannot be trusted anywhere one process sees more than one state of the same course.
 *
 * The overview report's setting is used rather than the user report's, because the overview
 * report is the learner's cross-course view and the transcript is its document form.
 *
 * @package    report_transcript
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_total {
    /** @var int The overview report's "show totals if they contain hidden items" setting. */
    private int $showtotals;

    /**
     * Constructor.
     *
     * @param int $courseid
     * @param stdClass $user the learner
     */
    public function __construct(
        /** @var int The course */
        private readonly int $courseid,
        /** @var stdClass The learner */
        private readonly stdClass $user,
    ) {
        global $CFG;
        $this->showtotals = (int) grade_get_setting(
            $courseid,
            'report_overview_showtotalsifcontainhidden',
            $CFG->grade_report_overview_showtotalsifcontainhidden
        );
    }

    /**
     * The learner's course total, with the hidden-item rule applied.
     *
     * @param grade_item $item the course grade item; its grademin and grademax are adjusted
     *      in place when hidden items change the bounds, as the overview report does
     * @return array{finalgrade: ?float, withheld: bool} the grade to present, and whether the
     *      gradebook is withholding it
     */
    public function resolve(grade_item $item): array {
        $grade = grade_grade::fetch(['itemid' => $item->id, 'userid' => $this->user->id]);
        if (!$grade || $grade->finalgrade === null) {
            return ['finalgrade' => null, 'withheld' => false];
        }
        $grade->grade_item = $item;

        if ($item->is_hidden() || $grade->is_hidden()) {
            return ['finalgrade' => null, 'withheld' => true];
        }

        $finalgrade = (float) $grade->finalgrade;
        $item->grademin = $grade->get_grade_min();
        $item->grademax = $grade->get_grade_max();

        if ($this->showtotals === GRADE_REPORT_SHOW_REAL_TOTAL_IF_CONTAINS_HIDDEN) {
            return ['finalgrade' => $finalgrade, 'withheld' => false];
        }

        $affected = $this->hiding_affected();
        $id = $item->id;
        $hide = $this->showtotals === GRADE_REPORT_HIDE_TOTAL_IF_CONTAINS_HIDDEN;

        $altered = array_key_exists($id, $affected['altered'])
            || array_key_exists($id, $affected['alteredgrademin'])
            || array_key_exists($id, $affected['alteredgrademax'])
            || array_key_exists($id, $affected['alteredaggregationstatus'])
            || array_key_exists($id, $affected['alteredaggregationweight']);

        if ($altered) {
            // The total definitely depends on a hidden item.
            if ($hide && array_key_exists($id, $affected['altered'])) {
                return ['finalgrade' => null, 'withheld' => true];
            }
            if (array_key_exists($id, $affected['altered'])) {
                $finalgrade = $affected['altered'][$id];
            }
        } else if (array_key_exists($id, $affected['unknowngrades'])) {
            // Core cannot tell whether the total depends on a hidden item.
            if ($hide) {
                return ['finalgrade' => null, 'withheld' => true];
            }
            $finalgrade = $affected['unknowngrades'][$id];
        } else {
            return ['finalgrade' => $finalgrade, 'withheld' => false];
        }

        if ($finalgrade === null) {
            return ['finalgrade' => null, 'withheld' => true];
        }
        if (array_key_exists($id, $affected['alteredgrademin'])) {
            $item->grademin = $affected['alteredgrademin'][$id];
        }
        if (array_key_exists($id, $affected['alteredgrademax'])) {
            $item->grademax = $affected['alteredgrademax'][$id];
        }
        return ['finalgrade' => (float) $finalgrade, 'withheld' => false];
    }

    /**
     * Core's analysis of which of this learner's grades in the course are affected by
     * hidden items. Every grade item in the course must be present, with an empty grade
     * object where the learner has none, or core refuses the arrays.
     *
     * @return array as returned by grade_grade::get_hiding_affected()
     */
    private function hiding_affected(): array {
        global $DB;

        $items = grade_item::fetch_all(['courseid' => $this->courseid]) ?: [];
        $grades = [];
        $sql = "SELECT g.*
                  FROM {grade_grades} g
                  JOIN {grade_items} gi ON gi.id = g.itemid
                 WHERE g.userid = :userid AND gi.courseid = :courseid";
        foreach ($DB->get_records_sql($sql, ['userid' => $this->user->id, 'courseid' => $this->courseid]) as $record) {
            $grades[$record->itemid] = new grade_grade($record, false);
        }
        foreach ($items as $itemid => $item) {
            if (!isset($grades[$itemid])) {
                $empty = new grade_grade();
                $empty->userid = $this->user->id;
                $empty->itemid = $itemid;
                $grades[$itemid] = $empty;
            }
            $grades[$itemid]->grade_item = $items[$itemid];
        }

        return grade_grade::get_hiding_affected($grades, $items);
    }
}
