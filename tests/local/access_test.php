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

/**
 * Tests for access: who may see whose transcript.
 *
 * Metadata stays in doc-comments rather than PHP attributes: attributes arrived in
 * PHPUnit 10 and Moodle 4.5, this plugin's floor, ships PHPUnit ^9.6.34.
 *
 * @package    report_transcript
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \report_transcript\local\access
 */
final class access_test extends \advanced_testcase {
    /**
     * A learner may see their own transcript and nobody else's; a manager may see anyone's;
     * a learner whose own capability is removed may not even see their own.
     */
    public function test_can_view(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $learner = $generator->create_user();
        $other = $generator->create_user();
        $manager = $generator->create_user();
        $generator->role_assign($DB->get_field('role', 'id', ['shortname' => 'manager']), $manager->id, context_system::instance());

        $this->setUser($learner);
        $this->assertTrue(access::can_view((int) $learner->id));
        $this->assertFalse(access::can_view((int) $other->id));
        $this->assertFalse(access::is_viewing_other((int) $learner->id));
        $this->assertTrue(access::is_viewing_other((int) $other->id));

        $this->setUser($manager);
        $this->assertTrue(access::can_view((int) $learner->id));
        $this->assertTrue(access::can_view((int) $manager->id));

        $this->setGuestUser();
        $this->assertFalse(access::can_view((int) $learner->id));

        $this->setUser(null);
        $this->assertFalse(access::can_view((int) $learner->id));

        // Withdraw the own-transcript capability from authenticated users.
        $userrole = $DB->get_field('role', 'id', ['shortname' => 'user']);
        assign_capability('report/transcript:view', CAP_PROHIBIT, $userrole, context_system::instance(), true);
        $this->setUser($learner);
        $this->assertFalse(access::can_view((int) $learner->id));
    }

    /**
     * require_view() throws for a refused view and returns quietly for an allowed one.
     */
    public function test_require_view(): void {
        $this->resetAfterTest();
        $learner = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();

        $this->setUser($learner);
        access::require_view((int) $learner->id);

        $this->expectException(\moodle_exception::class);
        access::require_view((int) $other->id);
    }

    /**
     * The capability for others is defined at user context, so it can be granted for one learner.
     */
    public function test_viewall_can_be_granted_per_user(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $learner = $generator->create_user();
        $other = $generator->create_user();
        $viewer = $generator->create_user();
        $roleid = $generator->create_role();
        assign_capability('report/transcript:viewall', CAP_ALLOW, $roleid, context_system::instance(), true);
        $generator->role_assign($roleid, $viewer->id, context_user::instance($learner->id));

        $this->setUser($viewer);
        $this->assertTrue(access::can_view((int) $learner->id));
        $this->assertFalse(access::can_view((int) $other->id));
        $othercontext = context_user::instance($other->id);
        $this->assertSame(0, $DB->count_records('role_assignments', ['userid' => $viewer->id, 'contextid' => $othercontext->id]));
    }
}
