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
 * A learner's transcript: who it is about, and the rows.
 *
 * Built live by transcript_builder, or rebuilt from an issue snapshot by the verify page.
 * Either way the page and PDF render the same object, so they cannot disagree.
 *
 * @package    report_transcript
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class transcript {
    /** @var int The snapshot format. Bump when the shape changes so old issues still verify. */
    public const SNAPSHOT_VERSION = 1;

    /**
     * Constructor.
     *
     * @param int $userid
     * @param string $fullname the learner's name as it should print
     * @param string $username
     * @param transcript_row[] $rows in display order
     * @param int $timegenerated
     * @param string $documenttitle the title that applies to this transcript
     */
    public function __construct(
        public readonly int $userid,
        public readonly string $fullname,
        public readonly string $username,
        public readonly array $rows,
        public readonly int $timegenerated,
        public readonly string $documenttitle,
    ) {
    }

    /**
     * How many rows carry a status.
     *
     * @param string $status a grade_presenter::STATUS_* constant
     * @return int
     */
    public function count(string $status): int {
        return count(array_filter($this->rows, fn(transcript_row $r) => $r->status === $status));
    }

    /**
     * Whether any row exists.
     *
     * @return bool
     */
    public function is_empty(): bool {
        return $this->rows === [];
    }

    /**
     * The shape stored in an issue snapshot: the rows as rendered, plus what shaped them.
     *
     * @param settings $settings
     * @return array
     */
    public function to_snapshot(settings $settings): array {
        return [
            'version' => self::SNAPSHOT_VERSION,
            'userid' => $this->userid,
            'fullname' => $this->fullname,
            'username' => $this->username,
            'timegenerated' => $this->timegenerated,
            'documenttitle' => $this->documenttitle,
            'settings' => [
                'institutionname' => $settings->institutionname,
                'includeinprogress' => $settings->includeinprogress,
                'includeuntracked' => $settings->includeuntracked,
                'gradedisplay' => $settings->gradedisplay,
                'showoutcome' => $settings->showoutcome,
            ],
            'rows' => array_map(fn(transcript_row $r) => $r->to_snapshot(), $this->rows),
        ];
    }

    /**
     * Rebuild from a snapshot, for the verify page.
     *
     * @param array $data
     * @return self
     */
    public static function from_snapshot(array $data): self {
        return new self(
            (int) $data['userid'],
            (string) $data['fullname'],
            (string) ($data['username'] ?? ''),
            array_map(fn(array $r) => transcript_row::from_snapshot($r), $data['rows'] ?? []),
            (int) $data['timegenerated'],
            (string) $data['documenttitle'],
        );
    }
}
