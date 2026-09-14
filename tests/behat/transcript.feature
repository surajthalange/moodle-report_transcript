@report @report_transcript
Feature: Learners can see and share their transcript
  In order to show what I have completed
  As a learner
  I need a transcript I can view, download and have verified

  Background:
    Given the following "courses" exist:
      | fullname            | shortname | enablecompletion |
      | Biology 101         | BIO101    | 1                |
      | Organic Chemistry   | OCHEM     | 1                |
      | Teaching Practicum  | TEACH     | 1                |
    And the following "users" exist:
      | username | firstname | lastname |
      | student1 | Asha      | Rao      |
      | student2 | Ben       | Ortiz    |
      | manager1 | Mira      | Manager  |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | BIO101 | student        |
      | student1 | OCHEM  | student        |
      | student1 | TEACH  | editingteacher |
      | student2 | BIO101 | student        |
    And the following "system role assigns" exist:
      | user     | role    |
      | manager1 | manager |
    And the following "grade items" exist:
      | course | itemname | grademax |
      | BIO101 | Essay    | 100      |
    And the following "grade grades" exist:
      | gradeitem | user     | grade |
      | Essay     | student1 | 80    |
    And the user "student1" completed the course "BIO101" on "2026-04-24"
    And the user "student1" completed the course "TEACH" on "2026-05-10"

  Scenario: A learner sees their own completed and in-progress courses, and not the course they teach
    Given I log in as "student1"
    When I visit "/report/transcript/index.php"
    Then I should see "Academic transcript"
    And I should see "1 completed, 1 in progress"
    And the following should exist in the "report-transcript-table" table:
      | Course            | Completed     | Grade | Outcome     |
      | Biology 101       | 24 April 2026 | 80.00 | Completed   |
      | Organic Chemistry | —             | —     | In progress |
    And I should not see "Teaching Practicum"
    And I should see "Download PDF"

  Scenario: A learner without the capability has no transcript link on their profile
    Given I log in as "student1"
    And I am on the "student1" "user > profile" page
    And I should see "Transcript"
    And I log out
    And the following "role capabilities" exist:
      | role | report/transcript:view |
      | user | prohibit               |
    When I log in as "student1"
    And I am on the "student1" "user > profile" page
    Then I should not see "Transcript"

  Scenario: A manager opens another learner's transcript from their profile
    Given I log in as "manager1"
    When I am on the "student1" "user > profile" page
    And I follow "Transcript"
    Then I should see "You are viewing this transcript as a manager"
    And I should see "Biology 101"
    And I should see "Asha Rao"

  Scenario: Anyone holding a code can verify what was issued
    Given a transcript has been issued for "student1" with code "ABCDEFGHJKLMNPQRSTUVWXYZ"
    When I visit "/report/transcript/verify.php"
    And I set the field "Verification code" to "abcd-efgh-jklm-npqr-stuv-wxyz"
    And I press "Verify"
    Then I should see "This transcript was issued on"
    And I should see "Asha Rao"
    And I should see "Biology 101"
    And I should see "exactly as they were issued"

  Scenario: An unknown code verifies nothing
    When I visit "/report/transcript/verify.php?code=AAAAAAAAAAAAAAAAAAAAAAAA"
    Then I should see "No transcript matches this code."
    And I should not see "Biology 101"
