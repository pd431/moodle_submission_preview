@mod_assign @local @local_submissioncheck @_file_upload @javascript
Feature: The review shows what is broken first, in few words, and asks twice before going ahead with problems
  In order to see at a glance what works and what does not, and check this is the right file
  As a student
  I need broken items listed once in red, a preview frame that always says what it can show, and no verdict when all is well

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

  Scenario: A healthy file gets the header, the three questions and no verdict, and one press confirms
    Given I upload "local/submissioncheck/tests/fixtures/essay.docx" file to "File submissions" filemanager
    When I press "Save changes"
    Then I should see "Check your submission to Essay 1 · RS101"
    And I should see "This stays your submission time unless you replace the file. Previews are approximate."
    And I should see "Is it for this assessment?"
    And I should see "Is it your latest version?"
    And I should see "Is everything you expect in it?"
    And I should not see "No problems found"
    And I should not see "broken item"
    And I should not see "Check this report thoroughly"
    And I should see "Confirm by" in the "[data-region='local-submissioncheck-bar']" "css_element"
    And I press "Yes, this is the file I intended to submit"
    And I should see "You confirmed this file."

  Scenario: Broken items are listed once, in red, and jump to their place
    Given I upload "local/submissioncheck/tests/fixtures/broken-links.docx" file to "File submissions" filemanager
    When I press "Save changes"
    Then I should see "3 broken items" in the "Broken items in broken-links.docx" "local_submissioncheck > Broken"
    And I should see "Your marker will not see these."
    And I should see "Not in the file: on your computer" in the "Broken items in broken-links.docx" "local_submissioncheck > Broken"
    And I should see "Needs sign-in: SharePoint" in the "Broken items in broken-links.docx" "local_submissioncheck > Broken"
    And I should see "Link to a file on your computer" in the "Broken items in broken-links.docx" "local_submissioncheck > Broken"
    And I should not see "Exeter library" in the "Broken items in broken-links.docx" "local_submissioncheck > Broken"
    And the Word preview of "broken-links.docx" should show "graph1.png is not included in this document. A marker will not see it."
    And I click on "graph1.png" "button" in the "Broken items in broken-links.docx" "local_submissioncheck > Broken"

  Scenario: Public links are not broken and no verdict is shown
    Given I upload "local/submissioncheck/tests/fixtures/public-link.docx" file to "File submissions" filemanager
    When I press "Save changes"
    Then I should see "Check this is the right file"
    And I should not see "broken item"
    And I should not see "No problems found"

  Scenario: More than five broken items collapse
    Given I upload "local/submissioncheck/tests/fixtures/many-links.docx" file to "File submissions" filemanager
    When I press "Save changes"
    Then I should see "8 broken items"
    And I should see "and 3 more"

  Scenario: A file that should render but cannot be read keeps the preview frame
    Given I upload "local/submissioncheck/tests/fixtures/damaged-start.docx" file to "File submissions" filemanager
    When I press "Save changes"
    Then I should see "File cannot be read." in the "Preview of damaged-start.docx" "local_submissioncheck > Preview"
    And I should see "File may be corrupt." in the "Preview of damaged-start.docx" "local_submissioncheck > Preview"

  Scenario: A password protected file says so in the frame
    Given I upload "local/submissioncheck/tests/fixtures/protected.docx" file to "File submissions" filemanager
    When I press "Save changes"
    Then I should see "File is password protected." in the "Preview of protected.docx" "local_submissioncheck > Preview"
    And I should see "A marker will not be able to open it." in the "Preview of protected.docx" "local_submissioncheck > Preview"

  Scenario: A type we never preview keeps the frame and asks for extra care
    Given I upload "local/submissioncheck/tests/fixtures/code.zip" file to "File submissions" filemanager
    When I press "Save changes"
    Then I should see "We couldn't preview this type of file." in the "Preview of code.zip" "local_submissioncheck > Preview"
    And I should see "Take extra care to check this is the correct file." in the "Preview of code.zip" "local_submissioncheck > Preview"

  Scenario: Tabs say only what is wrong
    Given I upload "local/submissioncheck/tests/fixtures/essay.docx" file to "File submissions" filemanager
    And I upload "local/submissioncheck/tests/fixtures/broken-links.docx" file to "File submissions" filemanager
    And I upload "local/submissioncheck/tests/fixtures/code.zip" file to "File submissions" filemanager
    When I press "Save changes"
    Then I should see "3 broken" in the "broken-links.docx" "local_submissioncheck > File tab"
    And I should see "No preview" in the "code.zip" "local_submissioncheck > File tab"
    And I should not see "broken" in the "essay.docx" "local_submissioncheck > File tab"
    And I should not see "No problems" in the "essay.docx" "local_submissioncheck > File tab"

  Scenario: With broken items, Yes asks twice, waits a second, and records the acknowledgement
    Given I upload "local/submissioncheck/tests/fixtures/broken-links.docx" file to "File submissions" filemanager
    And I press "Save changes"
    When I press "Yes, this is the file I intended to submit"
    And I press "Yes, this is the file I intended to submit"
    Then I should not see "You confirmed"
    And I wait "2" seconds
    And I press "Click again to confirm with 3 broken items"
    And I should see "You confirmed this file and read the warnings about it"

  Scenario: Moving away puts the button back
    Given I upload "local/submissioncheck/tests/fixtures/broken-links.docx" file to "File submissions" filemanager
    And I press "Save changes"
    When I press "Yes, this is the file I intended to submit"
    And I wait "2" seconds
    And "Click again to confirm with 3 broken items" "button" should exist
    And I click on "Check your submission to" "text"
    Then "Yes, this is the file I intended to submit" "button" should exist
    And "Click again to confirm with 3 broken items" "button" should not exist

  @accessibility
  Scenario: The red panel and the frames meet WCAG 2.1 AA
    Given I upload "local/submissioncheck/tests/fixtures/broken-links.docx" file to "File submissions" filemanager
    And I upload "local/submissioncheck/tests/fixtures/damaged-start.docx" file to "File submissions" filemanager
    And I upload "local/submissioncheck/tests/fixtures/code.zip" file to "File submissions" filemanager
    When I press "Save changes"
    Then the page should meet accessibility standards with "wcag21aa" extra tests
