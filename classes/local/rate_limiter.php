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

use cache;

/**
 * Limits verification lookups per client address.
 *
 * Codes are 24 characters from a 32-letter alphabet, so guessing one is not a realistic
 * attack; the limit exists so that the public page cannot be used to hammer the database.
 * The window slides: every attempt refreshes the entry's lifetime, so a client that keeps
 * trying stays limited.
 *
 * @package    report_transcript
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rate_limiter {
    /** @var int Attempts allowed per address per window. */
    public const LIMIT = 10;

    /**
     * Record an attempt and say whether it is allowed.
     *
     * @param string $address the client address
     * @return bool true when the attempt may proceed
     */
    public static function allow(string $address): bool {
        $cache = cache::make('report_transcript', 'verifyattempts');
        $key = sha1($address);
        $count = (int) $cache->get($key) + 1;
        $cache->set($key, $count);
        return $count <= self::LIMIT;
    }

    /**
     * Forget an address, for tests.
     *
     * @param string $address
     */
    public static function reset(string $address): void {
        cache::make('report_transcript', 'verifyattempts')->delete(sha1($address));
    }
}
