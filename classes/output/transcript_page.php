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

namespace report_transcript\output;

use moodle_url;
use renderable;
use renderer_base;
use report_transcript\local\grade_presenter;
use report_transcript\local\issuer;
use report_transcript\local\outcome;
use report_transcript\local\settings;
use report_transcript\local\transcript;
use report_transcript\local\transcript_row;
use stdClass;
use templatable;

/**
 * The transcript, ready for a template. Shared by the live page and the verify page.
 *
 * @package    report_transcript
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class transcript_page implements renderable, templatable {
    /**
     * Constructor.
     *
     * @param transcript $transcript
     * @param settings $settings
     * @param bool $isother the viewer is not the learner
     * @param moodle_url|null $downloadurl null on the verify page, where there is no download
     * @param stdClass[] $issues previously issued codes, newest first
     * @param int|null $timezoneuserid whose timezone dates render in; null for the viewer
     * @param bool $showname print the learner's name in the body; off when the page header has it
     */
    public function __construct(
        private readonly transcript $transcript,
        private readonly settings $settings,
        private readonly bool $isother,
        private readonly ?moodle_url $downloadurl,
        private readonly array $issues = [],
        private readonly ?int $timezoneuserid = null,
        private readonly bool $showname = true,
    ) {
    }

    /**
     * Export for template.
     *
     * @param renderer_base $output
     * @return stdClass
     */
    public function export_for_template(renderer_base $output): stdClass {
        $t = $this->transcript;

        $rows = array_map(fn(transcript_row $r) => $this->export_row($r), $t->rows);

        $completed = $t->count(grade_presenter::STATUS_COMPLETED);
        $inprogress = $t->count(grade_presenter::STATUS_INPROGRESS);
        if ($t->is_empty()) {
            $summary = get_string('summaryempty', 'report_transcript');
        } else if ($inprogress > 0) {
            $summary = get_string('summary', 'report_transcript', (object) ['completed' => $completed, 'inprogress' => $inprogress]);
        } else {
            $summary = get_string('summarycompletedonly', 'report_transcript', $completed);
        }

        return (object) [
            'title' => $t->documenttitle,
            'fullname' => $t->fullname,
            'showname' => $this->showname,
            'isother' => $this->isother,
            'summary' => $summary,
            'hasrows' => !$t->is_empty(),
            'rows' => $rows,
            'showoutcome' => $this->settings->showoutcome,
            'downloadurl' => $this->downloadurl?->out(false),
            'hasissues' => $this->issues !== [],
            'issues' => array_map(fn(stdClass $i) => [
                'code' => issuer::format_code($i->code),
                'issued' => get_string('issuedon', 'report_transcript', $this->date((int) $i->timecreated)),
            ], $this->issues),
        ];
    }

    /**
     * One row for the template.
     *
     * @param transcript_row $r
     * @return array
     */
    private function export_row(transcript_row $r): array {
        $dash = get_string('notavailable', 'report_transcript');
        return [
            'coursename' => $r->coursename,
            'shortname' => $r->shortname,
            'started' => $r->timestarted ? $this->date($r->timestarted) : $dash,
            'completed' => $r->timecompleted ? $this->date($r->timecompleted) : $dash,
            'gradetext' => $r->grade->gradetext,
            'outcome' => outcome::label($r->grade->outcome),
            'outcomekey' => $r->grade->outcome,
            'withheld' => $r->grade->hidden,
        ];
    }

    /**
     * A date in the chosen timezone, using the site's date format.
     *
     * @param int $timestamp
     * @return string
     */
    private function date(int $timestamp): string {
        $timezone = $this->timezoneuserid ? \core_date::get_user_timezone(\core_user::get_user($this->timezoneuserid)) : 99;
        return userdate($timestamp, get_string('strftimedate', 'langconfig'), $timezone);
    }
}
