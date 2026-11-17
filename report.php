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
 * Check results for an activity, for staff given the capability.
 *
 * @package    local_submissioncheck
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_submissioncheck\local\confirmation;
use local_submissioncheck\local\result_store;
use local_submissioncheck\output\report_builder;

$cmid = required_param('id', PARAM_INT);
[$course, $cm] = get_course_and_cm_from_cmid($cmid);
require_login($course, false, $cm);
require_capability('local/submissioncheck:viewresults', $cm->context);

$url = new moodle_url('/local/submissioncheck/report.php', ['id' => $cm->id]);
$PAGE->set_url($url);
$PAGE->set_title(get_string('resultslink', 'local_submissioncheck') . ': ' . format_string($cm->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_secondary_active_tab('modulepage');
$PAGE->navbar->add(get_string('resultslink', 'local_submissioncheck'), $url);

$assign = null;
$anonymous = false;
if ($cm->modname === 'assign') {
    $assign = \local_submissioncheck\local\adapter\assign::load($cm);
    $anonymous = $assign->is_blind_marking() && !has_capability('mod/assign:viewblinddetails', $cm->context);
}

// The current upload of each submission or attempt.
$records = $DB->get_records_select(
    'local_submissioncheck_upload',
    'cmid = :cmid AND state <> :replaced',
    ['cmid' => $cm->id, 'replaced' => confirmation::REPLACED],
    'itemid, id DESC'
);
$current = [];
foreach ($records as $record) {
    if (!isset($current[$record->itemid])) {
        $current[$record->itemid] = confirmation::current($record->component, (int) $record->itemid);
    }
}

$table = new html_table();
$table->head = [
    get_string('col_student', 'local_submissioncheck'),
    get_string('col_file', 'local_submissioncheck'),
    get_string('col_checks', 'local_submissioncheck'),
    get_string('col_acknowledged', 'local_submissioncheck'),
    get_string('col_confirmation', 'local_submissioncheck'),
    get_string('col_submitted', 'local_submissioncheck'),
];
$table->attributes['class'] = 'generaltable local-submissioncheck-results';
$table->data = [];

$name = function (int $userid, int $groupid) use ($DB, $anonymous, $assign) {
    if ($userid) {
        if ($anonymous) {
            return get_string('participant', 'mod_assign') . ' ' . $assign->get_uniqueid_for_user($userid);
        }
        $user = core_user::get_user($userid);
        return $user ? fullname($user) : '';
    }
    $group = $groupid ? groups_get_group($groupid) : null;
    return $group ? format_string($group->name) : get_string('defaultteam', 'mod_assign');
};

foreach ($current as $record) {
    if (!$record) {
        continue;
    }
    $state = (int) $record->state;
    if ($state === confirmation::AWAITING) {
        $confirmation = get_string(
            'state_awaiting',
            'local_submissioncheck',
            report_builder::time(confirmation::deadline($record))
        );
    } else if ($state === confirmation::CONFIRMED) {
        $confirmation = get_string('state_confirmed', 'local_submissioncheck', (object) [
            'name' => $name((int) $record->resolvedby, 0),
            'time' => userdate($record->timeresolved, get_string('strftimedatetimeshort', 'langconfig')),
        ]);
    } else {
        $confirmation = get_string(
            $record->component === 'mod_quiz' ? 'state_finishedunconfirmed' : 'state_autoaccepted',
            'local_submissioncheck'
        );
    }
    $acknowledged = !$record->haswarnings ? get_string('nowarnings', 'local_submissioncheck')
        : get_string($record->acknowledged ? 'acknowledged' : 'notacknowledged', 'local_submissioncheck');

    foreach (confirmation::files($record) as $entry) {
        $result = result_store::get($entry['file']);
        $summary = [];
        foreach ($result->findings as $finding) {
            $summary[] = s($finding->get_summary());
        }
        if (!$summary) {
            $summary[] = get_string($result->is_unavailable() ? 'checksnotrun' : 'noproblemsshort', 'local_submissioncheck');
        }
        $table->data[] = [
            s($name((int) $record->userid, (int) $record->groupid)),
            s($entry['filename']),
            implode('<br>', $summary),
            $acknowledged,
            s($confirmation),
            userdate($record->timeuploaded, get_string('strftimedatetimeshort', 'langconfig')),
        ];
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('resultsheading', 'local_submissioncheck', format_string($cm->name)));
echo html_writer::tag('p', \local_submissioncheck\local\activation::describe($cm->id));
if ($table->data) {
    echo html_writer::table($table);
} else {
    echo $OUTPUT->notification(get_string('noresults', 'local_submissioncheck'), 'info', false);
}
echo $OUTPUT->footer();
