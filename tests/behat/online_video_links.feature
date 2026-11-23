@mod_assign @local @local_submissioncheck @_file_upload @javascript
Feature: Online videos and media on sign-in storage are flagged
  In order not to submit a video my marker cannot open
  As a student
  I need to be told when my document plays or shows something stored on OneDrive, SharePoint or similar

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

  Scenario: An online video on OneDrive is a warning and is marked in the preview
    Given I am on the "Essay 1" "assign activity" page logged in as "student1"
    And I press "Add submission"
    And I upload "local/submissioncheck/tests/fixtures/sharepoint-video.docx" file to "File submissions" filemanager
    And I upload "local/submissioncheck/tests/fixtures/essay.docx" file to "File submissions" filemanager
    When I press "Save changes"
    Then I should see "1 broken" in the "sharepoint-video.docx" "local_submissioncheck > File tab"
    And I click on "sharepoint-video.docx" "local_submissioncheck > File tab"
    And the Word preview of "sharepoint-video.docx" should show "untitled.mp4 is not included in this document."
    And the Word preview of "sharepoint-video.docx" should show "It is stored on OneDrive. A marker will not see it."

  Scenario: Confirming with the warning on screen records it as acknowledged
    Given I am on the "Essay 1" "assign activity" page logged in as "student1"
    And I press "Add submission"
    And I upload "local/submissioncheck/tests/fixtures/sharepoint-video.docx" file to "File submissions" filemanager
    And I press "Save changes"
    When I press "Yes, this is the file I intended to submit"
    And I wait "2" seconds
    And I press "Click again to confirm with 1 broken item"
    Then I should see "You confirmed this file and read the warnings about it"

  Scenario: A video on another site is listed, not flagged
    Given I am on the "Essay 1" "assign activity" page logged in as "student1"
    And I press "Add submission"
    And I upload "local/submissioncheck/tests/fixtures/youtube-video.docx" file to "File submissions" filemanager
    When I press "Save changes"
    Then I should see "Check this is the right file"
    And I should not see "broken item"
    And I should see "1 online video (youtube.com)" in the "About youtube-video.docx" "local_submissioncheck > Facts"
    And the Word preview of "youtube-video.docx" should not show "is not included in this document"

  Scenario: A presentation's linked video on OneDrive is a warning
    Given I am on the "Essay 1" "assign activity" page logged in as "student1"
    And I press "Add submission"
    And I upload "local/submissioncheck/tests/fixtures/sharepoint-video.pptx" file to "File submissions" filemanager
    And I upload "local/submissioncheck/tests/fixtures/essay.docx" file to "File submissions" filemanager
    When I press "Save changes"
    Then I should see "2 broken" in the "sharepoint-video.pptx" "local_submissioncheck > File tab"
    And I click on "sharepoint-video.pptx" "local_submissioncheck > File tab"
    And I should see "Not in the file: on OneDrive"

  Scenario: Only the hosts in the site setting count as sign-in storage
    Given the following config values are set as admin:
      | config      | value       | plugin                |
      | signinhosts | dropbox.com | local_submissioncheck |
    And I am on the "Essay 1" "assign activity" page logged in as "student1"
    And I press "Add submission"
    And I upload "local/submissioncheck/tests/fixtures/sharepoint-video.docx" file to "File submissions" filemanager
    When I press "Save changes"
    Then I should see "Check this is the right file"
    And I should not see "broken item"

  Scenario: A plain Word document is not flagged
    Given I am on the "Essay 1" "assign activity" page logged in as "student1"
    And I press "Add submission"
    And I upload "local/submissioncheck/tests/fixtures/essay.docx" file to "File submissions" filemanager
    When I press "Save changes"
    Then I should see "Check this is the right file"
    And I should not see "broken item"
