<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Steps for submission check features.
 *
 * @package    local_submissioncheck
 * @category   test
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');
require_once(__DIR__ . '/../../../../lib/behat/core_behat_file_helper.php');

use Behat\Mink\Exception\ExpectationException;
use local_submissioncheck\local\confirmation;
use local_submissioncheck\local\testing;

/**
 * Steps for submission check features.
 */
class behat_local_submissioncheck extends behat_base {
    use core_behat_file_helper;

    /**
     * Named selectors for the report's regions.
     *
     * @return behat_component_named_selector[]
     */
    public static function get_partial_named_selectors(): array {
        return [
            new behat_component_named_selector('Preview', [
                ".//*[@role='region' and contains(@class, 'local-submissioncheck-preview') and @aria-label=%locator%]",
            ]),
            new behat_component_named_selector('Checks', [
                ".//*[@role='region' and contains(@class, 'local-submissioncheck-checks') and @aria-label=%locator%]",
            ]),
            new behat_component_named_selector('Broken', [
                ".//*[@role='region' and contains(@class, 'local-submissioncheck-broken') and @aria-label=%locator%]",
            ]),
            new behat_component_named_selector('Facts', [
                ".//*[@role='region' and contains(@class, 'local-submissioncheck-facts') and @aria-label=%locator%]",
            ]),
            new behat_component_named_selector('File tab', [
                ".//*[@role='tab' and @data-filename=%locator%]",
            ]),
        ];
    }

    /**
     * Previews come from a fast stand-in converter.
     *
     * @Given /^the submission check test converter is enabled$/
     */
    public function the_test_converter_is_enabled() {
        testing::set('test_converter', true);
    }

    /**
     * The converter behaves as if it were down.
     *
     * @Given /^the submission check converter is unavailable$/
     */
    public function the_converter_is_unavailable() {
        testing::set('converter_down', true);
    }

    /**
     * The checks behave as if they were down.
     *
     * @Given /^the submission check service is unavailable$/
     */
    public function the_service_is_unavailable() {
        testing::set('service_down', true);
    }

    /**
     * A preview made on the server (shown in a frame) contains some text. Opens the frame's page in this session.
     *
     * @Then /^the preview of "(?P<file>[^"]*)" should show "(?P<text>[^"]*)"$/
     * @param string $filename
     * @param string $text
     */
    public function the_preview_should_show(string $filename, string $text) {
        $region = $this->find('local_submissioncheck > Preview', get_string('previewof', 'local_submissioncheck', $filename));
        $frame = $region->find('css', 'iframe[data-region="local-submissioncheck-frame"]');
        if (!$frame) {
            throw new ExpectationException("No preview frame for $filename", $this->getSession());
        }
        $this->getSession()->visit($this->locate_path($frame->getAttribute('src')));
        $this->execute('behat_general::assert_page_contains_text', [$text]);
    }

    /**
     * A Word document drawn in the browser contains some text.
     *
     * @Then /^the Word preview of "(?P<file>[^"]*)" should show "(?P<text>[^"]*)"$/
     * @param string $filename
     * @param string $text
     */
    public function the_word_preview_should_show(string $filename, string $text) {
        $label = get_string('previewof', 'local_submissioncheck', $filename);
        $this->find('local_submissioncheck > Preview', $label);
        $script = 'return (function(label, text) {' .
            'var regions = document.querySelectorAll(\'[data-region="local-submissioncheck-docx"]\');' .
            'for (var i = 0; i < regions.length; i++) {' .
            '  var frame = regions[i].dataset.label === label ? regions[i].querySelector("iframe") : null;' .
            '  if (frame && regions[i].dataset.rendered && frame.contentDocument.body.textContent.indexOf(text) !== -1) {' .
            '    return true;' .
            '  }' .
            '}' .
            'return false;' .
            '})(' . json_encode($label) . ', ' . json_encode($text) . ');';
        $this->spin(function () use ($script) {
            return (bool) $this->evaluate_script($script);
        }, [], behat_base::get_extended_timeout(), new ExpectationException(
            "The Word preview of $filename does not show \"$text\"",
            $this->getSession()
        ));
    }

