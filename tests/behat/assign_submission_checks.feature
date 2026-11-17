@mod_assign @local_submissioncheck @_file_upload @javascript
Feature: Students check a preview of their assignment file straight after submitting it
  In order to catch a broken, empty or wrong file while there is still time to replace it
  As a student
  I need to see what my marker will see, be warned about problems, and confirm or go back

  Background:
    Given the following "courses" exist:
      | fullname        | shortname | category | groupmode |
      | Research skills | RS101     | 0        | 1         |
    And the following "users" exist:
      | username | firstname | lastname |
      | student1 | Sam       | Student  |
      | student2 | Ali       | Student  |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | RS101  | student |
      | student2 | RS101  | student |
    And the following "activities" exist:
      | activity | name | course | assignsubmission_file_enabled | assignsubmission_file_maxfiles | assignsubmission_file_maxsizebytes | submissiondrafts | submissioncheck_mode | duedate |
      | assign | Essay 1 | RS101 | 1 | 3 | 1048576 | 0 | on | ##+7 days## |
    And the submission check test converter is enabled

  Scenario: Straight after upload the student sees the timestamp, the report and the preview
    Given I am on the "Essay 1" "assign activity" page logged in as "student1"
    And I press "Add submission"
    And I upload "local/submissioncheck/tests/fixtures/essay.docx" file to "File submissions" filemanager
    When I press "Save changes"
    Then I should see "You submitted this file at"
    And I should see "Check this report thoroughly. You have 15 minutes to confirm this is the file you intended to submit. Unless you choose to resubmit, your recorded submission timestamp will remain"
    And I should see "No problems found in essay.docx"
    And I should see "This is an approximate preview of your document(s). Layout may be approximate. Use it to check this is what you intended to submit to RS101 Essay 1, and that it has all the content you expect, and the latest changes you made to it."
    And "Preview of essay.docx" "local_submissioncheck > Preview" should exist
    And I should see "Confirm by"
    And I should see "About this file"
    And "Yes, this is the file I intended to submit" "button" should exist
    And "No, wrong file: take me back" "button" should exist
    And I am on the "Essay 1" "assign activity" page
    And I should see "Submitted for grading"

  Scenario: Confirming records it and keeps the upload time
    Given I am on the "Essay 1" "assign activity" page logged in as "student1"
    And I press "Add submission"
    And I upload "local/submissioncheck/tests/fixtures/essay.docx" file to "File submissions" filemanager
    And I press "Save changes"
    When I press "Yes, this is the file I intended to submit"
    Then I should see "You confirmed this file"
    And I should see "Submitted for grading"
    And "Yes, this is the file I intended to submit" "button" should not exist

  Scenario: Linked media is listed above the preview by file name
    Given I am on the "Essay 1" "assign activity" page logged in as "student1"
    And I press "Add submission"
    And I upload "local/submissioncheck/tests/fixtures/linked-media.pptx" file to "File submissions" filemanager
    When I press "Save changes"
    Then I should see "The following files have been linked but not embedded in your file. A marker will not be able to see them. See https://aka.ms/file_embedding for solutions."
    And I should see "graph1.png"
    And I should see "interview.mp4"
    And "Preview of linked-media.pptx" "local_submissioncheck > Preview" should exist

  Scenario: Confirming with warnings on screen records them as acknowledged
    Given I am on the "Essay 1" "assign activity" page logged in as "student1"
    And I press "Add submission"
    And I upload "local/submissioncheck/tests/fixtures/linked-media.pptx" file to "File submissions" filemanager
    And I press "Save changes"
    When I press "Yes, this is the file I intended to submit"
    Then I should see "You confirmed this file and read the warnings about it"

  Scenario: Wrong file takes the student back to replace it, and the new file starts a new window
    Given I am on the "Essay 1" "assign activity" page logged in as "student1"
    And I press "Add submission"
    And I upload "local/submissioncheck/tests/fixtures/empty.docx" file to "File submissions" filemanager
    And I press "Save changes"
    And the submission check upload time for "student1" in "Essay 1" is "##-10 minutes##"
    When I press "No, wrong file: take me back"
    Then I should see "empty.docx" in the "File submissions" "form_row"
    And I delete "empty.docx" from "File submissions" filemanager
    And I upload "local/submissioncheck/tests/fixtures/essay.docx" file to "File submissions" filemanager
    And I press "Save changes"
    And I should see "No problems found in essay.docx"
    And I should see "You have 15 minutes to confirm"
    And the submission check upload time for "student1" in "Essay 1" should be within the last "1" minutes

  Scenario: Going back without saving a new file leaves the original submission in place
    Given I am on the "Essay 1" "assign activity" page logged in as "student1"
    And I press "Add submission"
    And I upload "local/submissioncheck/tests/fixtures/essay.docx" file to "File submissions" filemanager
    And I press "Save changes"
    And I press "No, wrong file: take me back"
    When I press "Cancel"
    Then I should see "Submitted for grading"
    And I should see "essay.docx"
    And I click on "Continue checking your submission" "link"
    And "Yes, this is the file I intended to submit" "button" should exist

  Scenario: After 15 minutes the submission is accepted automatically, with warnings not acknowledged
    Given the submission check upload for "student1" in "Essay 1" of "local/submissioncheck/tests/fixtures/linked-media.pptx" was made at "##-16 minutes##"
    And I run the scheduled task "\local_submissioncheck\task\auto_accept"
    When I am on the "Essay 1" "assign activity" page logged in as "student1"
    Then I should see "Accepted automatically after 15 minutes. The warnings below were not acknowledged."
    And I should see "Submitted for grading"
    And "Yes, this is the file I intended to submit" "button" should not exist

  Scenario: After the window, the student can still resubmit before the due date, with a new timestamp
    Given the submission check upload for "student1" in "Essay 1" of "local/submissioncheck/tests/fixtures/essay.docx" was made at "##-16 minutes##"
    And I run the scheduled task "\local_submissioncheck\task\auto_accept"
    And I am on the "Essay 1" "assign activity" page logged in as "student1"
    When I press "Edit submission"
    And I delete "essay.docx" from "File submissions" filemanager
    And I upload "local/submissioncheck/tests/fixtures/linked-image.docx" file to "File submissions" filemanager
    And I press "Save changes"
    Then I should see "You have 15 minutes to confirm"

  Scenario: Confirming does not lock the submission; resubmitting starts the flow again
    Given I am on the "Essay 1" "assign activity" page logged in as "student1"
    And I press "Add submission"
    And I upload "local/submissioncheck/tests/fixtures/essay.docx" file to "File submissions" filemanager
    And I press "Save changes"
    And I press "Yes, this is the file I intended to submit"
    When I press "Edit submission"
    And I delete "essay.docx" from "File submissions" filemanager
    And I upload "local/submissioncheck/tests/fixtures/linked-media.pptx" file to "File submissions" filemanager
    And I press "Save changes"
    Then I should see "You have 15 minutes to confirm"
    And I should see "interview.mp4"
    And "Yes, this is the file I intended to submit" "button" should exist
    And I should not see "You confirmed this file"

  Scenario: Uploading just before the due date keeps the on-time timestamp while the student checks
    Given the following "activities" exist:
      | activity | name | course | assignsubmission_file_enabled | assignsubmission_file_maxfiles | assignsubmission_file_maxsizebytes | submissiondrafts | submissioncheck_mode | duedate |
      | assign | Essay 2 | RS101 | 1 | 1 | 1048576 | 0 | on | ##-5 minutes## |
    And the submission check upload for "student1" in "Essay 2" of "local/submissioncheck/tests/fixtures/essay.docx" was made at "##-10 minutes##"
    When I am on the "Essay 2" "assign activity" page logged in as "student1"
    And I click on "Continue checking your submission" "link"
    And I press "Yes, this is the file I intended to submit"
    Then I should see "Submitted for grading"
    And I should not see "submitted late"

  Scenario: After the due date, the report warns that a replacement will be late
    Given the following "activities" exist:
      | activity | name | course | assignsubmission_file_enabled | assignsubmission_file_maxfiles | assignsubmission_file_maxsizebytes | submissiondrafts | submissioncheck_mode | duedate |
      | assign | Essay 3 | RS101 | 1 | 1 | 1048576 | 0 | on | ##-5 minutes## |
    And the submission check upload for "student1" in "Essay 3" of "local/submissioncheck/tests/fixtures/essay.docx" was made at "##-10 minutes##"
    When I am on the "Essay 3" "assign activity" page logged in as "student1"
    And I click on "Continue checking your submission" "link"
    Then I should see "The due date has passed. If you replace this file, the new file will be recorded as late."

  Scenario: After the cut-off date, the file can no longer be replaced and the report says so
    Given the following "activities" exist:
      | activity | name | course | assignsubmission_file_enabled | assignsubmission_file_maxfiles | assignsubmission_file_maxsizebytes | submissiondrafts | submissioncheck_mode | duedate | cutoffdate |
      | assign | Essay 4 | RS101 | 1 | 1 | 1048576 | 0 | on | ##-10 minutes## | ##-2 minutes## |
    And the submission check upload for "student1" in "Essay 4" of "local/submissioncheck/tests/fixtures/essay.docx" was made at "##-12 minutes##"
    When I am on the "Essay 4" "assign activity" page logged in as "student1"
    And I click on "Continue checking your submission" "link"
    Then the "No, wrong file: take me back" "button" should be disabled
    And I should see "You can't replace this file because the cut-off date has passed. Contact your module team if it is the wrong file."
    And "Yes, this is the file I intended to submit" "button" should exist

  Scenario Outline: Each first-release check warns with a plain explanation
    Given I am on the "Essay 1" "assign activity" page logged in as "student1"
    And I press "Add submission"
    And I upload "local/submissioncheck/tests/fixtures/<file>" file to "File submissions" filemanager
    When I press "Save changes"
    Then I should see "<warning>"

    Examples:
      | file           | warning                                                                                                 |
      | truncated.docx | truncated.docx could not be opened. It may be damaged or only partly uploaded.                           |
      | ~$essay.docx   | ~$essay.docx is a temporary lock file Word creates while a document is open, not your document.         |
      | essay.gdoc     | essay.gdoc is a shortcut to a Google Doc, not the document. Download it as Word or PDF and upload that. |
      | renamed.docx   | renamed.docx is named as a Word document but is actually a web page (HTML).                              |
      | protected.docx | protected.docx is password protected. Your marker will not be able to open it.                           |
      | empty.docx     | empty.docx appears to contain no text.                                                                   |

  Scenario: Each file in a multi-file submission gets its own results and preview
    Given I am on the "Essay 1" "assign activity" page logged in as "student1"
    And I press "Add submission"
    And I upload "local/submissioncheck/tests/fixtures/essay.docx" file to "File submissions" filemanager
    And I upload "local/submissioncheck/tests/fixtures/linked-media.pptx" file to "File submissions" filemanager
    When I press "Save changes"
    Then I should see "No problems found in essay.docx"
    And "Preview of essay.docx" "local_submissioncheck > Preview" should exist
    And I click on "linked-media.pptx" "local_submissioncheck > File tab"
    And I should see "interview.mp4"
    And "Preview of linked-media.pptx" "local_submissioncheck > Preview" should exist

  Scenario: A student who navigates away is brought back to the report
    Given I am on the "Essay 1" "assign activity" page logged in as "student1"
    And I press "Add submission"
    And I upload "local/submissioncheck/tests/fixtures/linked-media.pptx" file to "File submissions" filemanager
    And I press "Save changes"
    And I am on "Research skills" course homepage
    When I am on the "Essay 1" "assign activity" page
    Then I should see "Continue checking your submission"
    And I click on "Continue checking your submission" "link"
    And I should see "interview.mp4"
    And "Preview of linked-media.pptx" "local_submissioncheck > Preview" should exist

  Scenario: An unconfirmed upload sends a reminder with a link back to the report
    Given the submission check upload for "student1" in "Essay 1" of "local/submissioncheck/tests/fixtures/essay.docx" was made at "##-4 minutes##"
    And I run the scheduled task "\local_submissioncheck\task\remind_unconfirmed"
    When I log in as "student1"
    And I open the notification popover
    Then I should see "Check your submission to Essay 1"
    And I click on "Check your submission to Essay 1" "link"
    And I should see "No problems found in essay.docx"

  Scenario: If preview conversion is unavailable for a file only a converter can preview, the submission is unaffected
    Given the submission check converter is unavailable
    And I am on the "Essay 1" "assign activity" page logged in as "student1"
    And I press "Add submission"
    And I upload "local/submissioncheck/tests/fixtures/essay.rtf" file to "File submissions" filemanager
    When I press "Save changes"
    Then I should see "A preview isn't available right now. Your file has been submitted."
    And I am on the "Essay 1" "assign activity" page
    And I should see "Submitted for grading"

  Scenario: A file type with no preview says so instead of showing nothing
    Given I am on the "Essay 1" "assign activity" page logged in as "student1"
    And I press "Add submission"
    And I upload "local/submissioncheck/tests/fixtures/notes.pages" file to "File submissions" filemanager
    When I press "Save changes"
    Then I should see "There is no preview for this type of file (notes.pages)."

  @accessibility
  Scenario: The report is accessible without the visual preview
    Given I am on the "Essay 1" "assign activity" page logged in as "student1"
    And I press "Add submission"
    And I upload "local/submissioncheck/tests/fixtures/linked-media.pptx" file to "File submissions" filemanager
    When I press "Save changes"
    Then "Submission checks for linked-media.pptx" "local_submissioncheck > Checks" should exist
    And the page should meet accessibility standards with "wcag21aa" extra tests

  Scenario: In a group submission, another member can confirm and both see the warnings
    Given the following "groups" exist:
      | name    | course | idnumber |
      | Group A | RS101  | GA       |
    And the following "group members" exist:
      | user     | group |
      | student1 | GA    |
      | student2 | GA    |
    And the following "activities" exist:
      | activity | name | course | assignsubmission_file_enabled | assignsubmission_file_maxfiles | assignsubmission_file_maxsizebytes | submissiondrafts | teamsubmission | submissioncheck_mode | duedate |
      | assign | Group 1 | RS101 | 1 | 1 | 1048576 | 0 | 1 | on | ##+7 days## |
    And I am on the "Group 1" "assign activity" page logged in as "student1"
    And I press "Add submission"
    And I upload "local/submissioncheck/tests/fixtures/linked-media.pptx" file to "File submissions" filemanager
    And I press "Save changes"
    When I am on the "Group 1" "assign activity" page logged in as "student2"
    And I click on "Continue checking your submission" "link"
    Then I should see "interview.mp4"
    And I press "Yes, this is the file I intended to submit"
    And I should see "Ali Student confirmed this file and read the warnings about it"
