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
 * One course on a transcript.
 *
 * @package    report_transcript
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class transcript_row {
    /**
     * Constructor.
     *
     * @param int $courseid
     * @param string $coursename the formatted full name
     * @param string $shortname
     * @param string $status a grade_presenter::STATUS_* constant
     * @param int $timestarted never 0 for a row that appears; see transcript_builder
     * @param int|null $timecompleted null unless completed
     * @param grade_presentation $grade
     */
    public function __construct(
        public readonly int $courseid,
        public readonly string $coursename,
        public readonly string $shortname,
        public readonly string $status,
        public readonly int $timestarted,
        public readonly ?int $timecompleted,
        public readonly grade_presentation $grade,
    ) {
    }

    /**
     * The shape stored in an issue snapshot.
     *
     * @return array
     */
    public function to_snapshot(): array {
        return [
            'courseid' => $this->courseid,
            'coursename' => $this->coursename,
            'shortname' => $this->shortname,
            'status' => $this->status,
            'timestarted' => $this->timestarted,
            'timecompleted' => $this->timecompleted,
            'grade' => $this->grade->to_snapshot(),
        ];
    }

    /**
     * Rebuild a row from a snapshot, for the verify page.
     *
     * @param array $data
     * @return self
     */
    public static function from_snapshot(array $data): self {
        $g = $data['grade'];
        return new self(
            (int) $data['courseid'],
            (string) $data['coursename'],
            (string) $data['shortname'],
            (string) $data['status'],
            (int) $data['timestarted'],
            isset($data['timecompleted']) ? (int) $data['timecompleted'] : null,
            new grade_presentation(
                (string) $g['gradetext'],
                (string) $g['outcome'],
                (bool) $g['hidden'],
                isset($g['finalgrade']) ? (float) $g['finalgrade'] : null,
                (float) $g['gradepass'],
                (int) $g['displaytype'],
            ),
        );
    }
}
