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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/gradelib.php');

/**
 * Tests for course_total: every way the gradebook can withhold a course total.
 *
 * Metadata stays in doc-comments rather than PHP attributes: attributes arrived in
 * PHPUnit 10 and Moodle 4.5, this plugin's floor, ships PHPUnit ^9.6.34.
 *
 * @package    report_transcript
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \report_transcript\local\course_total
 */
final class course_total_test extends \advanced_testcase {
    /** @var \stdClass The course under test. */
    private \stdClass $course;

    /** @var \stdClass The learner. */
    private \stdClass $user;

    /**
     * A course with a learner enrolled.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
        $this->user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->user->id, $this->course->id, 'student');
    }

    /**
     * The course grade item.
     *
     * @return grade_item
     */
    private function item(): grade_item {
        return grade_item::fetch_course_item($this->course->id);
    }

    /**
     * Give the learner an overridden course total.
     *
     * @param float $value
     * @return grade_grade
     */
    private function override_total(float $value): grade_grade {
        $item = $this->item();
        $grade = new grade_grade(['itemid' => $item->id, 'userid' => $this->user->id], false);
        $grade->rawgrade = $value;
        $grade->finalgrade = $value;
        $grade->overridden = time();
        $grade->insert();
        return grade_grade::fetch(['itemid' => $item->id, 'userid' => $this->user->id]);
    }

    /**
     * Resolve the learner's course total.
     *
     * Core's blank_hidden_total_and_adjust_bounds() caches its hiding analysis in
     * function-level statics keyed on user id and course id. Within one PHPUnit process the
     * database is reset between tests but ids repeat, so a later test can inherit an earlier
     * test's analysis. Resolving a fresh throwaway course for the same user first changes the
     * key and forces the reload. A real request never hits this: it is one process per request.
     *
     * @return array{finalgrade: ?float, withheld: bool}
     */
    private function resolve(): array {
        // The decoy must carry a grade, or resolve() returns before reaching the static.
        $decoy = $this->getDataGenerator()->create_course();
        $decoyitem = grade_item::fetch_course_item($decoy->id);
        $decoygrade = new grade_grade(['itemid' => $decoyitem->id, 'userid' => $this->user->id], false);
        $decoygrade->finalgrade = 1;
        $decoygrade->rawgrade = 1;
        $decoygrade->overridden = time();
        $decoygrade->insert();
        (new course_total((int) $decoy->id, $this->user))->resolve($decoyitem);

        return (new course_total((int) $this->course->id, $this->user))->resolve($this->item());
    }

    /**
     * Set the overview report's "show totals if they contain hidden items" setting.
     *
     * grade_get_setting() caches the resolved value per course, default included, so the
     * cache has to be reset or a changed site default is never seen.
     *
     * @param int $value a GRADE_REPORT_*_IF_CONTAINS_HIDDEN constant
     */
    private function set_overview_setting(int $value): void {
        set_config('grade_report_overview_showtotalsifcontainhidden', $value);
        grade_get_setting($this->course->id, null, null, true);
    }

    /**
     * No grade at all is not withheld; there is simply nothing to show.
     */
    public function test_no_grade(): void {
        $this->assertSame(['finalgrade' => null, 'withheld' => false], $this->resolve());
    }

    /**
     * A visible grade comes back as-is.
     */
    public function test_visible_grade(): void {
        $this->override_total(72.5);

        $this->assertSame(['finalgrade' => 72.5, 'withheld' => false], $this->resolve());
    }

    /**
     * A hidden grade is withheld.
     */
    public function test_hidden_grade_is_withheld(): void {
        $this->override_total(72.5)->set_hidden(1);

        $this->assertSame(['finalgrade' => null, 'withheld' => true], $this->resolve());
    }

