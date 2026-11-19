@local @local_submissioncheck @javascript @_file_upload
Feature: Turning submission check and preview on and deciding who sees results
  In order to have checks run on assessments that matter without configuring each one
  As a site administrator
  I need checks to default on for tagged activities, and results visible only to roles I choose

  Background:
    Given the following "courses" exist:
      | fullname        | shortname | category |
      | Research skills | RS101     | 0        |
    And the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | Tara      | Teacher  |
      | marker1  | Mark      | Marker   |
      | manager1 | Mina      | Manager  |
      | student1 | Sam       | Student  |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | RS101  | editingteacher |
      | marker1  | RS101  | teacher        |
      | manager1 | RS101  | manager        |
      | student1 | RS101  | student        |
    And the following config values are set as admin:
      | defaulttags | summative, formative | local_submissioncheck |

  Scenario: A tagged assignment has checks on without the teacher touching the setting
    Given I log in as "teacher1"
    And I add an "assign" activity to course "Research skills" section "1" and I fill the form with:
      | Assignment name                           | Essay 1   |
      | File submissions                          | 1         |
      | Require students to click the submit button | No      |
      | Tags                                      | summative |
    When I am on the "Essay 1" "assign activity editing" page
    And I expand all fieldsets
    Then the field "Submission check and preview" matches value "Follow tags (on: tagged summative)"

  Scenario: An untagged assignment following tags has checks off
    Given the following "activities" exist:
      | activity | name | course | assignsubmission_file_enabled | assignsubmission_file_maxfiles | assignsubmission_file_maxsizebytes | submissiondrafts |
      | assign | Essay 2 | RS101 | 1 | 1 | 1048576 | 0 |
    When I am on the "Essay 2" "assign activity editing" page logged in as "teacher1"
    And I expand all fieldsets
    Then the field "Submission check and preview" matches value "Follow tags (off: no matching tag)"

  Scenario: A teacher can turn checks off on a tagged assignment
    Given I log in as "teacher1"
    And I add an "assign" activity to course "Research skills" section "1" and I fill the form with:
      | Assignment name                           | Essay 3   |
      | File submissions                          | 1         |
      | Require students to click the submit button | No      |
      | Tags                                      | formative |
      | Submission check and preview              | Off       |
    When I am on the "Essay 3" "assign activity" page logged in as "student1"
    And I press "Add submission"
    And I upload "local/submissioncheck/tests/fixtures/linked-image.docx" file to "File submissions" filemanager
    And I press "Save changes"
    Then I should not see "broken item"
    And I should not see "Check this is the right file"

  Scenario: Checks cannot be switched on while students must click a submit button
    Given I log in as "teacher1"
    When I add an "assign" activity to course "Research skills" section "1" and I fill the form with:
      | Assignment name                           | Essay 4 |
      | File submissions                          | 1       |
      | Require students to click the submit button | Yes   |
      | Submission check and preview              | On      |
    Then I should see "Submission check and preview needs \"Require students to click the submit button\" set to No, so the upload time is the submission time and students can replace a wrong file."

  Scenario: Markers do not see check results; managers do
    Given the following "activities" exist:
      | activity | name | course | assignsubmission_file_enabled | assignsubmission_file_maxfiles | assignsubmission_file_maxsizebytes | submissiondrafts |
      | assign | Essay 5 | RS101 | 1 | 1 | 1048576 | 0 |
    And I am on the "Essay 5" "assign activity editing" page logged in as "teacher1"
    And I expand all fieldsets
    And I set the field "Submission check and preview" to "On"
    And I press "Save and return to course"
    And I am on the "Essay 5" "assign activity" page logged in as "student1"
    And I press "Add submission"
    And I upload "local/submissioncheck/tests/fixtures/linked-image.docx" file to "File submissions" filemanager
    And I press "Save changes"
    When I am on the "Essay 5" "assign activity" page logged in as "marker1"
    Then I should not see "Submission check results"
    And I am on the "Essay 5" "assign activity" page logged in as "manager1"
    And I follow "Submission check results"
    And I should see "Sam Student" in the "linked-image.docx" "table_row"
    And I should see "Linked, not embedded: graph1.png" in the "linked-image.docx" "table_row"
    And I should see "Not acknowledged" in the "linked-image.docx" "table_row"

  Scenario: A role given the capability sees results
    Given the following "permission overrides" exist:
      | capability                         | permission | role    | contextlevel | reference |
      | local/submissioncheck:viewresults  | Allow      | teacher | Course       | RS101     |
    And the following "activities" exist:
      | activity | name | course | assignsubmission_file_enabled | assignsubmission_file_maxfiles | assignsubmission_file_maxsizebytes | submissiondrafts |
      | assign | Essay 6 | RS101 | 1 | 1 | 1048576 | 0 |
    And I am on the "Essay 6" "assign activity editing" page logged in as "teacher1"
    And I expand all fieldsets
    And I set the field "Submission check and preview" to "On"
    And I press "Save and return to course"
    When I am on the "Essay 6" "assign activity" page logged in as "marker1"
    Then I should see "Submission check results"

  Scenario: The setting sits under the submission statement in an assignment
    Given the following "activities" exist:
      | activity | name | course | assignsubmission_file_enabled | assignsubmission_file_maxfiles | assignsubmission_file_maxsizebytes | submissiondrafts |
      | assign | Essay 7 | RS101 | 1 | 1 | 1048576 | 0 |
    When I am on the "Essay 7" "assign activity editing" page logged in as "teacher1"
    And I expand all fieldsets
    Then "Submission check and preview" "field" should appear after "Require that students accept the submission statement" "field"
    And "Submission check and preview" "field" should appear before "Group submission settings" "text"
    And "#id_local_submissioncheck_header" "css_element" should not exist

  Scenario: An untagged assignment can be switched on whatever the tags
    Given the following "activities" exist:
      | activity | name | course | assignsubmission_file_enabled | assignsubmission_file_maxfiles | assignsubmission_file_maxsizebytes | submissiondrafts |
      | assign | Essay 8 | RS101 | 1 | 1 | 1048576 | 0 |
    And I am on the "Essay 8" "assign activity editing" page logged in as "teacher1"
    And I expand all fieldsets
    And I set the field "Submission check and preview" to "On"
    And I press "Save and display"
    When I am on the "Essay 8" "assign activity" page logged in as "student1"
    And I press "Add submission"
    And I upload "local/submissioncheck/tests/fixtures/essay.docx" file to "File submissions" filemanager
    And I press "Save changes"
    Then I should see "Check this is the right file"

  Scenario: Tagging an assignment in the same visit turns checks on when saved
    Given the following "activities" exist:
      | activity | name | course | assignsubmission_file_enabled | assignsubmission_file_maxfiles | assignsubmission_file_maxsizebytes | submissiondrafts |
      | assign | Essay 9 | RS101 | 1 | 1 | 1048576 | 0 |
    And I am on the "Essay 9" "assign activity editing" page logged in as "teacher1"
    And I expand all fieldsets
    And I set the field "Tags" to "summative"
    And I press "Save and return to course"
    When I am on the "Essay 9" "assign activity editing" page
    And I expand all fieldsets
    Then the field "Submission check and preview" matches value "Follow tags (on: tagged summative)"

  Scenario: With no tags set up, following tags says so
    Given the following config values are set as admin:
      | defaulttags |  | local_submissioncheck |
    And the following "activities" exist:
      | activity | name | course | assignsubmission_file_enabled | assignsubmission_file_maxfiles | assignsubmission_file_maxsizebytes | submissiondrafts |
      | assign | Essay 10 | RS101 | 1 | 1 | 1048576 | 0 |
    When I am on the "Essay 10" "assign activity editing" page logged in as "teacher1"
    And I expand all fieldsets
    Then the field "Submission check and preview" matches value "Follow tags (off: no tags set up)"

  Scenario: In a quiz the setting is in Question behaviour
    Given the following "activities" exist:
      | activity | name   | course |
      | quiz     | Quiz 9 | RS101  |
    When I am on the "Quiz 9" "quiz activity editing" page logged in as "teacher1"
    And I expand all fieldsets
    Then "Submission check and preview" "field" should appear after "How questions behave" "field"
    And "Submission check and preview" "field" should appear before "Review options" "text"
    And "#id_local_submissioncheck_header" "css_element" should not exist

