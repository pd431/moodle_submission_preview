@mod_assign @local_submissioncheck @_file_upload @javascript
Feature: Students review each submitted file on its own page before confirming
  In order to check every file I submitted without scrolling past previews to find the answers
  As a student
  I need each file's checks, facts and preview together, and the answers always in reach

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

  Scenario: Saving a submission opens the review page with the answers in a bar
    Given I am on the "Essay 1" "assign activity" page logged in as "student1"
    And I press "Add submission"
    And I upload "local/submissioncheck/tests/fixtures/essay.docx" file to "File submissions" filemanager
    When I press "Save changes"
    Then I should see "Check your submission"
    And I should see "This stays your submission time unless you replace the file."
    And I should see "Confirm by"
    And "Yes, this is the file I intended to submit" "button" should exist
    And "No, wrong file: take me back" "button" should exist
    And I should see "Check this is the right file"
    And I should not see "broken item"
    And I should see "About this file"
    And I should see "Word document"
    And "Preview of essay.docx" "local_submissioncheck > Preview" should exist

  Scenario: Each file has a tab with its warning count, and its checks sit beside its preview
    Given I am on the "Essay 1" "assign activity" page logged in as "student1"
    And I press "Add submission"
    And I upload "local/submissioncheck/tests/fixtures/essay.docx" file to "File submissions" filemanager
    And I upload "local/submissioncheck/tests/fixtures/linked-media.pptx" file to "File submissions" filemanager
    When I press "Save changes"
    Then I should not see "broken" in the "essay.docx" "local_submissioncheck > File tab"
    And I should see "2 broken" in the "linked-media.pptx" "local_submissioncheck > File tab"
    And I click on "linked-media.pptx" "local_submissioncheck > File tab"
    And I should see "Not in the file: on your computer · slide 1"
    And I should see "Slides"
    And "Preview of linked-media.pptx" "local_submissioncheck > Preview" should be visible
    And I should see "Viewed 2 of 2 files"

  Scenario: The answers do not wait for every tab to be opened
    Given I am on the "Essay 1" "assign activity" page logged in as "student1"
    And I press "Add submission"
    And I upload "local/submissioncheck/tests/fixtures/essay.docx" file to "File submissions" filemanager
    And I upload "local/submissioncheck/tests/fixtures/marks.xlsx" file to "File submissions" filemanager
    And I press "Save changes"
    And I should see "Viewed 1 of 2 files"
    When I press "Yes, these are the files I intended to submit"
    Then I should see "You confirmed"
    And I should see "Submitted for grading"

  Scenario: A linked picture in a Word document is marked where it is missing
    Given I am on the "Essay 1" "assign activity" page logged in as "student1"
    And I press "Add submission"
    And I upload "local/submissioncheck/tests/fixtures/linked-image.docx" file to "File submissions" filemanager
    When I press "Save changes"
    Then the Word preview of "linked-image.docx" should show "graph1.png is not included in this document. A marker will not see it."

  Scenario: A linked video in a presentation is marked under its slide
    Given I am on the "Essay 1" "assign activity" page logged in as "student1"
    And I press "Add submission"
    And I upload "local/submissioncheck/tests/fixtures/linked-media.pptx" file to "File submissions" filemanager
    When I press "Save changes"
    Then the preview of "linked-media.pptx" should show "interview.mp4 is not included in this document. A marker will not see it."

  Scenario: Facts come from the file itself
    Given I am on the "Essay 1" "assign activity" page logged in as "student1"
    And I press "Add submission"
    And I upload "local/submissioncheck/tests/fixtures/marks.xlsx" file to "File submissions" filemanager
    When I press "Save changes"
    Then I should see "Excel workbook" in the "About marks.xlsx" "local_submissioncheck > Facts"
    And I should see "Marks" in the "About marks.xlsx" "local_submissioncheck > Facts"
    And I should see "1 formula" in the "About marks.xlsx" "local_submissioncheck > Facts"

  Scenario: Leaving the review keeps a banner on the assignment page that leads back to it
    Given I am on the "Essay 1" "assign activity" page logged in as "student1"
    And I press "Add submission"
    And I upload "local/submissioncheck/tests/fixtures/essay.docx" file to "File submissions" filemanager
    And I press "Save changes"
    And I am on "Research skills" course homepage
    When I am on the "Essay 1" "assign activity" page
    Then I should see "Continue checking your submission"
    And I click on "Continue checking your submission" "link"
    And "Yes, this is the file I intended to submit" "button" should exist

  Scenario: After answering, the assignment page shows the outcome and the report stays readable
    Given I am on the "Essay 1" "assign activity" page logged in as "student1"
    And I press "Add submission"
    And I upload "local/submissioncheck/tests/fixtures/essay.docx" file to "File submissions" filemanager
    And I press "Save changes"
    And I press "Yes, this is the file I intended to submit"
    Then I should see "You confirmed this file."
    And I click on "See the check report" "link"
    And I should see "Check this is the right file"
    And I should not see "broken item"
    And "Yes, this is the file I intended to submit" "button" should not exist

  Scenario: Tabs work with the keyboard
    Given I am on the "Essay 1" "assign activity" page logged in as "student1"
    And I press "Add submission"
    And I upload "local/submissioncheck/tests/fixtures/essay.docx" file to "File submissions" filemanager
    And I upload "local/submissioncheck/tests/fixtures/marks.xlsx" file to "File submissions" filemanager
    And I press "Save changes"
    When I click on "essay.docx" "local_submissioncheck > File tab"
    And I press the right key
    Then "Preview of marks.xlsx" "local_submissioncheck > Preview" should be visible

  @accessibility
  Scenario: The review page meets WCAG 2.1 AA with tabs and the fixed bar
    Given I am on the "Essay 1" "assign activity" page logged in as "student1"
    And I press "Add submission"
    And I upload "local/submissioncheck/tests/fixtures/essay.docx" file to "File submissions" filemanager
    And I upload "local/submissioncheck/tests/fixtures/linked-media.pptx" file to "File submissions" filemanager
    When I press "Save changes"
    Then the page should meet accessibility standards with "wcag21aa" extra tests

  Scenario: On a narrow screen the review is in steps
    Given I change the viewport size to "mobile"
    And I am on the "Essay 1" "assign activity" page logged in as "student1"
    And I press "Add submission"
    And I upload "local/submissioncheck/tests/fixtures/essay.docx" file to "File submissions" filemanager
    When I press "Save changes"
    Then I should see "What we found"
    And I should see "Look through your file"
    And "Yes, this is the file I intended to submit" "button" should be visible
