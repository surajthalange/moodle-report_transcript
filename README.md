# Transcript

[![Moodle Plugin CI](https://github.com/surajthalange/moodle-report_transcript/actions/workflows/ci.yml/badge.svg)](https://github.com/surajthalange/moodle-report_transcript/actions/workflows/ci.yml)

A Moodle report plugin that gives every learner an academic transcript: the courses they
have completed, with dates, the final grade as each course chose to show it, and a pass or
fail outcome. Delivered as a page, as a branded PDF carrying a verification code, and as a
public verification page where anyone holding a code can confirm what was issued.

- **Component:** `report_transcript`
- **Moodle support:** 4.5 LTS floor; targets 4.5, 5.0, 5.1, 5.2
- **Licence:** GPLv3 or later
- **Databases:** MariaDB/MySQL and PostgreSQL, both tested on every supported branch

## What it does

A learner opens **Transcript** from their profile page and sees one table: course, started,
completed, grade, outcome. **Download PDF** produces an A4 document with the institution's
name and logo, the learner's name, the rows, and a 24-character verification code. Every
download issues a fresh code and freezes a snapshot of the rows as they stood.

Anyone with a code can open `/report/transcript/verify.php`, enter it, and see exactly what
was issued: the learner's name, the date, and the rows. The snapshot does not change when a
course is later renamed, archived or deleted.

Managers can open and download any learner's transcript from that learner's profile.

## Where it sits beside core

| | Grade overview report (core) | Report builder (core) | **Transcript** |
|---|---|---|---|
| Audience | The learner | Administrators | The learner, and managers |
| Shape | One row per course, grade only | Rows, any columns, CSV | One document per person |
| Dates and outcome | No | Dates only | Yes |
| Download | No | CSV | PDF, branded |
| Verifiable by a third party | No | No | Yes, by code |
| Survives course deletion | No | No | The issued snapshot does |

This is deliberately not a bulk export tool. Administrators who want rows for many users
already have Report builder: the *Course participants* datasource exposes completion dates
and the course grade. This plugin is the document.

## Which courses appear

1. **Completed courses**: every completion record, whether or not the course is still
   visible. A course later hidden is still one the learner completed.
2. **Courses in progress** (setting, on by default): active enrolments in visible courses
   that track completion and are not yet complete.
3. **Courses without completion tracking** (setting, off by default): shown with the outcome
   "Not tracked".

Never shown: the front page; courses in the excluded categories you configure, including
their subcategories; and courses where the user's only roles are not graded roles, judged by
the site's *graded roles* setting, the same rule the gradebook uses. A teacher's own courses
do not appear on their transcript. A completed course the learner has since been unenrolled
from still does: the completion record is the evidence.

## Grades and hidden grades

The grade follows each course's own grade display type unless the site setting forces real,
percentage or letter. The outcome is **Pass** or **Fail** where the course grade item defines
a grade to pass, and **Completed** otherwise.

**A grade the gradebook hides from the learner is never shown on the transcript**, to the
learner or to a manager. Hidden grades, hidden grade items, "hidden until" dates and course
totals that depend on a hidden item all show as "—" for grade *and* outcome, because Pass or
Fail would give the grade away. Totals containing hidden items follow the overview report's
*Show totals if they contain hidden items* setting, so the transcript never reveals more
than the learner's own gradebook.

## Settings

Site administration → Plugins → Reports → Transcript.

| Setting | Default | Purpose |
|---|---|---|
| Document title | Academic transcript | Page heading and PDF title. Rename to "Record of achievement" without a language pack. |
| Institution name | Site full name | PDF header |
| Institution logo | none | PDF header, scaled to 18 mm high |
| Include courses in progress | on | |
| Include courses without completion tracking | off | |
| Excluded course categories | none | Including subcategories |
| Grade display | Course setting | Or force real / percentage / letter |
| Show outcome column | on | |
| PDF footer text | verification sentence | `{code}`, `{verifyurl}` and `{institution}` are replaced |

## Capabilities

- `report/transcript:view` — view and download your own transcript. Given to every
  authenticated user by default; never to guests.
- `report/transcript:viewall` — view and download any user's transcript. Given to managers
  by default. Defined at user context, so it can also be granted for a single learner.

Teachers do not get `viewall` by default: a teacher's remit is one course, and a transcript
spans all of them.

## The verification page is public

`verify.php` does not require login, deliberately, so it stays reachable on sites that
force login elsewhere. It reveals the learner's name and rows to anyone holding the code,
which is the point of a verification code and is stated on the PDF that carries it. Lookups
are limited to ten per client address per minute; the miss and the refusal are both neutral
sentences.

To put the page somewhere learners and employers can find it, add it to the custom menu:
`Verify a transcript|/report/transcript/verify.php`.

## Privacy

Each issued transcript stores the learner's id, the issuer's id, the code, the document
title at the time, and a JSON snapshot of the rows. Data lives in the learner's user context.
The learner's privacy export includes every issue with its rows; a manager who issued for
someone else gets only the code and date. Deleting a learner removes their issues; deleting
a manager clears their id from issues they made for others and leaves the learner's record
whole.

## Installation

### From a ZIP package

Site administration → Plugins → Install plugins, upload the ZIP, and follow the prompts.

### From git

Moodle 5.1 and later moved the codebase under `public/` (MDL-83424), so the path differs:

    # Moodle 5.1+
    git clone https://github.com/surajthalange/moodle-report_transcript.git \
      <moodle>/public/report/transcript

    # Moodle 4.5 - 5.0
    git clone https://github.com/surajthalange/moodle-report_transcript.git \
      <moodle>/report/transcript

The directory must be named `transcript`, not the repository name.

## Prior art

Core's grade overview report lists a learner's course grades; core's Report builder gives
administrators rows. [Custom certificate](https://marketplace.moodle.com/plugins/mod_customcert)
by Mark Nelson established the pattern of a verifiable, downloadable PDF with a code, per
course. This plugin is a fresh implementation; no source is copied from any of them.

## Reporting bugs and requesting features

<https://github.com/surajthalange/moodle-report_transcript/issues>

Bug reports are most useful with the Moodle version, the PHP version, the database engine,
and whether debugging was on.

## Licence

GNU General Public License v3 or later. The full text ships in [COPYING.txt](COPYING.txt),
and every source file carries the standard Moodle GPL header.

Copyright 2026 Suraj Thalange.

## Development

Checks, from a `moodle-plugin-ci` installation:

    php ci/bin/moodle-plugin-ci phplint <path-to-plugin>
    php ci/bin/moodle-plugin-ci phpcs --max-warnings 0 <path-to-plugin>
    php ci/bin/moodle-plugin-ci phpcpd <path-to-plugin>
    php ci/bin/moodle-plugin-ci validate --moodle=<moodle> <path-to-plugin>

Unit tests, from the Moodle root:

    php public/admin/tool/phpunit/cli/init.php
    php vendor/phpunit/phpunit/phpunit --testsuite report_transcript_testsuite

Acceptance tests need a second wwwroot and a matching ChromeDriver:

    php public/admin/tool/behat/cli/init.php
    php vendor/behat/behat/bin/behat --config <behat_dataroot>/behatrun/behat/behat.yml \
        --profile chrome --tags @report_transcript
