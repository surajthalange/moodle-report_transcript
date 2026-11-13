# The transcript archive

Transcripts are built from Moodle's own completion records and gradebook. Those records
belong to the course, so when a course is deleted the lines that came from it disappear from
every learner's transcript.

The archive fixes that. As learners complete courses, and again immediately before a course
is deleted, the plugin keeps its own copy of what each transcript line said. If the course
is later removed, the line stays.

## What it does and does not do

**It keeps:**

- Every course a learner completed while this feature was installed.
- The course name and short name as they were, the start and completion dates, the grade as
  it was shown, and the pass, fail or completed outcome.
- Everything already captured by a downloaded PDF. Those were always safe, because each
  download freezes its own copy with its verification code. Codes issued before a deletion
  keep working afterwards, and always did.

**It does not keep:**

- **Courses deleted before this version was installed.** Nothing can bring those back. The
  records they were built from are already gone.
- Courses a learner started but never completed. An unfinished course was never a line on a
  transcript, so there is nothing to preserve.
- Courses that would not have appeared anyway. If a learner is a teacher rather than a
  student in a course, or the course sits in a category your settings exclude, it stays off
  the transcript after deletion exactly as it was before.

**Important:** while a course still exists, the live data is always used. If somebody
corrects a grade next term, the transcript shows the corrected grade. The archived copy is
only consulted for courses that no longer exist.

## Install and upgrade

This version is 1.1.0. It upgrades in place from any earlier 1.0.x release, and it installs
cleanly on a site that has never had the plugin.

### Which Moodle versions this runs on

It requires Moodle 4.5 and supports 4.5 through 5.3. One single build covers that whole
range, so while you move instances to newer Moodle you do not need different plugin
versions for different instances, and you can upgrade Moodle and the plugin in either
order.

**Moodle 4.1 to 4.4 cannot run this plugin.** That has been true of every released version,
including the one already on your instances, so this change does not alter it. Instances
below 4.5 will refuse to install it until they are upgraded.

### Before you start

1. Back up the database and the `report/transcript` directory. Please do not skip this. See
   the rollback note below for why it matters more than usual.
2. Note the plugin version you are on now, from **Site administration > Plugins > Plugins
   overview**. You will want it if you ever need to compare.

### The upgrade, per instance

Each Moodle instance is independent, so do them one at a time and in any order that suits
you. An instance that has not been upgraded yet keeps working exactly as it does today.

1. Put the site into maintenance mode.
2. Replace the whole `report/transcript` directory with this version. Replace it rather than
   copying over the top, so that no file from the previous version is left behind.
3. Visit **Site administration > Notifications**, or run `php admin/cli/upgrade.php`, and
   complete the upgrade.
4. Take the site out of maintenance mode.
5. Run the one-time backfill, described in the next section. This is per instance, so it
   needs running once on each site you upgrade.

### Checking it worked

Two things confirm a successful upgrade:

1. **Site administration > Plugins > Plugins overview** shows Transcript at version 1.1.0.
2. A learner's transcript still lists their completed courses, exactly as before. Nothing
   about the existing transcript should look different, because nothing about it changed.

The upgrade adds one database table and changes nothing that already exists. It adds no
settings and no new permissions, so your existing configuration carries over untouched, and
existing transcripts, issued PDFs and verification codes are unaffected.

### If you need to roll back

Rolling back is not simply a matter of putting the old files back. Moodle records the
installed version of every plugin in its database, and if it finds older code than the
version it has recorded, it reports a downgrade and refuses to continue. So a file-only
rollback leaves the site unable to complete any upgrade until it is resolved.

To roll back properly, restore the database backup and the previous `report/transcript`
directory together, as one operation. This is the reason the backup above matters.

Be aware of what rolling back costs you: the archived records stay in the database, but the
earlier version does not read them, so transcript lines for courses that have since been
deleted would disappear again. If you later reinstall 1.1.0, those archived records are
still there and the lines come back.

## The one-time backfill

The plugin only sees completions from the moment it is installed. Everything that happened
before that needs one pass to be archived. Until you run it, older completions are still at
risk if their course is deleted.

