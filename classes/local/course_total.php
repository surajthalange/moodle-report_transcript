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
use grade_grade;
use grade_item;
use grade_plugin_return;
use grade_report;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->dirroot . '/grade/report/lib.php');
require_once($CFG->dirroot . '/grade/lib.php');

/**
 * A learner's course total, exactly as the core overview report would show it to them.
 *
 * The transcript must never reveal more than the learner's own gradebook. The hardest case
 * is a course total that aggregates a hidden item: the site setting "show totals if they
 * contain hidden items" decides whether the learner sees nothing, an adjusted total, or the
 * real one. Core implements that in grade_report::blank_hidden_total_and_adjust_bounds(),
 * which is protected, so this thin subclass exists only to call it. Reimplementing the rule
 * would be a second copy of core logic that could drift.
 *
 * The overview report's setting is used rather than the user report's, because the overview
 * report is the learner's cross-course view and the transcript is its document form.
 *
 * @package    report_transcript
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_total extends grade_report {
    /**
     * Constructor.
     *
     * @param int $courseid
     * @param stdClass $user the learner
     */
    public function __construct(int $courseid, stdClass $user) {
        global $CFG;

        $context = context_course::instance($courseid);
        parent::__construct($courseid, new grade_plugin_return(), $context);

        $this->user = $user;
        $this->showtotalsifcontainhidden = [
            $courseid => grade_get_setting(
                $courseid,
                'report_overview_showtotalsifcontainhidden',
                $CFG->grade_report_overview_showtotalsifcontainhidden
            ),
        ];
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

        $adjusted = $this->blank_hidden_total_and_adjust_bounds($this->courseid, $item, (float) $grade->finalgrade);
        if ($adjusted['grade'] === null) {
            return ['finalgrade' => null, 'withheld' => true];
        }

        $item->grademax = $adjusted['grademax'];
        $item->grademin = $adjusted['grademin'];
        return ['finalgrade' => (float) $adjusted['grade'], 'withheld' => false];
    }

    /**
     * Required by the base class; this report never processes form data.
     *
     * @param array $data
     * @return bool
     */
    public function process_data($data) {
        return false;
    }

    /**
     * Required by the base class; this report has no actions.
     *
     * @param string $target
     * @param string $action
     */
    public function process_action($target, $action) {
    }
}
