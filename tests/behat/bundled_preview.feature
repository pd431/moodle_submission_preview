@mod_assign @local @local_submissioncheck @_file_upload @javascript
Feature: Students get a preview of their file even when the site has no document converter
  In order to check what I submitted on any site
  As a student
  I need a preview of Word documents, spreadsheets, text files and presentations drawn by the plugin itself

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
    And I am on the "Essay 1" "assign activity" page logged in as "student1"
    And I press "Add submission"

  Scenario: A Word document is drawn in the browser
    Given I upload "local/submissioncheck/tests/fixtures/essay.docx" file to "File submissions" filemanager
    When I press "Save changes"
    Then the Word preview of "essay.docx" should show "The role of feedback in learning"
    And I should not see "We couldn't preview this type of file."

  Scenario: A spreadsheet is shown as a table with the values the spreadsheet stored
    Given I upload "local/submissioncheck/tests/fixtures/marks.xlsx" file to "File submissions" filemanager
    When I press "Save changes"
    Then the preview of "marks.xlsx" should show "Total"
    And I should see "133"

  Scenario: A presentation gets a text-only preview, slide by slide
    Given I upload "local/submissioncheck/tests/fixtures/linked-media.pptx" file to "File submissions" filemanager
    When I press "Save changes"
    Then I should see "Text only: layout, pictures and formatting aren't shown."
    And the preview of "linked-media.pptx" should show "Slide 1"
    And I should see "Interview findings"

  Scenario: Each file in a submission gets the preview its kind allows
    Given I upload "local/submissioncheck/tests/fixtures/essay.docx" file to "File submissions" filemanager
    And I upload "local/submissioncheck/tests/fixtures/marks.xlsx" file to "File submissions" filemanager
    And I upload "local/submissioncheck/tests/fixtures/essay.rtf" file to "File submissions" filemanager
    When I press "Save changes"
    Then the Word preview of "essay.docx" should show "Feedback helps students"
    And "Preview of marks.xlsx" "local_submissioncheck > Preview" should exist
    And I click on "essay.rtf" "local_submissioncheck > File tab"
    And I should see "We couldn't preview this type of file." in the "Preview of essay.rtf" "local_submissioncheck > Preview"