### From the command line, recommended

```
php report/transcript/cli/backfill_archive.php
```

It prints each course as it goes. To see the size of the job first without writing anything:

```
php report/transcript/cli/backfill_archive.php --dry-run
```

If you need to stop it, note the last course id it printed and resume with:

```
php report/transcript/cli/backfill_archive.php --after=1234
```

Running it more than once is safe. Each write replaces the row for that learner and course,
so a second run changes nothing except a timestamp.

### Letting cron do it instead

On a large site you may prefer not to hold a command open. Queue the work instead:

```
php report/transcript/cli/backfill_archive.php --queue
```

That hands it to the task **Archive completed courses for transcripts**, which archives
fifty courses each time cron runs and queues itself again until it is finished. You can
watch it under **Site administration > Server > Tasks > Ad hoc tasks**.

Both routes do the same work and both are safe to run more than once. Queuing needs the
command line once, because Moodle has no screen for starting an ad hoc task by hand.

### How long to expect

The work is one pass per course that has ever recorded a completion, and within each course
one row per learner who completed it. As a guide, a site with 500 such courses and 50,000
completions takes roughly fifteen to forty minutes, depending on the database and the
server. The `--dry-run` count tells you how many courses are involved before you start.

The backfill only reads completion and grade data and writes to its own table. It is safe to
run on a live site, though a quiet period is kinder to the database.

## Testing it on your staging site

Each of these can be done by one administrator in a few minutes.

### 1. A deleted course keeps its transcript line

1. Enrol a test learner on a course with completion tracking enabled.
2. Complete the course for them.
3. Open the learner's profile and choose **Transcript**. Confirm the course is listed with
   its dates, grade and outcome.
4. Download the PDF and note the verification code printed at the bottom.
5. Delete the course entirely, through **Site administration > Courses**.
6. Open the learner's transcript again. **The course must still be listed**, with the same
   name, dates, grade and outcome.
7. Open `/report/transcript/verify.php`, enter the code from step 4, and confirm the
   document still appears with that course on it. Do this while signed out, to prove no
   account is needed.

### 2. A grade corrected in a live course still updates

1. Complete a different course for the same learner.
2. Check the transcript and note the grade.
3. Change that learner's course total in the gradebook.
4. Open the transcript again. **The new grade must be shown**, not the one from step 2.

This confirms the archive is not being used while the course exists.

### 3. A privacy deletion removes the archive too

1. Pick a learner who has at least one completed course.
2. Go to **Site administration > Users > Privacy and policies > Data requests**.
3. Create and approve a **Delete all of my personal data** request for that learner.
4. After the request is processed, open their transcript. **No archived courses may
   remain.**

If you have database access, the same check is:

```sql
SELECT COUNT(*) FROM mdl_report_transcript_archive WHERE userid = <the user id>;
```

which must return 0. Replace `mdl_` with your own table prefix.

### 4. A course deleted before installing is not recovered

This is expected behaviour, not a fault. If you want to confirm it, pick a course you
deleted before installing this version and check that it does not appear. There is no way to
recover those records.

## If a deletion is refused

Deleting a course runs the archive first. If that fails for any reason, the deletion stops
and the error is shown rather than hidden. This is deliberate: letting the course go while
the archive quietly failed would lose exactly the records this feature exists to protect.
Fix the reported problem and delete the course again.

## Changelog

### 1.1.0

- Added a transcript archive so that completed courses stay on a learner's transcript after
  the course is deleted from the site.
- Completions are archived as they happen, and every learner in a course is archived once
  more immediately before that course is deleted, so the last grades are the ones kept.
- Added a command line backfill and an ad hoc task for completions that predate this
  version. Both are safe to run more than once.
- While a course exists the live data is used, so corrected grades and renamed courses
  continue to show their current values.
- Archived rows are covered by the privacy API: they are declared, included in a learner's
  data export, and removed when a learner's data is deleted.
- Verification codes issued before a course was deleted continue to resolve, as they always
  have, and there is now a test that keeps it that way.
