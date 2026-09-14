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

namespace report_transcript\privacy;

use context_user;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\tests\provider_testcase;
use report_transcript\local\issuer;
use report_transcript\local\settings;
use report_transcript\local\transcript_builder;

/**
 * Tests for the privacy provider, against a learner with issues and a manager who issued
 * for others, as the PRD requires.
 *
 * Metadata stays in doc-comments rather than PHP attributes: attributes arrived in
 * PHPUnit 10 and Moodle 4.5, this plugin's floor, ships PHPUnit ^9.6.34.
 *
 * @package    report_transcript
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \report_transcript\privacy\provider
 */
final class provider_test extends provider_testcase {
    /** @var \stdClass A learner with issues in their own name. */
    private \stdClass $learner;

    /** @var \stdClass A second learner. */
    private \stdClass $other;

    /** @var \stdClass A manager who issued for both learners and has none of their own. */
    private \stdClass $manager;

    /**
     * Two learners each with a completed course; the learner self-issues once, and the
     * manager issues once for each learner.
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        require_once($CFG->libdir . '/completionlib.php');
        $this->redirectMessages();

        $generator = $this->getDataGenerator();
        $this->learner = $generator->create_user(['firstname' => 'Lea', 'lastname' => 'Learner']);
        $this->other = $generator->create_user();
        $this->manager = $generator->create_user();

        foreach ([$this->learner, $this->other] as $user) {
            $course = $generator->create_course(['enablecompletion' => 1, 'fullname' => 'Ethics']);
            $generator->enrol_user($user->id, $course->id, 'student');
            (new \completion_completion(['course' => $course->id, 'userid' => $user->id]))->mark_complete(1700000000);
        }

        $issuer = new issuer();
        $settings = settings::defaults();
        $builder = new transcript_builder($settings);
        $issuer->issue($builder->build($this->learner), $settings, (int) $this->learner->id);
        $issuer->issue($builder->build($this->learner), $settings, (int) $this->manager->id);
        $issuer->issue($builder->build($this->other), $settings, (int) $this->manager->id);
    }

    /**
     * The metadata names the table and every field.
     */
    public function test_get_metadata(): void {
        $collection = provider::get_metadata(new collection('report_transcript'));
        $items = $collection->get_collection();

        $this->assertCount(1, $items);
        $this->assertSame(issuer::TABLE, $items[0]->get_name());
        $this->assertSame(
            ['userid', 'issuerid', 'code', 'documenttitle', 'snapshot', 'timecreated'],
            array_keys($items[0]->get_privacy_fields())
        );
    }

    /**
     * A learner's context is their own; a manager's are the contexts of everyone they issued for.
     */
    public function test_get_contexts_for_userid(): void {
        $learnerctx = context_user::instance($this->learner->id)->id;
        $otherctx = context_user::instance($this->other->id)->id;

        $this->assertEquals([$learnerctx], provider::get_contexts_for_userid($this->learner->id)->get_contextids());
        $this->assertEqualsCanonicalizing(
            [$learnerctx, $otherctx],
            array_map('intval', provider::get_contexts_for_userid($this->manager->id)->get_contextids())
        );
        $this->assertSame([], provider::get_contexts_for_userid($this->getDataGenerator()->create_user()->id)->get_contextids());
    }

    /**
     * A learner's context lists the learner and whoever issued for them.
     */
    public function test_get_users_in_context(): void {
        $userlist = new userlist(context_user::instance($this->learner->id), 'report_transcript');
        provider::get_users_in_context($userlist);

        $this->assertEqualsCanonicalizing([(int) $this->learner->id, (int) $this->manager->id], $userlist->get_userids());
    }

