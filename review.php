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
 * The review page for a student's assignment upload: each file's checks, facts and preview, and the two answers.
 *
 * @package    local_submissioncheck
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_submissioncheck\local\adapter\assign as assign_adapter;
use local_submissioncheck\output\review_page;

$id = required_param('id', PARAM_INT);
[$course, $cm] = get_course_and_cm_from_cmid($id, 'assign');
require_login($course, false, $cm);
require_capability('mod/assign:submit', $cm->context);

$url = assign_adapter::review_url($cm->id);
$PAGE->set_url($url);
$PAGE->set_pagelayout('incourse');
$PAGE->add_body_class('local-submissioncheck-reviewpage');
$title = get_string('reviewtitle', 'local_submissioncheck', format_string($cm->name, true, ['context' => $cm->context]));
$PAGE->set_title($title);
$PAGE->set_heading(format_string($course->fullname, true, ['context' => $cm->context]));
$PAGE->navbar->add(get_string('reportheading', 'local_submissioncheck'), $url);
if (isset($PAGE->activityheader)) {
    $PAGE->activityheader->set_description('');
}

$data = review_page::data($cm, (int) $USER->id, optional_param('armed', 0, PARAM_BOOL));
if (!$data) {
    redirect(assign_adapter::view_url($cm->id));
}

$PAGE->requires->js_call_amd('local_submissioncheck/review', 'init', [$data['uniqid']]);
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_submissioncheck/review', $data);
echo $OUTPUT->footer();
