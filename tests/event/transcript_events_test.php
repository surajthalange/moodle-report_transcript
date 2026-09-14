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

use context_user;

/**
 * Tests for the two events.
 *
 * Metadata stays in doc-comments rather than PHP attributes: attributes arrived in
 * PHPUnit 10 and Moodle 4.5, this plugin's floor, ships PHPUnit ^9.6.34.
 *
 * @package    report_transcript
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \report_transcript\event\transcript_viewed
 * @covers     \report_transcript\event\transcript_issued
 */
final class transcript_events_test extends \advanced_testcase {
    /**
     * transcript_viewed carries the learner as relateduserid and describes both cases.
     */
    public function test_transcript_viewed(): void {
        $this->resetAfterTest();
        $learner = $this->getDataGenerator()->create_user();
        $manager = $this->getDataGenerator()->create_user();
        $context = context_user::instance($learner->id);

        $this->setUser($learner);
        $sink = $this->redirectEvents();
        transcript_viewed::create(['context' => $context, 'relateduserid' => $learner->id])->trigger();
        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertInstanceOf(transcript_viewed::class, $event);
        $this->assertSame('r', $event->crud);
        $this->assertSame((int) $learner->id, (int) $event->relateduserid);
        $this->assertStringContainsString('their own transcript', $event->get_description());
        $this->assertStringEndsWith('/report/transcript/index.php', $event->get_url()->get_path());
        $this->assertSame((string) $learner->id, $event->get_url()->get_param('userid'));

        $this->setUser($manager);
        $event = transcript_viewed::create(['context' => $context, 'relateduserid' => $learner->id]);
        $this->assertStringContainsString("transcript of the user with id '{$learner->id}'", $event->get_description());
    }

    /**
     * transcript_issued carries the code and row count in other, and nothing else.
     */
    public function test_transcript_issued(): void {
        $this->resetAfterTest();
        $learner = $this->getDataGenerator()->create_user();
        $context = context_user::instance($learner->id);

        $this->setUser($learner);
        $sink = $this->redirectEvents();
        transcript_issued::create([
            'context' => $context,
            'objectid' => 42,
            'relateduserid' => $learner->id,
            'other' => ['code' => 'ABCDEFGHJKLMNPQRSTUVWXYZ', 'rows' => 3],
        ])->trigger();
        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertSame('c', $event->crud);
        $this->assertSame('report_transcript_issue', $event->objecttable);
        $this->assertSame(42, (int) $event->objectid);
        $this->assertSame(['code', 'rows'], array_keys($event->other));
        $this->assertStringContainsString('with 3 course(s)', $event->get_description());
        $this->assertSame('ABCDEFGHJKLMNPQRSTUVWXYZ', $event->get_url()->get_param('code'));
    }

    /**
     * transcript_issued refuses to be created without the code and row count.
     */
    public function test_transcript_issued_requires_other(): void {
        $this->resetAfterTest();
        $learner = $this->getDataGenerator()->create_user();

        $this->expectException(\coding_exception::class);
        transcript_issued::create([
            'context' => context_user::instance($learner->id),
            'objectid' => 1,
            'relateduserid' => $learner->id,
        ]);
    }
}
