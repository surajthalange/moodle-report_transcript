# Changes

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
