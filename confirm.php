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
 * The student's answer in the confirmation window: this is the right file, or take me back.
 *
 * @package    local_submissioncheck
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_submissioncheck\local\adapter\assign as assign_adapter;
use local_submissioncheck\local\confirmation;

$uploadid = required_param('uploadid', PARAM_INT);
$action = required_param('action', PARAM_ALPHA);

$record = $DB->get_record('local_submissioncheck_upload', ['id' => $uploadid], '*', MUST_EXIST);
[$course, $cm] = get_course_and_cm_from_cmid($record->cmid, 'assign');
require_login($course, false, $cm);
require_sesskey();
$PAGE->set_url(new moodle_url('/local/submissioncheck/confirm.php'));

if ($record->component !== assign_adapter::COMPONENT || !assign_adapter::can_act($record, (int) $USER->id)) {
    throw new moodle_exception('nopermissions', 'error', '', get_string('reportheading', 'local_submissioncheck'));
}
require_capability('mod/assign:submit', $cm->context);

$viewurl = assign_adapter::view_url($cm->id);
if ($action === 'back') {
    $replace = assign_adapter::replace_state(assign_adapter::load($cm), (int) $USER->id);
    if (!$replace['can']) {
        redirect($viewurl, $replace['reason'], null, \core\output\notification::NOTIFY_WARNING);
    }
    \local_submissioncheck\event\upload_returned::create([
        'objectid' => $record->id,
        'context' => $cm->context,
        'relateduserid' => $record->userid ?: null,
        'other' => ['component' => $record->component, 'itemid' => $record->itemid],
    ])->trigger();
    redirect(assign_adapter::edit_url($cm->id));
}

if ($action === 'confirm') {
    if (!confirmation::confirm($record, (int) $USER->id)) {
        redirect(
            $viewurl,
            get_string('windowended', 'local_submissioncheck', confirmation::window() / MINSECS),
            null,
            \core\output\notification::NOTIFY_INFO
        );
    }
    redirect($viewurl);
}

throw new moodle_exception('invalidparameter', 'debug');
