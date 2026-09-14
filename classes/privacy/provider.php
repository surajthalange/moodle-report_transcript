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

use context;
use context_user;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use report_transcript\local\issuer;
use report_transcript\local\outcome;
use report_transcript\local\transcript;

/**
 * Privacy provider.
 *
 * The issue table holds personal data twice: the learner, whose name and results are in
 * the snapshot, and the issuer, who may be a different person. Data lives in the learner's
 * user context. For an issuer who is not the learner only the code and date are theirs;
 * the snapshot is someone else's data and is never exported to them, and deleting the
 * issuer clears their id from the row rather than destroying the learner's record.
 *
 * @package    report_transcript
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Metadata.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(issuer::TABLE, [
            'userid' => 'privacy:metadata:issue:userid',
            'issuerid' => 'privacy:metadata:issue:issuerid',
            'code' => 'privacy:metadata:issue:code',
            'documenttitle' => 'privacy:metadata:issue:documenttitle',
            'snapshot' => 'privacy:metadata:issue:snapshot',
            'timecreated' => 'privacy:metadata:issue:timecreated',
        ], 'privacy:metadata:issue');
        return $collection;
    }

    /**
     * Contexts holding data about a user: the user context of every learner whose issues
     * the user appears in, as learner or as issuer.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $sql = "SELECT ctx.id
                  FROM {report_transcript_issue} i
                  JOIN {context} ctx ON ctx.instanceid = i.userid AND ctx.contextlevel = :userlevel
                 WHERE i.userid = :learner OR i.issuerid = :issuer";
        $contextlist->add_from_sql($sql, ['userlevel' => CONTEXT_USER, 'learner' => $userid, 'issuer' => $userid]);
        return $contextlist;
    }

    /**
     * Users with data in a context: the learner the context belongs to, and anyone who
     * issued for them.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if (!$context instanceof context_user) {
            return;
        }
        $params = ['learner' => $context->instanceid];
        $userlist->add_from_sql('userid', "SELECT userid FROM {report_transcript_issue} WHERE userid = :learner", $params);
        $userlist->add_from_sql(
            'issuerid',
            "SELECT issuerid FROM {report_transcript_issue} WHERE userid = :learner AND issuerid > 0",
            $params
        );
    }

    /**
     * Export.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof context_user) {
                continue;
            }
            $learnerid = (int) $context->instanceid;
            $issues = $DB->get_records(issuer::TABLE, ['userid' => $learnerid], 'timecreated ASC');

            if ($learnerid === $userid) {
                $mine = [];
                foreach ($issues as $issue) {
                    $mine[] = self::export_issue($issue, true);
                }
                if ($mine) {
                    writer::with_context($context)->export_data(
                        [get_string('pluginname', 'report_transcript'), get_string('privacy:export:mine', 'report_transcript')],
                        (object) ['issues' => $mine]
                    );
                }
            }

            $issuedbyme = [];
            foreach ($issues as $issue) {
                if ((int) $issue->issuerid === $userid && $learnerid !== $userid) {
                    $issuedbyme[] = self::export_issue($issue, false);
                }
            }
            if ($issuedbyme) {
                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'report_transcript'), get_string('privacy:export:issuedbyme', 'report_transcript')],
                    (object) ['issues' => $issuedbyme]
                );
            }
        }
    }

    /**
     * One issue in export form.
     *
     * @param \stdClass $issue
     * @param bool $withsnapshot include the rows; only for the learner
     * @return array
     */
    private static function export_issue(\stdClass $issue, bool $withsnapshot): array {
        $out = [
            'code' => $issue->code,
            'documenttitle' => $issue->documenttitle,
            'timecreated' => transform::datetime($issue->timecreated),
        ];
        if ($withsnapshot) {
            $transcript = transcript::from_snapshot(json_decode($issue->snapshot, true));
            $out['fullname'] = $transcript->fullname;
            $out['courses'] = array_map(fn($r) => [
                'course' => $r->coursename,
                'shortname' => $r->shortname,
                'status' => $r->status,
                'timestarted' => $r->timestarted ? transform::datetime($r->timestarted) : null,
                'timecompleted' => $r->timecompleted ? transform::datetime($r->timecompleted) : null,
                'grade' => $r->grade->gradetext,
                'outcome' => outcome::label($r->grade->outcome),
            ], $transcript->rows);
        }
        return $out;
    }

    /**
     * Delete everything in a user context: the learner's issues.
     *
     * @param context $context
     */
    public static function delete_data_for_all_users_in_context(context $context): void {
        global $DB;
        if ($context instanceof context_user) {
            $DB->delete_records(issuer::TABLE, ['userid' => $context->instanceid]);
        }
    }

    /**
     * Delete a user's data: their own issues go; rows they issued for others keep the
     * learner's record and lose the issuer id.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;
        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof context_user) {
                continue;
            }
            if ((int) $context->instanceid === $userid) {
                $DB->delete_records(issuer::TABLE, ['userid' => $userid]);
            } else {
                $DB->set_field(issuer::TABLE, 'issuerid', 0, ['userid' => $context->instanceid, 'issuerid' => $userid]);
            }
        }
    }

    /**
     * Delete several users' data within one context.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;
        $context = $userlist->get_context();
        if (!$context instanceof context_user) {
            return;
        }
        $learnerid = (int) $context->instanceid;
        $userids = array_map('intval', $userlist->get_userids());
        if (in_array($learnerid, $userids, true)) {
            $DB->delete_records(issuer::TABLE, ['userid' => $learnerid]);
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params['learner'] = $learnerid;
        $DB->set_field_select(issuer::TABLE, 'issuerid', 0, "userid = :learner AND issuerid {$insql}", $params);
    }
}