    /**
     * "Hidden until" a future time is withheld; once the time has passed it is visible.
     */
    public function test_hidden_until_respects_the_clock(): void {
        $grade = $this->override_total(72.5);

        $grade->set_hidden(time() + HOURSECS);
        $this->assertTrue($this->resolve()['withheld']);

        $grade->set_hidden(time() - HOURSECS);
        $this->assertSame(['finalgrade' => 72.5, 'withheld' => false], $this->resolve());
    }

    /**
     * A hidden course grade item withholds every learner's total.
     */
    public function test_hidden_item_is_withheld(): void {
        $this->override_total(72.5);
        $this->item()->set_hidden(1);

        $this->assertSame(['finalgrade' => null, 'withheld' => true], $this->resolve());
    }

    /**
     * A total that aggregates a hidden activity grade follows the overview report's
     * "show totals if they contain hidden items" setting: hide, adjust, or show real.
     */
    public function test_total_containing_a_hidden_item_follows_the_overview_setting(): void {
        $generator = $this->getDataGenerator();
        $visible = $generator->create_grade_item(['courseid' => $this->course->id, 'grademax' => 100]);
        $hidden = $generator->create_grade_item(['courseid' => $this->course->id, 'grademax' => 100]);
        grade_item::fetch(['id' => $visible->id])->update_final_grade($this->user->id, 80, 'test');
        $hiddenitem = grade_item::fetch(['id' => $hidden->id]);
        $hiddenitem->update_final_grade($this->user->id, 60, 'test');
        $hiddenitem->set_hidden(1);
        grade_regrade_final_grades($this->course->id);

        // Sanity: the real total is the natural sum of both items.
        $real = grade_grade::fetch(['itemid' => $this->item()->id, 'userid' => $this->user->id]);
        $this->assertEquals(140.0, (float) $real->finalgrade);

        $this->set_overview_setting(GRADE_REPORT_HIDE_TOTAL_IF_CONTAINS_HIDDEN);
        $this->assertSame(['finalgrade' => null, 'withheld' => true], $this->resolve(),
            'Hide: the learner cannot see the total, so neither can the transcript');

        $this->set_overview_setting(GRADE_REPORT_SHOW_TOTAL_IF_CONTAINS_HIDDEN);
        $adjusted = $this->resolve();
        $this->assertFalse($adjusted['withheld']);
        $this->assertEquals(80.0, $adjusted['finalgrade'], 'Adjusted: the hidden item is left out of the total');

        $this->set_overview_setting(GRADE_REPORT_SHOW_REAL_TOTAL_IF_CONTAINS_HIDDEN);
        $this->assertSame(['finalgrade' => 140.0, 'withheld' => false], $this->resolve(),
            'Show real: the learner sees the true total, so the transcript may too');
    }

    /**
     * Adjusting the total also adjusts the grade item's bounds, so a percentage stays honest.
     */
    public function test_adjusted_total_adjusts_the_bounds(): void {
        $generator = $this->getDataGenerator();
        $visible = $generator->create_grade_item(['courseid' => $this->course->id, 'grademax' => 100]);
        $hidden = $generator->create_grade_item(['courseid' => $this->course->id, 'grademax' => 100]);
        grade_item::fetch(['id' => $visible->id])->update_final_grade($this->user->id, 80, 'test');
        $hiddenitem = grade_item::fetch(['id' => $hidden->id]);
        $hiddenitem->update_final_grade($this->user->id, 60, 'test');
        $hiddenitem->set_hidden(1);
        grade_regrade_final_grades($this->course->id);
        $this->set_overview_setting(GRADE_REPORT_SHOW_TOTAL_IF_CONTAINS_HIDDEN);

        $item = $this->item();
        $this->assertEquals(200.0, (float) $item->grademax, 'Natural aggregation: max is the sum of both items');

        $this->resolve();
        (new course_total((int) $this->course->id, $this->user))->resolve($item);

        $this->assertEquals(100.0, (float) $item->grademax, 'With the hidden item left out, the max shrinks with it');
    }
}
