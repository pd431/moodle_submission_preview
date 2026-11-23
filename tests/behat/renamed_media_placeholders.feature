@mod_assign @local_submissioncheck @_file_upload @javascript
Feature: Renamed sound and video files are named, and converter previews show missing items
  In order to know my file is really what its name says, and that a marker will see what is missing
  As a student
  I need sound or video under a document name named, and missing items drawn in converter previews

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
    And I am on the "Essay 1" "assign activity" page logged in as "student1"
    And I press "Add submission"

  Scenario: A sound file under a Word name says what it is
    Given the submission check converter is unavailable
    And I upload "local/submissioncheck/tests/fixtures/sound-renamed.docx" file to "File submissions" filemanager
    When I press "Save changes"
    Then I should see "sound-renamed.docx is named as a Word document but is actually a sound file."
    And I should see "We couldn't preview this type of file." in the "Preview of sound-renamed.docx" "local_submissioncheck > Preview"

  Scenario: A video file under a PDF name says what it is
    Given the submission check converter is unavailable
    And I upload "local/submissioncheck/tests/fixtures/video-renamed.pdf" file to "File submissions" filemanager
    When I press "Save changes"
    Then I should see "video-renamed.pdf is named as a PDF but is actually a video file."

  Scenario: A real sound file is not flagged
    Given the submission check converter is unavailable
    And I upload "local/submissioncheck/tests/fixtures/clip.mp3" file to "File submissions" filemanager
    When I press "Save changes"
    Then I should not see "is actually a sound file"
    And I should not see "broken item"

  Scenario: The converter is handed a copy with the linked picture replaced
    Given the submission check test converter is enabled
    And I upload "local/submissioncheck/tests/fixtures/linked-image.docx" file to "File submissions" filemanager
    When I press "Save changes"
    Then I should see "1 broken item"
    And the file handed to the converter for "linked-image.docx" should have 1 placeholder picture and no linked items
    And the submitted file "linked-image.docx" should be unchanged

  Scenario: A presentation's copy has its linked media replaced
    Given the submission check test converter is enabled
    And I upload "local/submissioncheck/tests/fixtures/linked-media.pptx" file to "File submissions" filemanager
    When I press "Save changes"
    Then the file handed to the converter for "linked-media.pptx" should have 1 placeholder picture and no linked items
    And the submitted file "linked-media.pptx" should be unchanged

  Scenario: A file with nothing broken is handed over as it is
    Given the submission check test converter is enabled
    And I upload "local/submissioncheck/tests/fixtures/essay.docx" file to "File submissions" filemanager
    When I press "Save changes"
    Then the file handed to the converter for "essay.docx" should be the submitted file
