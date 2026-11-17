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
 * Moodle callbacks: the activity setting, navigation and clean-up.
 *
 * @package    local_submissioncheck
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_submissioncheck\local\activation;

/**
 * Add the "Submission checks" setting to assignment and quiz settings.
 *
 * @param moodleform_mod $formwrapper
 * @param MoodleQuickForm $mform
 */
function local_submissioncheck_coursemodule_standard_elements($formwrapper, $mform) {
    $modname = $formwrapper->get_current()->modulename ?? '';
    if (!in_array($modname, activation::MODULES, true)) {
        return;
    }
    $mform->addElement('header', 'local_submissioncheck_header', get_string('settingsheader', 'local_submissioncheck'));
    $mform->addElement('select', 'submissioncheck_mode', get_string('mode', 'local_submissioncheck'), [
        activation::MODE_AUTO => get_string('mode_auto', 'local_submissioncheck'),
        activation::MODE_ON => get_string('mode_on', 'local_submissioncheck'),
        activation::MODE_OFF => get_string('mode_off', 'local_submissioncheck'),
    ]);
    $mform->addHelpButton('submissioncheck_mode', 'mode', 'local_submissioncheck');

    $cmid = (int) ($formwrapper->get_current()->coursemodule ?? 0);
    $mform->setDefault('submissioncheck_mode', $cmid ? activation::get_mode($cmid) : activation::MODE_AUTO);
    if ($cmid) {
        $mform->addElement('static', 'submissioncheck_status', '', activation::describe($cmid));
        // Staff should notice that checks are on without opening the section.
        $mform->setExpanded('local_submissioncheck_header', activation::is_switched_on($formwrapper->get_coursemodule()));
    }
}

/**
 * Refuse checks on an assignment that makes students click a submit button.
 *
 * With that setting the upload is a draft and its time is not the submission time, so the
 * confirmation window would mean something different.
 *
 * @param moodleform_mod $formwrapper
 * @param array $data
 * @return array Errors.
 */
function local_submissioncheck_coursemodule_validation($formwrapper, $data) {
    if (($data['modulename'] ?? '') !== 'assign' || !isset($data['submissioncheck_mode'])) {
        return [];
    }
    $tags = [];
    foreach ((array) ($data['tags'] ?? []) as $tag) {
        $tags[] = is_numeric($tag) ? (string) core_tag_tag::get($tag, 'name')->name : (string) $tag;
    }
    $on = activation::resolve($data['submissioncheck_mode'], $tags);
    if ($on && !empty($data['assignsubmission_file_enabled']) && !empty($data['submissiondrafts'])) {
        return ['submissioncheck_mode' => get_string('needsnodrafts', 'local_submissioncheck')];
    }
    return [];
}

/**
 * Save the setting.
 *
 * @param stdClass $data
 * @param stdClass $course
 * @return stdClass
 */
function local_submissioncheck_coursemodule_edit_post_actions($data, $course) {
    if (
        isset($data->submissioncheck_mode) && !empty($data->coursemodule)
            && in_array($data->modulename ?? '', activation::MODULES, true)
    ) {
        activation::set_mode((int) $data->coursemodule, (string) $data->submissioncheck_mode);
    }
    return $data;
}

/**
 * Remove what was recorded for an activity being deleted.
 *
 * @param stdClass $cm
 */
function local_submissioncheck_pre_course_module_delete($cm) {
    activation::delete((int) $cm->id);
    \local_submissioncheck\local\confirmation::delete_for_cm((int) $cm->id);
}