    /**
     * The learner's export carries their issues with the rows; the manager's export for
     * the same context carries only code and date, never the learner's grades.
     */
    public function test_export_user_data(): void {
        $context = context_user::instance($this->learner->id);

        // Learner.
        provider::export_user_data(new approved_contextlist($this->learner, 'report_transcript', [$context->id]));
        $writer = writer::with_context($context);
        $mine = $writer->get_data(['Transcript', 'Transcripts issued in your name']);
        $this->assertCount(2, $mine->issues, 'Self-issue and the manager\'s issue are both in the learner\'s name');
        $this->assertSame('Lea Learner', $mine->issues[0]['fullname']);
        $this->assertSame('Ethics', $mine->issues[0]['courses'][0]['course']);
        $this->assertSame('Completed', $mine->issues[0]['courses'][0]['outcome']);
        $this->assertEmpty($writer->get_data(['Transcript', 'Transcripts you issued for other users']));

        // Manager, same context.
        writer::reset();
        provider::export_user_data(new approved_contextlist($this->manager, 'report_transcript', [$context->id]));
        $writer = writer::with_context($context);
        $byme = $writer->get_data(['Transcript', 'Transcripts you issued for other users']);
        $this->assertCount(1, $byme->issues);
        $this->assertArrayNotHasKey('courses', $byme->issues[0], 'Another learner\'s rows are not the issuer\'s data');
        $this->assertArrayNotHasKey('fullname', $byme->issues[0]);
        $this->assertEmpty($writer->get_data(['Transcript', 'Transcripts issued in your name']));
    }

    /**
     * Deleting a learner removes their issues; deleting a manager clears their id from
     * rows they issued and leaves the learners' records intact.
     */
    public function test_delete_data_for_user(): void {
        global $DB;
        $learnerctx = context_user::instance($this->learner->id);
        $otherctx = context_user::instance($this->other->id);

        provider::delete_data_for_user(
            new approved_contextlist($this->manager, 'report_transcript', [$learnerctx->id, $otherctx->id])
        );
        $this->assertSame(2, $DB->count_records(issuer::TABLE, ['userid' => $this->learner->id]), 'Learner records kept');
        $this->assertSame(1, $DB->count_records(issuer::TABLE, ['userid' => $this->other->id]));
        $this->assertSame(0, $DB->count_records(issuer::TABLE, ['issuerid' => $this->manager->id]), 'Manager no longer named');
        $this->assertSame(2, $DB->count_records(issuer::TABLE, ['issuerid' => 0]));

        provider::delete_data_for_user(new approved_contextlist($this->learner, 'report_transcript', [$learnerctx->id]));
        $this->assertSame(0, $DB->count_records(issuer::TABLE, ['userid' => $this->learner->id]));
        $this->assertSame(1, $DB->count_records(issuer::TABLE, ['userid' => $this->other->id]), 'Unrelated learner untouched');
    }

    /**
     * Deleting everything in a learner's context removes their issues only.
     */
    public function test_delete_data_for_all_users_in_context(): void {
        global $DB;

        provider::delete_data_for_all_users_in_context(context_user::instance($this->learner->id));

        $this->assertSame(0, $DB->count_records(issuer::TABLE, ['userid' => $this->learner->id]));
        $this->assertSame(1, $DB->count_records(issuer::TABLE, ['userid' => $this->other->id]));
    }

    /**
     * Bulk deletion in a context: the learner among the users empties it; only issuers
     * among them are unnamed.
     */
    public function test_delete_data_for_users(): void {
        global $DB;
        $learnerctx = context_user::instance($this->learner->id);

        provider::delete_data_for_users(new approved_userlist($learnerctx, 'report_transcript', [(int) $this->manager->id]));
        $this->assertSame(2, $DB->count_records(issuer::TABLE, ['userid' => $this->learner->id]));
        $this->assertSame(1, $DB->count_records(issuer::TABLE, ['userid' => $this->learner->id, 'issuerid' => 0]));

        provider::delete_data_for_users(new approved_userlist($learnerctx, 'report_transcript', [(int) $this->learner->id]));
        $this->assertSame(0, $DB->count_records(issuer::TABLE, ['userid' => $this->learner->id]));
        $this->assertSame(1, $DB->count_records(issuer::TABLE, ['userid' => $this->other->id]));
    }
}
