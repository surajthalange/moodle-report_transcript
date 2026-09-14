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
 * Tests for issuer.
 *
 * Metadata stays in doc-comments rather than PHP attributes: attributes arrived in
 * PHPUnit 10 and Moodle 4.5, this plugin's floor, ships PHPUnit ^9.6.34.
 *
 * @package    report_transcript
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \report_transcript\local\issuer
 */
final class issuer_test extends \advanced_testcase {
    /**
     * A transcript with one completed row, built from real records.
     *
     * @return array{0: transcript, 1: \stdClass} the transcript and the learner
     */
    private function transcript(): array {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');

        $generator = $this->getDataGenerator();
        $user = $generator->create_user(['firstname' => 'Priya', 'lastname' => 'Nair']);
        $course = $generator->create_course(['enablecompletion' => 1, 'fullname' => 'Ethics']);
        $generator->enrol_user($user->id, $course->id, 'student');
        $this->redirectMessages();
        (new \completion_completion(['course' => $course->id, 'userid' => $user->id]))->mark_complete(1700000000);

        return [(new transcript_builder(settings::defaults()))->build($user), $user];
    }

    /**
     * Issuing stores a row with a well-formed code and a snapshot that round-trips.
     */
    public function test_issue_stores_code_and_snapshot(): void {
        global $DB;
        $this->resetAfterTest();
        [$transcript, $user] = $this->transcript();

        $issue = (new issuer())->issue($transcript, settings::defaults(), (int) $user->id);

        $record = $DB->get_record(issuer::TABLE, ['id' => $issue->id], '*', MUST_EXIST);
        $this->assertSame((int) $user->id, (int) $record->userid);
        $this->assertSame((int) $user->id, (int) $record->issuerid);
        $this->assertSame('Academic transcript', $record->documenttitle);
        $this->assertMatchesRegularExpression('/^[' . issuer::ALPHABET . ']{24}$/', $record->code);
        $this->assertGreaterThan(0, (int) $record->timecreated);

        $rebuilt = (new issuer())->transcript_from($record);
        $this->assertSame('Priya Nair', $rebuilt->fullname);
        $this->assertCount(1, $rebuilt->rows);
        $this->assertSame('Ethics', $rebuilt->rows[0]->coursename);
        $this->assertSame(1700000000, $rebuilt->rows[0]->timecompleted);
    }

    /**
     * Every download issues a new code; nothing is reused.
     */
    public function test_each_issue_has_its_own_code(): void {
        $this->resetAfterTest();
        [$transcript, $user] = $this->transcript();
        $issuer = new issuer();

        $a = $issuer->issue($transcript, settings::defaults(), (int) $user->id);
        $b = $issuer->issue($transcript, settings::defaults(), (int) $user->id);

        $this->assertNotSame($a->code, $b->code);
        $this->assertCount(2, $issuer->issued_for((int) $user->id));
    }

    /**
     * A code is found however a person types it: lower case, with spaces or dashes.
     */
    public function test_find_by_code_forgives_formatting(): void {
        $this->resetAfterTest();
        [$transcript, $user] = $this->transcript();
        $issuer = new issuer();
        $issue = $issuer->issue($transcript, settings::defaults(), (int) $user->id);

        $this->assertSame((int) $issue->id, (int) $issuer->find_by_code($issue->code)->id);
        $this->assertSame((int) $issue->id, (int) $issuer->find_by_code(strtolower($issue->code))->id);
        $this->assertSame((int) $issue->id, (int) $issuer->find_by_code(issuer::format_code($issue->code))->id);
        $this->assertSame((int) $issue->id, (int) $issuer->find_by_code(implode('-', str_split($issue->code, 4)))->id);
    }

    /**
     * An unknown or malformed code finds nothing, without an error.
     */
    public function test_find_by_code_misses(): void {
        $this->resetAfterTest();
        $issuer = new issuer();

        $this->assertNull($issuer->find_by_code(''));
        $this->assertNull($issuer->find_by_code('short'));
        $this->assertNull($issuer->find_by_code(str_repeat('A', 24)));
        $this->assertNull($issuer->find_by_code("' OR 1=1 --"));
    }

    /**
     * issued_for() lists a learner's issues newest first, and nobody else's.
     */
    public function test_issued_for_is_per_learner_newest_first(): void {
        global $DB;
        $this->resetAfterTest();
        [$transcript, $user] = $this->transcript();
        $other = $this->getDataGenerator()->create_user();
        $issuer = new issuer();

        $first = $issuer->issue($transcript, settings::defaults(), (int) $user->id);
        $DB->set_field(issuer::TABLE, 'timecreated', 1000, ['id' => $first->id]);
        $second = $issuer->issue($transcript, settings::defaults(), (int) $user->id);
        $DB->set_field(issuer::TABLE, 'timecreated', 2000, ['id' => $second->id]);

        $list = $issuer->issued_for((int) $user->id);
        $this->assertSame([(int) $second->id, (int) $first->id], array_map(fn($i) => (int) $i->id, $list));
        $this->assertSame([], $issuer->issued_for((int) $other->id));
    }

    /**
     * Codes use only the unambiguous alphabet and format in groups of four.
     */
    public function test_code_generation_and_formatting(): void {
        for ($i = 0; $i < 50; $i++) {
            $this->assertMatchesRegularExpression('/^[' . issuer::ALPHABET . ']{24}$/', issuer::generate_code());
        }
        $this->assertStringNotContainsString('0', issuer::ALPHABET);
        $this->assertStringNotContainsString('O', issuer::ALPHABET);
        $this->assertStringNotContainsString('1', issuer::ALPHABET);
        $this->assertStringNotContainsString('I', issuer::ALPHABET);

        $this->assertSame('ABCD EFGH JKLM NPQR STUV WXYZ', issuer::format_code('ABCDEFGHJKLMNPQRSTUVWXYZ'));
        $this->assertSame('ABCDEFGH', issuer::normalise_code(' ab-cd ef gh '));
    }
}
