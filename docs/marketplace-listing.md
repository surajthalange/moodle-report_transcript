# Moodle Marketplace listing copy

Draft. Not published. Kept here so the wording is version controlled and reviewable
alongside the code it describes, rather than living only in the Marketplace form.

Field limits come from the Moodle Marketplace provider documentation, *Set up and
publish your plugin page*: the short description allows 270 characters but plugin
cards truncate at 119, and the description allows 7,000.

---

## Short description

Character budget: 270 maximum. The **first 119 characters must stand alone**, because
that is all a plugin card shows.

### First 119 characters, as a standalone sentence

> Every learner gets an academic transcript: completed courses, dates, grades and
> outcomes, as a page and a verified PDF.

### Full short description

> Every learner gets an academic transcript: completed courses, dates, grades and
> outcomes, as a page and a verified PDF. Each download carries a code that anyone
> can check on a public page, and the record it verifies survives course deletion.

---

## Description

### The problem it solves

Moodle knows which courses a learner completed, when, and what they scored. What it
does not have is the document: the one thing a learner can download, attach to an
application, and have a third party confirm. The grade overview report shows the
learner a list of grades. Report builder gives administrators rows. Neither produces a
transcript.

This plugin does, and only that.

### What the learner gets

A **Transcript** link on their profile opens one table: course, started, completed,
grade, outcome. **Download PDF** produces an A4 document with the institution's name
and logo, the learner's name, the rows, and a 24-character verification code. Every
download issues a fresh code and freezes a snapshot of the rows as they stood at that
moment.

### What a third party gets

A public verification page. Enter the code from the PDF and see exactly what was
issued: the learner's name, the date, and the rows. The snapshot does not change when a
course is later renamed, archived or deleted, so a transcript issued in one academic
year still verifies in the next.

### What a manager gets

Any learner's transcript, from that learner's profile, with the same download. Teachers
do not get this by default: a teacher's remit is one course, and a transcript spans all
of them.

### Which courses appear

Completed courses always, whether or not the course is still visible. Courses in
progress and courses without completion tracking are each a setting. Courses under
categories you exclude never appear, and neither do courses where the user's only roles
are not graded roles, so a teacher's own courses stay off their transcript.

### Hidden grades stay hidden

A grade the gradebook is hiding from the learner is never shown on the transcript, to
the learner or to a manager. Hidden grades, hidden grade items, "hidden until" dates
and course totals that depend on a hidden item all withhold both the grade and the
outcome, because Pass or Fail would give the grade away. Totals containing hidden items
follow the overview report's own setting, so the transcript never reveals more than the
learner's gradebook would.

### Settings

Document title (rename to "Record of achievement" without a language pack), institution
name and logo, whether to include in-progress and untracked courses, excluded
categories, a forced grade display, the outcome column, and the PDF footer text.

### Accessibility

No JavaScript. Native tables with proper headers; the outcome is always a word, never a
colour alone; the mobile layout is stacked cards with each value labelled. Keyboard
focus is the theme's own.

### Privacy

Each issued transcript stores the learner's id, the issuer's id, the code and a
snapshot of the rows. The learner's privacy export includes every issue with its rows;
a manager who issued for someone else gets only the code and date. Deleting a learner
removes their issues; deleting a manager leaves the learners' records whole.

### Supported versions

Moodle 4.5 LTS, 5.0, 5.1 and 5.2, on MySQL/MariaDB and PostgreSQL. Every combination is
tested on every change.

### Source, licence and support

GPLv3 or later. Source, issue tracker and continuous integration on GitHub.
