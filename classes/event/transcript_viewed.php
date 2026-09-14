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

namespace report_transcript\event;

/**
 * A transcript was viewed.
 *
 * relateduserid is the learner; userid is the viewer. They differ when a manager looks.
 *
 * @package    report_transcript
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class transcript_viewed extends \core\event\base {
    /**
     * Init.
     */
    protected function init() {
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
    }

    /**
     * Name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event:transcriptviewed', 'report_transcript');
    }

    /**
     * Description.
     *
     * @return string
     */
    public function get_description() {
        if ($this->userid == $this->relateduserid) {
            return "The user with id '{$this->userid}' viewed their own transcript.";
        }
        return "The user with id '{$this->userid}' viewed the transcript of the user with id '{$this->relateduserid}'.";
    }

    /**
     * Url.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/report/transcript/index.php', ['userid' => $this->relateduserid]);
    }

    /**
     * Validate.
     */
    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->relateduserid)) {
            throw new \coding_exception('The \'relateduserid\' must be set.');
        }
    }
}
