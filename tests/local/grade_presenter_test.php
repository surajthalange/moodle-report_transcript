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

global $CFG;
require_once($CFG->libdir . '/gradelib.php');

/**
 * Tests for grade_presenter: one test per rule in PRD section 6.3.
 *
 * Visibility is course_total's job and is tested there; these tests hand the presenter a
 * resolved grade and check what it makes of it.
 *
 * Metadata stays in doc-comments rather than PHP attributes: attributes arrived in
 * PHPUnit 10 and Moodle 4.5, this plugin's floor, ships PHPUnit ^9.6.34.
 *
 * @package    report_transcript
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \report_transcript\local\grade_presenter
 * @covers     \report_transcript\local\grade_presentation
 * @covers     \report_transcript\local\outcome
 */
final class grade_presenter_test extends \advanced_testcase {
    /** @var \stdClass The course under test. */
    private \stdClass $course;

    /**
     * A course whose grade item can be configured per test.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
    }

    /**
     * The course grade item, refetched so that updates made through it are visible.
     *
     * @param array $changes properties to set before returning, saved to the database
     * @return grade_item
     */
    private function item(array $changes = []): grade_item {
        $item = grade_item::fetch_course_item($this->course->id);
        if ($changes) {
            foreach ($changes as $k => $v) {
                $item->$k = $v;
            }
            $item->update();
            $item = grade_item::fetch_course_item($this->course->id);
        }
        return $item;
    }

    /**
     * A completed course with no grade at all is simply completed.
     */
    public function test_completed_without_a_grade_is_completed(): void {
        $p = grade_presenter::present($this->item(), null, false, grade_presenter::STATUS_COMPLETED, settings::defaults());

        $this->assertSame('—', $p->gradetext);
        $this->assertSame(outcome::COMPLETED, $p->outcome);
        $this->assertFalse($p->hidden);
        $this->assertNull($p->finalgrade);
    }

    /**
     * At or above the grade to pass is a pass.
     */
    public function test_grade_at_or_above_gradepass_is_a_pass(): void {
        $item = $this->item(['gradepass' => 50]);

        $p = grade_presenter::present($item, 50.0, false, grade_presenter::STATUS_COMPLETED, settings::defaults());

        $this->assertSame(outcome::PASS, $p->outcome);
        $this->assertSame(50.0, $p->finalgrade);
        $this->assertSame(50.0, $p->gradepass);
        $this->assertSame('50.00', $p->gradetext);
    }

    /**
     * Below the grade to pass is a fail.
     */
    public function test_grade_below_gradepass_is_a_fail(): void {
        $item = $this->item(['gradepass' => 50]);

        $p = grade_presenter::present($item, 49.99, false, grade_presenter::STATUS_COMPLETED, settings::defaults());

        $this->assertSame(outcome::FAIL, $p->outcome);
    }

    /**
     * With no grade to pass defined, a graded completion is just completed.
     */
    public function test_no_gradepass_means_completed_not_pass(): void {
        $p = grade_presenter::present($this->item(), 95.0, false, grade_presenter::STATUS_COMPLETED, settings::defaults());

        $this->assertSame(outcome::COMPLETED, $p->outcome);
        $this->assertSame('95.00', $p->gradetext);
    }

    /**
     * A withheld grade shows nothing, and the outcome is withheld with it.
     *
     * This is the rule the PRD calls the most important in the plugin: Pass or Fail would
     * reveal the hidden grade, so both columns go blank, and the raw value is not stored.
     */
    public function test_withheld_grade_withholds_grade_and_outcome(): void {
        $item = $this->item(['gradepass' => 50]);

        $p = grade_presenter::present($item, 80.0, true, grade_presenter::STATUS_COMPLETED, settings::defaults());

        $this->assertTrue($p->hidden);
        $this->assertSame('—', $p->gradetext);
        $this->assertSame(outcome::NONE, $p->outcome);
        $this->assertNull($p->finalgrade, 'The raw value must not leak into the snapshot either');
    }

