@mod_quiz @local_submissioncheck @javascript @_file_upload
Feature: Students see checks and a preview of files attached to quiz essay questions
  In order to avoid attaching a broken, empty or wrong file in a quiz
  As a student
  I need to see what my marker will see, and be warned about problems, before I finish the attempt

  Background:
    Given the following "courses" exist:
      | fullname        | shortname | category |
      | Research skills | RS101     | 0        |
    And the following "users" exist:
      | username | firstname | lastname |
      | student1 | Sam       | Student  |
      | manager1 | Mina      | Manager  |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | RS101  | student |
      | manager1 | RS101  | manager |
    And the following "question categories" exist:
      | contextlevel | reference | name           |
      | Course       | RS101     | Test questions |
    And the following "questions" exist:
      | questioncategory | qtype     | name   | template | questiontext            |
      | Test questions   | essay     | Upload | editorfilepicker | Upload your report.     |
      | Test questions   | truefalse | TF     |          | Reports must be cited.  |
    And the following "activities" exist:
      | activity | name   | course | timeclose   | submissioncheck_mode            |
      | quiz     | Quiz 1 | RS101  | ##+7 days## | on                              |
    And quiz "Quiz 1" contains the following questions:
      | question | page |
      | Upload   | 1    |
      | TF       | 1    |
    And the submission check test converter is enabled

  Scenario: An attached file shows results and the preview under the question
    Given I am on the "Quiz 1" "mod_quiz > View" page logged in as "student1"
    And I press "Attempt quiz"
    When I upload "local/submissioncheck/tests/fixtures/linked-media.pptx" file to the attachments of "Question 1"
    Then I should see "2 broken items"
    And I should see "interview.mp4"
    And I should see "Previews are approximate."
    And "Preview of linked-media.pptx" "local_submissioncheck > Preview" should exist

  Scenario: Quiz attachments show their facts under the question
    Given I am on the "Quiz 1" "mod_quiz > View" page logged in as "student1"
    And I press "Attempt quiz"
    When I upload "local/submissioncheck/tests/fixtures/essay.docx" file to the attachments of "Question 1"
    Then I should see "About this file"
    And I should see "Word document" in the "About essay.docx" "local_submissioncheck > Facts"

  Scenario: Questions without attachments are left alone
    Given I am on the "Quiz 1" "mod_quiz > View" page logged in as "student1"
    When I press "Attempt quiz"
    Then I should not see "Submission checks" in the "Question 2" "question"

  Scenario: Finishing with a broken item asks twice
    Given I am on the "Quiz 1" "mod_quiz > View" page logged in as "student1"
    And I press "Attempt quiz"
    And I upload "local/submissioncheck/tests/fixtures/linked-media.pptx" file to the attachments of "Question 1"
    And I press "Finish attempt ..."
    Then I should see "interview.mp4"
    And I press "Submit all and finish"
    And I should see "Finish attempt"
    And I wait "2" seconds
    And I press "Click again to finish with 2 broken items"
    And I click on "Submit all and finish" "button" in the "Submit all your answers and finish?" "dialogue"
    And I should see "Finished"

  Scenario: A clean file needs no acknowledgement
    Given I am on the "Quiz 1" "mod_quiz > View" page logged in as "student1"
    And I press "Attempt quiz"
    And I upload "local/submissioncheck/tests/fixtures/essay.docx" file to the attachments of "Question 1"
    And I should see "Check this is the right file"
    And I should not see "broken item"
    And I press "Finish attempt ..."
    When I press "Submit all and finish"
    Then I click on "Submit all and finish" "button" in the "Submit all your answers and finish?" "dialogue"
    And I should see "Finished"

  Scenario: Near the time limit, warnings show but no tick is needed
    Given the following "activities" exist:
      | activity | name   | course | timelimit | submissioncheck_mode            |
      | quiz     | Quiz 2 | RS101  | 600       | on                              |
    And quiz "Quiz 2" contains the following questions:
      | question | page |
      | Upload   | 1    |
    And I am on the "Quiz 2" "mod_quiz > View" page logged in as "student1"
    And I press "Attempt quiz"
    And I press "Start attempt"
    And I upload "local/submissioncheck/tests/fixtures/linked-media.pptx" file to the attachments of "Question 1"
    And I press "Finish attempt ..."
    Then I should see "The time limit is close, so you can finish without confirming."
    And I press "Submit all and finish"
    And I click on "Submit all and finish" "button" in the "Submit all your answers and finish?" "dialogue"
    And I should see "Finished"

  Scenario: If checks are unavailable the student can still finish
    Given the submission check service is unavailable
    And I am on the "Quiz 1" "mod_quiz > View" page logged in as "student1"
    And I press "Attempt quiz"
    When I upload "local/submissioncheck/tests/fixtures/essay.docx" file to the attachments of "Question 1"
    Then I should see "Checks aren't available right now. You can still submit."
    And I press "Finish attempt ..."
    And I press "Submit all and finish"
    And I click on "Submit all and finish" "button" in the "Submit all your answers and finish?" "dialogue"
    And I should see "Finished"

  Scenario: Results for a finished attempt reach the results report
    Given I am on the "Quiz 1" "mod_quiz > View" page logged in as "student1"
    And I press "Attempt quiz"
    And I upload "local/submissioncheck/tests/fixtures/linked-media.pptx" file to the attachments of "Question 1"
    And I press "Finish attempt ..."
    And I press "Submit all and finish"
    And I wait "2" seconds
    And I press "Click again to finish with 2 broken items"
    And I click on "Submit all and finish" "button" in the "Submit all your answers and finish?" "dialogue"
    When I am on the "Quiz 1" "mod_quiz > View" page logged in as "manager1"
    And I follow "Submission check results"
    Then I should see "Sam Student" in the "linked-media.pptx" "table_row"
    And I should see "Acknowledged" in the "linked-media.pptx" "table_row"

  Scenario: A student who leaves the attempt sees the results again on return
    Given I am on the "Quiz 1" "mod_quiz > View" page logged in as "student1"
    And I press "Attempt quiz"
    And I upload "local/submissioncheck/tests/fixtures/linked-media.pptx" file to the attachments of "Question 1"
    And I press "Finish attempt ..."
    And I am on "Research skills" course homepage
    When I am on the "Quiz 1" "mod_quiz > View" page
    And I press "Continue your attempt"
    Then I should see "interview.mp4"
    And "Preview of linked-media.pptx" "local_submissioncheck > Preview" should exist
