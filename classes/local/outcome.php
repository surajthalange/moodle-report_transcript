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
 * The outcome column's vocabulary.
 *
 * Stored in snapshots as these string constants, never as translated text, so that a
 * snapshot issued in one language renders correctly in another.
 *
 * @package    report_transcript
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class outcome {
    /** @var string Completed and reached the grade to pass. */
    public const PASS = 'pass';

    /** @var string Completed but below the grade to pass. */
    public const FAIL = 'fail';

    /** @var string Completed; the course defines no grade to pass. */
    public const COMPLETED = 'completed';

    /** @var string Active enrolment, not yet completed. */
    public const INPROGRESS = 'inprogress';

    /** @var string Active enrolment in a course that does not track completion. */
    public const UNTRACKED = 'untracked';

    /** @var string Withheld: the gradebook is hiding the grade, or there is none to judge. */
    public const NONE = 'none';

    /**
     * The translated label for an outcome.
     *
     * @param string $outcome one of the constants
     * @return string
     */
    public static function label(string $outcome): string {
        return match ($outcome) {
            self::PASS => get_string('outcome:pass', 'report_transcript'),
            self::FAIL => get_string('outcome:fail', 'report_transcript'),
            self::COMPLETED => get_string('outcome:completed', 'report_transcript'),
            self::INPROGRESS => get_string('outcome:inprogress', 'report_transcript'),
            self::UNTRACKED => get_string('outcome:untracked', 'report_transcript'),
            default => get_string('notavailable', 'report_transcript'),
        };
    }
}
