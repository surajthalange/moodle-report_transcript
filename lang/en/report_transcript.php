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
 * Language strings.
 *
 * @package    report_transcript
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['col:completed'] = 'Completed';
$string['col:course'] = 'Course';
$string['col:grade'] = 'Grade';
$string['col:outcome'] = 'Outcome';
$string['col:started'] = 'Started';
$string['defaultdocumenttitle'] = 'Academic transcript';
$string['download'] = 'Download PDF';
$string['downloadnote'] = 'Downloading issues a verification code that anyone can use to confirm this transcript is genuine.';
$string['event:transcriptissued'] = 'Transcript issued';
$string['event:transcriptviewed'] = 'Transcript viewed';
$string['issuedcodes'] = 'Previously issued codes';
$string['issuedon'] = 'Issued {$a}';
$string['navigationlink'] = 'Transcript';
$string['notavailable'] = '—';
$string['outcome:completed'] = 'Completed';
$string['outcome:fail'] = 'Fail';
$string['outcome:inprogress'] = 'In progress';
$string['outcome:pass'] = 'Pass';
$string['outcome:untracked'] = 'Not tracked';
$string['pdf:code'] = 'Verification code';
$string['pdf:filename'] = 'transcript-{$a->username}-{$a->code}.pdf';
$string['pdf:issuedon'] = 'Issued on';
$string['pdf:issuedto'] = 'Issued to';
$string['pdf:page'] = 'Page {$a->page} of {$a->total}';
$string['pluginname'] = 'Transcript';
$string['privacy:export:issuedbyme'] = 'Transcripts you issued for other users';
$string['privacy:export:mine'] = 'Transcripts issued in your name';
$string['privacy:metadata:issue'] = 'A record of each transcript issued, kept so that a verification code can be checked later.';
$string['privacy:metadata:issue:code'] = 'The verification code.';
$string['privacy:metadata:issue:documenttitle'] = 'The document title at the time of issue.';
$string['privacy:metadata:issue:issuerid'] = 'The user who triggered the issue.';
$string['privacy:metadata:issue:snapshot'] = 'The learner\'s name and, for each course, the course name, dates, grade and outcome as they were issued.';
$string['privacy:metadata:issue:timecreated'] = 'When the transcript was issued.';
$string['privacy:metadata:issue:userid'] = 'The learner the transcript is about.';
$string['settings:documenttitle'] = 'Document title';
$string['settings:documenttitle_desc'] = 'The heading on the transcript page and the title printed on the PDF. Institutions that prefer "Record of achievement" can change it here without a language pack.';
$string['settings:excludedcategories'] = 'Excluded course categories';
$string['settings:excludedcategories_desc'] = 'Courses in these categories, and in any of their subcategories, never appear on a transcript. Useful for sandbox, template and staff-training categories.';
$string['settings:footertext'] = 'PDF footer text';
$string['settings:footertext_default'] = 'This transcript was issued by {institution} and can be verified at {verifyurl} using the code {code}.';
$string['settings:footertext_desc'] = 'Printed at the foot of every PDF page. {code} is replaced by the verification code and {verifyurl} by the address of the verification page.';
$string['settings:gradedisplay'] = 'Grade display';
$string['settings:gradedisplay:course'] = 'Course setting';
$string['settings:gradedisplay_desc'] = 'How the final grade is shown. "Course setting" follows each course\'s own grade display type, so a course that shows letters in its gradebook shows letters here too.';
$string['settings:includeinprogress'] = 'Include courses in progress';
$string['settings:includeinprogress_desc'] = 'List courses the learner is actively enrolled in but has not yet completed. Only courses with completion tracking enabled qualify.';
$string['settings:includeuntracked'] = 'Include courses without completion tracking';
$string['settings:includeuntracked_desc'] = 'List active enrolments in courses that do not track completion. They appear with the outcome "Not tracked" and no completion date. Off by default because on most sites these rows are noise.';
$string['settings:institutionlogo'] = 'Institution logo';
$string['settings:institutionlogo_desc'] = 'Printed in the PDF header beside the institution name. A wide image works best; it is scaled to 18mm high.';
$string['settings:institutionname'] = 'Institution name';
$string['settings:institutionname_desc'] = 'Printed at the top of the PDF. Leave empty to use the site\'s full name.';
$string['settings:showoutcome'] = 'Show outcome column';
$string['settings:showoutcome_desc'] = 'The outcome is "Pass" or "Fail" where the course grade item defines a grade to pass, and "Completed" otherwise. Turn this off to omit the column entirely.';
$string['summary'] = '{$a->completed} completed, {$a->inprogress} in progress';
$string['summarycompletedonly'] = '{$a} completed';
$string['summaryempty'] = 'No courses to show yet. Completed courses appear here once their completion has been recorded.';
$string['transcript:view'] = 'View and download your own transcript';
$string['transcript:viewall'] = 'View and download any user\'s transcript';
$string['transcriptfor'] = 'Transcript for {$a}';
$string['verify:asissued'] = 'The rows below are exactly as they were issued and may differ from the learner\'s current record.';
$string['verify:code'] = 'Verification code';
$string['verify:found'] = 'This transcript was issued on {$a->date} to {$a->name}.';
$string['verify:intro'] = 'Enter the verification code printed on the transcript.';
$string['verify:notfound'] = 'No transcript matches this code.';
$string['verify:ratelimited'] = 'Too many verification attempts. Please wait a minute and try again.';
$string['verify:submit'] = 'Verify';
$string['verify:title'] = 'Verify a transcript';
$string['viewingasmanager'] = 'You are viewing this transcript as a manager. Downloading it issues a verification code in the learner\'s name.';
