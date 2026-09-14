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
 * What one course's grade looks like on the transcript, plus the raw values behind it.
 *
 * The text and outcome are what the page and PDF show and what the snapshot stores.
 * The raw values are stored alongside so that a snapshot can be audited later without
 * recomputing anything.
 *
 * @package    report_transcript
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class grade_presentation {
    /**
     * Constructor.
     *
     * @param string $gradetext the grade as displayed, or the "not available" dash
     * @param string $outcome an outcome::* constant
     * @param bool $hidden true when the gradebook is withholding the grade
     * @param float|null $finalgrade the raw final grade; null when absent or withheld
     * @param float $gradepass the course's grade to pass; 0 when none is defined
     * @param int $displaytype the GRADE_DISPLAY_TYPE_* that produced $gradetext
     */
    public function __construct(
        /** @var string The grade as displayed, or the "not available" dash */
        public readonly string $gradetext,
        /** @var string An outcome::* constant */
        public readonly string $outcome,
        /** @var bool True when the gradebook is withholding the grade */
        public readonly bool $hidden,
        /** @var ?float The raw final grade; null when absent or withheld */
        public readonly ?float $finalgrade,
        /** @var float The course's grade to pass; 0 when none is defined */
        public readonly float $gradepass,
        /** @var int The GRADE_DISPLAY_TYPE_* that produced $gradetext */
        public readonly int $displaytype,
    ) {
    }

    /**
     * The shape stored in an issue snapshot.
     *
     * @return array
     */
    public function to_snapshot(): array {
        return [
            'gradetext' => $this->gradetext,
            'outcome' => $this->outcome,
            'hidden' => $this->hidden,
            'finalgrade' => $this->finalgrade,
            'gradepass' => $this->gradepass,
            'displaytype' => $this->displaytype,
        ];
    }
}