    /**
     * The latest submitted copy of a file in an assignment submission.
     *
     * @param string $filename
     * @return stored_file
     */
    protected function submitted_file(string $filename): stored_file {
        global $DB;
        $id = $DB->get_field_sql("SELECT MAX(id) FROM {files} WHERE component = 'assignsubmission_file'
            AND filearea = 'submission_files' AND filename = ?", [$filename]);
        if (!$id) {
            throw new ExpectationException("No submitted file $filename", $this->getSession());
        }
        return get_file_storage()->get_file_by_id($id);
    }

    /**
     * What the stand-in converter was handed for a submitted file.
     *
     * @param string $filename
     * @return stored_file
     */
    protected function handed_file(string $filename): stored_file {
        $handed = \local_submissioncheck\local\preview::stored(
            $this->submitted_file($filename),
            \local_submissioncheck\local\preview::AREA_HANDED
        );
        if (!$handed) {
            throw new ExpectationException("Nothing was handed to the converter for $filename", $this->getSession());
        }
        return $handed;
    }

    /**
     * The converter was handed a copy with placeholder pictures in place of linked items.
     *
     * @Then /^the file handed to the converter for "([^"]*)" should have (\d+) placeholder pictures? and no linked items$/
     * @param string $filename
     * @param int $count
     */
    public function the_handed_file_should_have_placeholders(string $filename, int $count) {
        $handed = $this->handed_file($filename);
        $path = $handed->copy_content_to_temp('local_submissioncheck');
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CHECKCONS) !== true) {
            throw new ExpectationException("The handed file for $filename is not a valid package", $this->getSession());
        }
        $placeholders = 0;
        $linked = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (!preg_match('#^(word/(document|header\d*|footer\d*)|ppt/slides/slide\d+)\.xml$#', $name)) {
                continue;
            }
            $xml = (string) $zip->getFromName($name);
            $placeholders += substr_count($xml, 'r:embed="' . \local_submissioncheck\local\preview_copy::PLACEHOLDER_ID . '"');
            $rels = (string) $zip->getFromName(dirname($name) . '/_rels/' . basename($name) . '.rels');
            $external = \local_submissioncheck\local\preview_copy::linked_targets($rels)
                + \local_submissioncheck\local\preview_copy::cloud_targets($rels);
            foreach (array_keys($external) as $id) {
                $linked += substr_count($xml, '"' . $id . '"');
            }
        }
        $zip->close();
        @unlink($path);
        if ($placeholders !== $count || $linked !== 0) {
            throw new ExpectationException("The handed file for $filename has $placeholders placeholder pictures and " .
                "$linked linked items", $this->getSession());
        }
    }

    /**
     * The converter was handed the submitted file itself.
     *
     * @Then /^the file handed to the converter for "(?P<file>[^"]*)" should be the submitted file$/
     * @param string $filename
     */
    public function the_handed_file_should_be_the_submitted_file(string $filename) {
        if ($this->handed_file($filename)->get_contenthash() !== $this->submitted_file($filename)->get_contenthash()) {
            throw new ExpectationException("The converter was not handed $filename itself", $this->getSession());
        }
    }

    /**
     * The submitted file is byte for byte the uploaded fixture.
     *
     * @Then /^the submitted file "(?P<file>[^"]*)" should be unchanged$/
     * @param string $filename
     */
    public function the_submitted_file_should_be_unchanged(string $filename) {
        global $CFG;
        $fixture = $CFG->dirroot . '/local/submissioncheck/tests/fixtures/' . $filename;
        if ($this->submitted_file($filename)->get_contenthash() !== sha1_file($fixture)) {
            throw new ExpectationException("The submitted $filename differs from the upload", $this->getSession());
        }
    }

    /**
     * A Word document drawn in the browser does not contain some text.
     *
     * @Then /^the Word preview of "(?P<file>[^"]*)" should not show "(?P<text>[^"]*)"$/
     * @param string $filename
     * @param string $text
     */
    public function the_word_preview_should_not_show(string $filename, string $text) {
        $this->wait_for_word_preview($filename);
        $found = $this->evaluate_script($this->word_preview_script(
            $filename,
            'return body.textContent.indexOf(' . json_encode($text) . ') !== -1;'
        ));
        if ($found) {
            throw new ExpectationException("The Word preview of $filename shows \"$text\"", $this->getSession());
        }
    }

    /**
     * A Word document drawn in the browser has a number of loaded pictures.
     *
     * @Then /^the Word preview of "(?P<file>[^"]*)" should show (?P<count>\d+) pictures?$/
     * @param string $filename
     * @param int $count
     */
    public function the_word_preview_should_show_pictures(string $filename, int $count) {
        $this->wait_for_word_preview($filename);
        $script = $this->word_preview_script(
            $filename,
            'return Array.prototype.filter.call(body.querySelectorAll("img"), function(i) { return i.naturalWidth > 0; }).length;'
        );
        $this->spin(function () use ($script, $count) {
            return (int) $this->evaluate_script($script) === $count;
        }, [], behat_base::get_extended_timeout(), new ExpectationException(
            "The Word preview of $filename does not show $count pictures",
            $this->getSession()
        ));
    }

    /**
     * Wait until a Word preview has been drawn.
     *
     * @param string $filename
     */
    protected function wait_for_word_preview(string $filename): void {
        $script = $this->word_preview_script($filename, 'return true;');
        $this->spin(function () use ($script) {
            return (bool) $this->evaluate_script($script);
        }, [], behat_base::get_extended_timeout(), new ExpectationException(
            "The Word preview of $filename was not drawn",
            $this->getSession()
        ));
    }

    /**
     * Script that runs some code on the body of a drawn Word preview, or returns false when it is not drawn yet.
     *
     * @param string $filename
     * @param string $code JavaScript using "body".
     * @return string
     */
    protected function word_preview_script(string $filename, string $code): string {
        $label = get_string('previewof', 'local_submissioncheck', $filename);
        return 'return (function(label) {' .
            'var regions = document.querySelectorAll(\'[data-region="local-submissioncheck-docx"]\');' .
            'for (var i = 0; i < regions.length; i++) {' .
            '  var frame = regions[i].dataset.label === label ? regions[i].querySelector("iframe") : null;' .
            '  if (frame && regions[i].dataset.rendered) {' .
            '    var body = frame.contentDocument.body;' .
            '    return (function(body) {' . $code . '})(body);' .
            '  }' .
            '}' .
            'return false;' .
            '})(' . json_encode($label) . ');';
    }

    /**
     * Move the current upload of a student's submission (and Moodle's submission time) to another time.
     *
     * @Given /^the submission check upload time for "(?P<user>[^"]*)" in "(?P<activity>[^"]*)" is "(?P<time>[^"]*)"$/
     * @param string $username
     * @param string $activity
     * @param string $time
     */
    public function the_upload_time_is(string $username, string $activity, string $time) {
        $this->set_upload_time($this->current_upload($username, $activity), $this->parse_time($time));
    }

    /**
     * A student submitted a file at a given time (through the assignment, so the checks ran on it).
     *
     * @Given /^the submission check upload for "([^"]*)" in "([^"]*)" of "([^"]*)" was made at "([^"]*)"$/
     * @param string $username
     * @param string $activity
     * @param string $filepath Path from the Moodle root.
     * @param string $time
     */
    public function the_upload_was_made_at(string $username, string $activity, string $filepath, string $time) {
        global $CFG, $DB, $USER;
        $user = $DB->get_record('user', ['username' => $username], '*', MUST_EXIST);
        $cm = $this->get_cm_by_activity_name('assign', $activity);
        $assign = \local_submissioncheck\local\adapter\assign::load($cm);

        $previoususer = $USER;
        \core\session\manager::set_user($user);
        try {
            $draftid = file_get_unused_draft_itemid();
            get_file_storage()->create_file_from_pathname([
                'contextid' => context_user::instance($user->id)->id,
                'component' => 'user',
                'filearea' => 'draft',
                'itemid' => $draftid,
                'filepath' => '/',
                'filename' => basename($filepath),
            ], $CFG->dirroot . '/' . $filepath);
            $notices = [];
            $data = (object) ['files_filemanager' => $draftid, 'userid' => $user->id];
            if (!$assign->save_submission($data, $notices)) {
                throw new ExpectationException('The submission was not saved: ' . implode(' ', $notices), $this->getSession());
            }
        } finally {
            \core\session\manager::set_user($previoususer);
        }
        $this->set_upload_time($this->current_upload($username, $activity), $this->parse_time($time));
    }

    /**
     * Check how recent the current upload of a student's submission is.
     *
     * @Then /^the submission check upload time for "([^"]*)" in "([^"]*)" should be within the last "(\d+)" minutes$/
     * @param string $username
     * @param string $activity
     * @param int $minutes
     */
    public function the_upload_time_should_be_recent(string $username, string $activity, int $minutes) {
        $record = $this->current_upload($username, $activity);
        if ((int) $record->timeuploaded < time() - $minutes * MINSECS) {
            throw new ExpectationException('The upload was recorded at ' . userdate($record->timeuploaded) .
                ', more than ' . $minutes . ' minutes ago.', $this->getSession());
        }
    }

    /**
     * The current upload record of a student's assignment submission.
     *
     * @param string $username
     * @param string $activity
     * @return stdClass
     */
    protected function current_upload(string $username, string $activity): stdClass {
        global $DB;
        $user = $DB->get_record('user', ['username' => $username], '*', MUST_EXIST);
        $cm = $this->get_cm_by_activity_name('assign', $activity);
        $assign = \local_submissioncheck\local\adapter\assign::load($cm);
        $submission = \local_submissioncheck\local\adapter\assign::get_submission($assign, (int) $user->id);
        $record = $submission ? confirmation::current('mod_assign', (int) $submission->id) : null;
        if (!$record) {
            throw new ExpectationException("No checked upload for {$username} in {$activity}", $this->getSession());
        }
        return $record;
    }

    /**
     * Move an upload and its submission to another time.
     *
     * @param stdClass $record
     * @param int $time
     */
    protected function set_upload_time(stdClass $record, int $time): void {
        global $DB;
        $DB->set_field('local_submissioncheck_upload', 'timeuploaded', $time, ['id' => $record->id]);
        $DB->set_field('assign_submission', 'timemodified', $time, ['id' => $record->itemid]);
    }

    /**
     * A time given as a timestamp or as ##relative time##.
     *
     * @param string $time
     * @return int
     */
    protected function parse_time(string $time): int {
        if (is_numeric($time)) {
            return (int) $time;
        }
        $parsed = strtotime(trim($time, '#'));
        if ($parsed === false) {
            throw new ExpectationException("Unrecognised time: {$time}", $this->getSession());
        }
        return $parsed;
    }

    /**
     * Upload a file to an essay question's attachments in a quiz attempt.
     *
     * The core filemanager step finds form fields by label, and a question's file manager is not a form field.
     *
     * @When /^I upload "(?P<filepath>(?:[^"]|\\")*)" file to the attachments of "(?P<question>(?:[^"]|\\")*)"$/
     * @param string $filepath Path relative to the Moodle code directory.
     * @param string $question The question, as the "question" selector finds it (for example "Question 1").
     */
    public function i_upload_file_to_question_attachments(string $filepath, string $question): void {
        global $CFG;
        if (!$this->has_tag('_file_upload')) {
            throw new \Behat\Mink\Exception\DriverException('File upload tests must have the @_file_upload tag.');
        }
        $questionnode = $this->get_selected_node('question', $question);
        $filemanager = $this->find('css', '.attachments', false, $questionnode);
        $this->open_add_file_window($filemanager, get_string('pluginname', 'repository_upload'));
        $this->find('css', '.repository_upload .fp-upload-form form');
        $path = $CFG->dirroot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $filepath);
        if (!is_readable($path)) {
            throw new ExpectationException('The file to be uploaded does not exist.', $this->getSession());
        }
        $this->find_file('repo_upload_file')->attachFile($path);
        $this->find_button(get_string('upload', 'repository'))->press();
        $this->wait_for_pending_js();
    }
}
