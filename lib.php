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
 * Add the "Submission check and preview" setting to assignment and quiz settings: in an assignment directly under
 * "Require that students accept the submission statement", in a quiz at the end of "Question behaviour".
 *
 * @param moodleform_mod $formwrapper
 * @param MoodleQuickForm $mform
 */
function local_submissioncheck_coursemodule_standard_elements($formwrapper, $mform) {
    $modname = $formwrapper->get_current()->modulename ?? '';
    if (!in_array($modname, activation::MODULES, true)) {
        return;
    }
    $cmid = (int) ($formwrapper->get_current()->coursemodule ?? 0);
    $select = $mform->createElement('select', 'submissioncheck_mode', get_string('mode', 'local_submissioncheck'), [
        activation::MODE_AUTO => activation::follow_label($cmid),
        activation::MODE_ON => get_string('mode_on', 'local_submissioncheck'),
        activation::MODE_OFF => get_string('mode_off', 'local_submissioncheck'),
    ]);
    $before = local_submissioncheck_setting_position($mform, $modname);
    if ($before !== null) {
        $mform->insertElementBefore($select, $before);
    } else {
        $mform->addElement($select);
    }
    $mform->addHelpButton('submissioncheck_mode', 'mode', 'local_submissioncheck');
    $mform->setDefault('submissioncheck_mode', $cmid ? activation::get_mode($cmid) : activation::MODE_AUTO);
}

/**
 * The element the setting goes before: in an assignment, whatever follows the submission statement setting; in a
 * quiz, the section after "Question behaviour". Null when the form does not have it (the setting then goes last).
 *
 * @param MoodleQuickForm $mform
 * @param string $modname
 * @return string|null
 */
function local_submissioncheck_setting_position(MoodleQuickForm $mform, string $modname): ?string {
    if ($modname === 'quiz') {
        return $mform->elementExists('reviewoptionshdr') ? 'reviewoptionshdr' : null;
    }
    if (!$mform->elementExists('requiresubmissionstatement')) {
        return $mform->elementExists('groupsubmissionsettings') ? 'groupsubmissionsettings' : null;
    }
    $index = $mform->_elementIndex['requiresubmissionstatement'];
    $count = count($mform->_elements);
    for ($i = $index + 1; $i < $count; $i++) {
        $name = $mform->_elements[$i]->getName();
        if ($name !== null && $name !== '' && $mform->elementExists($name)) {
            return $name;
        }
    }
    return null;
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
