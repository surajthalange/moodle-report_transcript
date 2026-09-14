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
 * A transcript was issued: a PDF was downloaded and a verification code created.
 *
 * other carries the code and the row count only. Nothing from the snapshot goes in,
 * because event data is broadly readable and the snapshot holds grades.
 *
 * @package    report_transcript
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class transcript_issued extends \core\event\base {
    /**
     * Init.
     */
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'report_transcript_issue';
    }

    /**
     * Name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event:transcriptissued', 'report_transcript');
    }

    /**
     * Description.
     *
     * @return string
     */
    public function get_description() {
        $rows = (int) ($this->other['rows'] ?? 0);
        if ($this->userid == $this->relateduserid) {
            return "The user with id '{$this->userid}' issued their own transcript with {$rows} course(s).";
        }
        return "The user with id '{$this->userid}' issued the transcript of the user with id "
            . "'{$this->relateduserid}' with {$rows} course(s).";
    }

    /**
     * Url: the verification page for this issue.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/report/transcript/verify.php', ['code' => $this->other['code']]);
    }

    /**
     * Validate.
     */
    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->relateduserid)) {
            throw new \coding_exception('The \'relateduserid\' must be set.');
        }
        if (!isset($this->other['code']) || !isset($this->other['rows'])) {
            throw new \coding_exception('The \'code\' and \'rows\' values must be set in other.');
        }
    }

    /**
     * Object id mapping for restore: issues are not backed up.
     *
     * @return array|bool
     */
    public static function get_objectid_mapping() {
        return ['db' => 'report_transcript_issue', 'restore' => \core\event\base::NOT_MAPPED];
    }

    /**
     * Other mapping: nothing to map.
     *
     * @return bool
     */
    public static function get_other_mapping() {
        return false;
    }
}
