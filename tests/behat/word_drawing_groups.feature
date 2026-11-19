@mod_assign @local_submissioncheck @_file_upload @javascript
Feature: Pictures inside Word drawing groups appear in the preview
  In order to check my document's pictures before confirming
  As a student
  I need pictures that Word keeps inside drawing groups to show in the preview

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
    And the submission check converter is unavailable

  Scenario: A picture inside a drawing group is drawn, and a shapes-only drawing says it is not shown
    Given I am on the "Essay 1" "assign activity" page logged in as "student1"
    And I press "Add submission"
    And I upload "local/submissioncheck/tests/fixtures/grouped-pictures.docx" file to "File submissions" filemanager
    When I press "Save changes"
    Then the Word preview of "grouped-pictures.docx" should show 1 picture
    And the Word preview of "grouped-pictures.docx" should show "Drawing not shown in this preview. Your marker will see it."
    And I should see "Check this is the right file"
    And I should not see "broken item"

  Scenario: The text of a grouped text box is in the preview
    Given I am on the "Essay 1" "assign activity" page logged in as "student1"
    And I press "Add submission"
    And I upload "local/submissioncheck/tests/fixtures/grouped-pictures.docx" file to "File submissions" filemanager
    When I press "Save changes"
    Then the Word preview of "grouped-pictures.docx" should show "Caption inside the group"

  Scenario: A plain Word document shows no grey box
    Given I am on the "Essay 1" "assign activity" page logged in as "student1"
    And I press "Add submission"
    And I upload "local/submissioncheck/tests/fixtures/essay.docx" file to "File submissions" filemanager
    When I press "Save changes"
    Then the Word preview of "essay.docx" should show "The role of feedback in learning"
    And the Word preview of "essay.docx" should not show "Drawing not shown in this preview."