    /**
     * By default the transcript follows the course's own display type.
     */
    public function test_course_display_type_is_followed_by_default(): void {
        $item = $this->item(['display' => GRADE_DISPLAY_TYPE_PERCENTAGE]);

        $p = grade_presenter::present($item, 75.0, false, grade_presenter::STATUS_COMPLETED, settings::defaults());

        $this->assertSame(GRADE_DISPLAY_TYPE_PERCENTAGE, $p->displaytype);
        $this->assertSame('75.00 %', $p->gradetext);
    }

    /**
     * The site-wide grade display setting overrides the course's display type.
     */
    public function test_forced_display_type_overrides_the_course(): void {
        $item = $this->item(['display' => GRADE_DISPLAY_TYPE_PERCENTAGE]);
        $settings = settings::defaults(['gradedisplay' => GRADE_DISPLAY_TYPE_LETTER]);

        $p = grade_presenter::present($item, 75.0, false, grade_presenter::STATUS_COMPLETED, $settings);

        $this->assertSame(GRADE_DISPLAY_TYPE_LETTER, $p->displaytype);
        $this->assertSame(grade_format_gradevalue(75.0, $item, true, GRADE_DISPLAY_TYPE_LETTER), $p->gradetext);
        $this->assertStringNotContainsString('%', $p->gradetext);
    }

    /**
     * An in-progress course carries its status as the outcome, whatever the grade says.
     */
    public function test_in_progress_outcome_is_the_status(): void {
        $item = $this->item(['gradepass' => 50]);

        $p = grade_presenter::present($item, 90.0, false, grade_presenter::STATUS_INPROGRESS, settings::defaults());

        $this->assertSame(outcome::INPROGRESS, $p->outcome, 'A running grade above gradepass is not yet a pass');
        $this->assertSame('90.00', $p->gradetext);
    }

    /**
     * An untracked course carries its status as the outcome.
     */
    public function test_untracked_outcome_is_the_status(): void {
        $p = grade_presenter::present($this->item(), null, false, grade_presenter::STATUS_UNTRACKED, settings::defaults());

        $this->assertSame(outcome::UNTRACKED, $p->outcome);
        $this->assertSame('—', $p->gradetext);
    }

    /**
     * A course whose grade type is "none" has no grade to show, but can still be completed.
     */
    public function test_gradetype_none_shows_no_grade(): void {
        $item = $this->item(['gradetype' => GRADE_TYPE_NONE]);

        $p = grade_presenter::present($item, 80.0, false, grade_presenter::STATUS_COMPLETED, settings::defaults());

        $this->assertSame('—', $p->gradetext);
        $this->assertSame(outcome::COMPLETED, $p->outcome);
    }

    /**
     * Every outcome constant has a label, and unknown values fall back to the dash.
     */
    public function test_outcome_labels(): void {
        $this->assertSame('Pass', outcome::label(outcome::PASS));
        $this->assertSame('Fail', outcome::label(outcome::FAIL));
        $this->assertSame('Completed', outcome::label(outcome::COMPLETED));
        $this->assertSame('In progress', outcome::label(outcome::INPROGRESS));
        $this->assertSame('Not tracked', outcome::label(outcome::UNTRACKED));
        $this->assertSame('—', outcome::label(outcome::NONE));
        $this->assertSame('—', outcome::label('garbage'));
    }

    /**
     * The snapshot form carries every field the verify page needs.
     */
    public function test_snapshot_shape(): void {
        $item = $this->item(['gradepass' => 50]);

        $snap = grade_presenter::present($item, 60.0, false, grade_presenter::STATUS_COMPLETED, settings::defaults())
            ->to_snapshot();

        $this->assertSame(
            ['gradetext', 'outcome', 'hidden', 'finalgrade', 'gradepass', 'displaytype'],
            array_keys($snap)
        );
        $this->assertSame(outcome::PASS, $snap['outcome']);
        $this->assertSame(60.0, $snap['finalgrade']);
    }
}
