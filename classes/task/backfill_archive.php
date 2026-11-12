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

namespace report_transcript\task;

use core\task\adhoc_task;
use core\task\manager;
use report_transcript\local\backfill;

/**
 * Archive the completions that happened before the archive existed.
 *
 * Runs a batch of courses, then queues itself again for the next batch if there is one, so
 * a large site is worked through over several cron runs rather than in one long request.
 * Every write is an upsert, so a batch that runs twice costs time and changes nothing.
 *
 * @package    report_transcript
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backfill_archive extends adhoc_task {
    /**
     * The name shown in the task administration screens.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:backfillarchive', 'report_transcript');
    }

    /**
     * Run one batch, and queue the next one if the work is not finished.
     */
    public function execute(): void {
        $data = (array) ($this->get_custom_data() ?? []);
        $after = (int) ($data['after'] ?? 0);

        $result = backfill::run($after, backfill::BATCH, function (int $courseid, int $rows, int $done, int $total) {
            mtrace("  course {$courseid}: {$rows} archived ({$done}/{$total})");
        });

        mtrace(get_string('task:backfillprogress', 'report_transcript', (object) [
            'courses' => $result['courses'],
            'rows' => $result['rows'],
        ]));

        if ($result['done']) {
            mtrace(get_string('task:backfilldone', 'report_transcript'));
            return;
        }

        $next = new self();
        $next->set_custom_data(['after' => $result['lastcourseid']]);
        manager::queue_adhoc_task($next);
        mtrace(get_string('task:backfillqueued', 'report_transcript', $result['lastcourseid']));
    }
}
