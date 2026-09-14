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
 * Tests for rate_limiter.
 *
 * Metadata stays in doc-comments rather than PHP attributes: attributes arrived in
 * PHPUnit 10 and Moodle 4.5, this plugin's floor, ships PHPUnit ^9.6.34.
 *
 * @package    report_transcript
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \report_transcript\local\rate_limiter
 */
final class rate_limiter_test extends \advanced_testcase {
    /**
     * The limit-th attempt is allowed; the next is not; another address is unaffected.
     */
    public function test_limit_per_address(): void {
        $this->resetAfterTest();
        rate_limiter::reset('203.0.113.7');
        rate_limiter::reset('203.0.113.8');

        for ($i = 1; $i <= rate_limiter::LIMIT; $i++) {
            $this->assertTrue(rate_limiter::allow('203.0.113.7'), "Attempt {$i} is within the limit");
        }
        $this->assertFalse(rate_limiter::allow('203.0.113.7'), 'Attempt ' . (rate_limiter::LIMIT + 1) . ' is refused');
        $this->assertFalse(rate_limiter::allow('203.0.113.7'), 'And it stays refused');

        $this->assertTrue(rate_limiter::allow('203.0.113.8'), 'A different address has its own count');

        rate_limiter::reset('203.0.113.7');
        $this->assertTrue(rate_limiter::allow('203.0.113.7'), 'Reset clears the count');
    }
}
