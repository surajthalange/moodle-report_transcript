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

use dml_write_exception;
use stdClass;

/**
 * Issues transcripts: the only class that writes to the plugin's table.
 *
 * An issue is a verification code plus a snapshot of the transcript as it stood. Every
 * PDF download issues afresh rather than reusing a code, because the third download may
 * not say what the first one did, and a code must vouch for exactly one document.
 *
 * @package    report_transcript
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class issuer {
    /** @var string Code alphabet: upper-case without 0, O, 1 and I, so a code can be read aloud. */
    public const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /** @var int Code length in characters. */
    public const CODE_LENGTH = 24;

    /** @var string The table. */
    public const TABLE = 'report_transcript_issue';

    /**
     * Issue a transcript.
     *
     * @param transcript $transcript
     * @param settings $settings the settings that shaped it, stored with the snapshot
     * @param int $issuerid who triggered the issue
     * @return stdClass the issue record
     */
    public function issue(transcript $transcript, settings $settings, int $issuerid): stdClass {
        global $DB;

        $record = (object) [
            'userid' => $transcript->userid,
            'issuerid' => $issuerid,
            'documenttitle' => $transcript->documenttitle,
            'snapshot' => json_encode($transcript->to_snapshot($settings), JSON_UNESCAPED_UNICODE),
            'timecreated' => time(),
        ];

        // The unique index on code makes a collision a write failure; the odds are
        // negligible at 24 characters over a 32-letter alphabet, but a retry costs nothing.
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $record->code = self::generate_code();
            try {
                $record->id = $DB->insert_record(self::TABLE, $record);
                return $record;
            } catch (dml_write_exception $e) {
                if ($attempt === 4) {
                    throw $e;
                }
            }
        }
        // Unreachable: the loop either returns or throws.
        throw new \coding_exception('Unreachable');
    }

    /**
     * Find an issue by its code.
     *
     * @param string $code as typed; case and grouping spaces are forgiven
     * @return stdClass|null
     */
    public function find_by_code(string $code): ?stdClass {
        global $DB;

        $normalised = self::normalise_code($code);
        if (strlen($normalised) !== self::CODE_LENGTH) {
            return null;
        }
        $record = $DB->get_record(self::TABLE, ['code' => $normalised]);
        return $record ?: null;
    }

    /**
     * Every issue for a learner, newest first.
     *
     * @param int $userid
     * @return stdClass[]
     */
    public function issued_for(int $userid): array {
        global $DB;
        return array_values($DB->get_records(self::TABLE, ['userid' => $userid], 'timecreated DESC', 'id, code, timecreated, issuerid'));
    }

    /**
     * Rebuild the transcript an issue captured.
     *
     * @param stdClass $issue
     * @return transcript
     */
    public function transcript_from(stdClass $issue): transcript {
        return transcript::from_snapshot(json_decode($issue->snapshot, true));
    }

    /**
     * A fresh code.
     *
     * @return string
     */
    public static function generate_code(): string {
        $alphabet = self::ALPHABET;
        $max = strlen($alphabet) - 1;
        $code = '';
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= $alphabet[random_int(0, $max)];
        }
        return $code;
    }

    /**
     * Strip grouping and case from a code as a person might type it.
     *
     * @param string $code
     * @return string
     */
    public static function normalise_code(string $code): string {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
    }

    /**
     * A code in groups of four, for printing.
     *
     * @param string $code
     * @return string
     */
    public static function format_code(string $code): string {
        return implode(' ', str_split($code, 4));
    }
}
