@mod_assign @local @local_submissioncheck @_file_upload @javascript
Feature: A damaged file is reported even when nothing about it is recognisable
  In order not to submit a file my marker cannot open
  As a student
  I need to be told when my file is damaged beyond recognition

  Background:
    Given the following "courses" exist:
      | fullname        | shortname | category |
      | Research skills | RS101     | 0        |
    And the following "users" exist:
      | username | firstname | lastname |
      | student1 | Sam       | Student  |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | RS101  | student |
    And the following "activities" exist:
      | activity | name | course | assignsubmission_file_enabled | assignsubmission_file_maxfiles | assignsubmission_file_maxsizebytes | submissiondrafts | submissioncheck_mode | duedate |
      | assign | Essay 1 | RS101 | 1 | 3 | 1048576 | 0 | on | ##+7 days## |

  Scenario: A Word document damaged at its start cannot be opened
    Given I am on the "Essay 1" "assign activity" page logged in as "student1"
    And I press "Add submission"
    And I upload "local/submissioncheck/tests/fixtures/damaged-start.docx" file to "File submissions" filemanager
    When I press "Save changes"
    Then I should see "damaged-start.docx could not be opened. It may be damaged or only partly uploaded."
    And I should see "1 broken item"

  Scenario: A PDF that is only noise cannot be opened
    Given I am on the "Essay 1" "assign activity" page logged in as "student1"
    And I press "Add submission"
    And I upload "local/submissioncheck/tests/fixtures/noise.pdf" file to "File submissions" filemanager
    When I press "Save changes"
    Then I should see "noise.pdf could not be opened. It may be damaged or only partly uploaded."

  Scenario: A renamed web page keeps its own message
    Given I am on the "Essay 1" "assign activity" page logged in as "student1"
    And I press "Add submission"
    And I upload "local/submissioncheck/tests/fixtures/renamed.docx" file to "File submissions" filemanager
    When I press "Save changes"
    Then I should see "renamed.docx is named as"
    And I should not see "could not be opened"

  Scenario: A real MP3 is still recognised as sound
    Given I am on the "Essay 1" "assign activity" page logged in as "student1"
    And I press "Add submission"
    And I upload "local/submissioncheck/tests/fixtures/clip.mp3" file to "File submissions" filemanager
    When I press "Save changes"
    Then I should see "Check this is the right file"
    And I should not see "broken item"

  Scenario: A plain Word document is not affected
    Given I am on the "Essay 1" "assign activity" page logged in as "student1"
    And I press "Add submission"
    And I upload "local/submissioncheck/tests/fixtures/essay.docx" file to "File submissions" filemanager
    When I press "Save changes"
    Then I should see "Check this is the right file"
    And I should not see "broken item"
