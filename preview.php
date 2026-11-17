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
 * Sends the preview of a submitted file to the student who submitted it.
 *
 * @package    local_submissioncheck
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:ignore moodle.Files.RequireLogin.Missing -- require_login() is called once the file's activity is known.
require_once(__DIR__ . '/../../config.php');

use local_submissioncheck\local\adapter\assign as assign_adapter;
use local_submissioncheck\local\adapter\quiz as quiz_adapter;
use local_submissioncheck\local\html_preview;
use local_submissioncheck\local\preview;
use local_submissioncheck\local\word_markers;

$fileid = required_param('fileid', PARAM_INT);
$uploadid = optional_param('uploadid', 0, PARAM_INT);
$attemptid = optional_param('attemptid', 0, PARAM_INT);

$fs = get_file_storage();
$file = $fs->get_file_by_id($fileid);
if (!$file || $file->is_directory()) {
    send_file_not_found();
}

$allowed = false;
if ($uploadid) {
    $record = $DB->get_record('local_submissioncheck_upload', ['id' => $uploadid], '*', MUST_EXIST);
    [$course, $cm] = get_course_and_cm_from_cmid($record->cmid);
    require_login($course, false, $cm);
    $allowed = $DB->record_exists('local_submissioncheck_file', ['uploadid' => $record->id, 'fileid' => $file->get_id()])
        && $record->component === assign_adapter::COMPONENT && assign_adapter::can_act($record, (int) $USER->id);
} else if ($attemptid) {
    $attempt = $DB->get_record('quiz_attempts', ['id' => $attemptid], '*', MUST_EXIST);
    [$course, $cm] = get_course_and_cm_from_instance($attempt->quiz, 'quiz');
    require_login($course, false, $cm);
    $allowed = (int) $attempt->userid === (int) $USER->id && quiz_adapter::file_belongs_to_attempt($file, $attempt);
} else {
    require_login();
    $allowed = $file->get_component() === 'user' && $file->get_filearea() === 'draft'
        && (int) $file->get_contextid() === (int) context_user::instance($USER->id)->id;
}
if (!$allowed) {
    send_file_not_found();
}

$previewfile = preview::get_preview_file($file);
if (!$previewfile) {
    send_file_not_found();
}

if (preview::get_status($file, false)['kind'] === preview::KIND_HTML) {
    // Made here from the student's file: sandboxed, so nothing in it can run scripts, reach this site as the
    // student, or load anything from elsewhere.
    raise_memory_limit(MEMORY_EXTRA);
    core_php_time_limit::raise(60);
    \core\session\manager::write_close();
    $html = html_preview::render($file);
    if ($html === null) {
        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body><p>' .
            s(get_string('previewcouldnotread', 'local_submissioncheck', $file->get_filename())) . '</p></body></html>';
    }
    header('Content-Type: text/html');
    header("Content-Security-Policy: sandbox; default-src 'none'; style-src 'unsafe-inline'; img-src data:; font-src data:");
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: private, no-store');
    echo $html;
    die;
}

\core\session\manager::write_close();
if (preview::get_status($file, false)['kind'] === preview::KIND_DOCX && ($prepared = word_markers::prepare($file))) {
    // Linked items marked for the browser to draw as missing.
    send_file($prepared, $file->get_filename(), 0, 0, false, false, $file->get_mimetype(), false, ['dontdie' => true]);
    @unlink($prepared);
    die;
}
$name = $previewfile === $file ? $file->get_filename() : pathinfo($file->get_filename(), PATHINFO_FILENAME) . '.pdf';
send_stored_file($previewfile, 0, 0, false, ['filename' => $name, 'dontdie' => false]);
