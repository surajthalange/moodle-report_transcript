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

namespace report_transcript;

use core\event\course_completed;
use report_transcript\local\archive;

/**
 * Event observers.
 *
 * @package    report_transcript
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * Archive a completion as it happens.
     *
     * This is the ordinary case. A course deleted later still has this row, and the sweep
     * before deletion refreshes it so that any grade corrected in the meantime is kept.
     *
     * @param course_completed $event
     */
    public static function course_completed(course_completed $event): void {
        archive::capture((int) $event->relateduserid, (int) $event->courseid);
    }
}
