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

/**
 * Archive completions that happened before the archive existed.
 *
 * Run once after upgrading. Safe to run again: every write is an upsert, so a second run
 * only refreshes rows that are already correct. Safe to interrupt: pass the course id it
 * reached to --after and it carries on from there.
 *
 * @package    report_transcript
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use report_transcript\local\backfill;

[$options, $unrecognised] = cli_get_params(
    [
        'help' => false,
        'after' => 0,
        'limit' => 0,
        'dry-run' => false,
    ],
    [
        'h' => 'help',
    ]
);

if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode(PHP_EOL . '  ', $unrecognised)));
}

if ($options['help']) {
    echo <<<EOT
Archive completions that happened before the transcript archive existed.

Each course that has ever recorded a completion is visited once, and every learner who
completed it gets an archived transcript line. Courses deleted before this plugin version
was installed cannot be recovered; nothing can bring those back.

Options:
  -h, --help     Print this help.
      --after=N  Start after course id N. Use this to resume an interrupted run.
      --limit=N  Stop after N courses. Default 0, meaning every course.
      --dry-run  Count the work without writing anything.

Example:
  php report/transcript/cli/backfill_archive.php

EOT;
    exit(0);
}

$after = (int) $options['after'];
$limit = (int) $options['limit'];

if ($options['dry-run']) {
    $courses = count(backfill::course_ids($after, $limit));
    cli_writeln("Courses with completions to archive: {$courses}");
    cli_writeln('Nothing was written. Run without --dry-run to archive them.');
    exit(0);
}

$started = microtime(true);
cli_writeln('Archiving completed courses. This can be run again safely.');

$result = backfill::run($after, $limit, function (int $courseid, int $rows, int $done, int $total) {
    cli_writeln("  [{$done}/{$total}] course {$courseid}: {$rows} learner(s) archived");
});

$seconds = round(microtime(true) - $started, 1);
cli_writeln('');
cli_writeln("Done. {$result['courses']} course(s), {$result['rows']} archived row(s), {$seconds}s.");

if (!$result['done']) {
    cli_writeln("Stopped at course id {$result['lastcourseid']}. Resume with --after={$result['lastcourseid']}");
}

exit(0);
