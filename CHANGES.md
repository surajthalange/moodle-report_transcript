# Changes

## 1.1.3 (2026-11-21)

- Describe `rows_for()` and `is_excluded()` with a plain `array` parameter type. Moodle's
  PHPDoc checker cannot parse the `array<int, true>` generic and reports the parameter
  list as incomplete, which fails CI.
- Give the three privacy deletion tests one named assertion helper instead of repeating
  the same four assertions, which the copy and paste detector counts as a clone.

## 1.1.2 (2026-11-21)

- Declare the archive table's text columns with no default rather than an empty string.
  XMLDB rejects an empty string as the default of a CHAR NOT NULL column, rewrites it to
  none and emits a debugging message while doing so. The resulting column was always
  correct, which is why the table installed cleanly everywhere, but Moodle plugin CI fails
  any job that produces a debugging message during install, so every CI job failed the
  first time this table reached it. Five columns in `db/install.xml` and the same five in
  `db/upgrade.php`.
- No schema change for sites already running 1.1.0 or 1.1.1: their columns were created
  with no default, because XMLDB had already corrected it. This only makes the declaration
  say what the database always did.

## 1.1.1 (2026-11-21)

Archive every transcript line, not only completed courses.

- In-progress courses appear on transcripts whenever `includeinprogress` is on, which is
  the default, and courses without completion tracking appear when `includeuntracked` is
  on. Neither was archived, so such a line vanished when its course was deleted. Both are
  archived now. Found by the client during acceptance testing; the premise in the 1.1.0
  code comment and documentation, that an unfinished course was never a transcript line,
  was simply false.
- Which statuses are listed is applied when the archive is read, not when it is written, so
  turning `includeinprogress` or `includeuntracked` on or off takes effect immediately and
  never calls for a second backfill.
- The pre-deletion sweep now considers everyone with an active enrolment as well as everyone
  with a completion, and drops archived rows for anyone who no longer earns a transcript
  line, which now includes a learner unenrolled from a course they had not completed.
- `backfill::course_ids()` selects courses with an active enrolment as well as courses with
  a completion, so the backfill covers what the archive keeps.
- Documentation corrected on two counts: unfinished courses were described as never being
  transcript lines, and the backfill was described as the thing protecting existing
  completions. The capture before deletion is what protects them, and it does not depend on
  the backfill having run.

## 1.1.0 (2026-11-12)

The transcript archive: completed courses stay on a transcript after the course is deleted.

- Transcripts are built from core's completion records and gradebook, and those belong to
  the course, so deleting a course removed its line from every learner's transcript.
  Documents already downloaded were never affected, because each download freezes its own
  copy with its verification code, but a learner who had not downloaded one lost the record.
- Completions are now archived as they happen, and every learner in a course is archived
  once more immediately before that course is deleted, while its records are still intact,
  so the grades kept are the last ones the course ever had.
- A command line backfill and an ad hoc task cover completions that predate this version.
  Both are batched, resumable and safe to run more than once. Courses deleted before the
  upgrade cannot be recovered.
- While a course exists its live data is used, so a corrected grade or a renamed course
  still shows its current value. The archive is read only for courses that are gone.
- The rules that keep a course off a transcript keep working after deletion: a teacher's own
  courses stay off their transcript, an excluded category stays excluded even once the
  category itself is deleted, and a completion that was revoked does not come back.
- Archived rows are covered by the privacy API. They are declared, included in a learner's
  export, and removed when a learner's data is deleted.
- If archiving fails while a course is being deleted, the deletion stops and reports why
  rather than proceeding silently. Losing the record quietly is the failure this prevents.

## 1.0.2 (2026-10-10)

Moodle 5.3.

- Declared support for Moodle 5.3, which became the current long term support release
  on 5 October 2026, and added it to the CI matrix on PHP 8.3 and 8.4 against both
  databases, taking the matrix to twenty jobs. Those PHP versions are 5.3's own, taken
  from its workflows rather than assumed.
- No code changes were needed. The 5.3 upgrade notes touch the gradebook in two places,
  grade_item::update_deducted_mark() and assign::calculate_penalised_grade(), and this
  plugin calls neither; nothing else it uses changed.

## 1.0.1 (2026-10-03)

Both findings from the Marketplace review of 1.0.0 (MMR-244).

- A course total is now withheld when the course has grades switched off for learners
  (showgrades) or the learner does not hold moodle/grade:view in the course. Before this a
  teacher who had turned the gradebook off, for example before results were released, still
  had the total printed on the page, embedded in the PDF and made verifiable on the public
  verification page. Both halves of core's own test in
  grade_report_overview::setup_courses_data(), asked of the learner rather than of whoever
  is reading the transcript.
- Courses without completion tracking are now checked for visibility like every other row.
  They came from enrol_get_all_users_courses(), which does not filter hidden courses, so a
  hidden course could be listed with its total while the in-progress rule already excluded
  one.
- The cachedef_verifyattempts language string, which db/caches.php declared but the
  language file did not define.

## 1.0.0 (2026-09-14)

First release.

- Transcript page for every learner, reached from their profile: completed courses with
  started and completed dates, the grade as the course chose to show it, and a Pass, Fail
  or Completed outcome. Courses in progress optionally; untracked courses optionally.
- PDF download with the institution's name and logo, and a 24-character verification
  code. Every download issues a fresh code and a snapshot of the rows as issued.
- Public verification page: anyone holding a code sees what was issued, even after the
  course has been deleted. Ten lookups per client address per minute.
- Managers can view and download any learner's transcript from that learner's profile.
- Hidden grades are never shown, to anyone: hidden grades, hidden items, "hidden until"
  dates and totals depending on hidden items all withhold both the grade and the outcome,
  following the overview report's "show totals if they contain hidden items" setting.
- Courses where the user's only roles are not graded roles are excluded, so a teacher's
  own courses do not appear on their transcript.
- Settings: document title, institution name and logo, inclusion of in-progress and
  untracked courses, excluded categories, forced grade display, outcome column, footer text.
- Privacy provider covering the learner and the issuer separately.
- Supports Moodle 4.5 LTS through 5.2 on MySQL/MariaDB and PostgreSQL.
