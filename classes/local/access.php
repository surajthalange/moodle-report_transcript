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

use context_system;
use context_user;
use moodle_exception;

/**
 * The one place that decides who may see whose transcript.
 *
 * Two capabilities, two questions: is this my own transcript, and if not, may I see
 * everyone's? Both the profile link and the page controllers ask here, so the answer
 * cannot drift between them.
 *
 * @package    report_transcript
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class access {
    /**
     * May the current user view the transcript of the given user?
     *
     * @param int $userid the learner
     * @return bool
     */
    public static function can_view(int $userid): bool {
        global $USER;

        if (!isloggedin() || isguestuser() || $userid <= 0) {
            return false;
        }

        if ((int) $USER->id === $userid) {
            return has_capability('report/transcript:view', context_system::instance());
        }

        return has_capability('report/transcript:viewall', context_user::instance($userid));
    }

    /**
     * Throw unless the current user may view the transcript of the given user.
     *
     * @param int $userid the learner
     * @throws moodle_exception
     */
    public static function require_view(int $userid): void {
        if (!self::can_view($userid)) {
            throw new moodle_exception('nopermissions', 'error', '', get_string('transcript:view', 'report_transcript'));
        }
    }

    /**
     * Is the current user looking at someone else's transcript?
     *
     * @param int $userid the learner
     * @return bool
     */
    public static function is_viewing_other(int $userid): bool {
        global $USER;
        return (int) $USER->id !== $userid;
    }
}
