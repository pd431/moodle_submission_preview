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
 * Site settings.
 *
 * @package    local_submissioncheck
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_submissioncheck', get_string('pluginname', 'local_submissioncheck'));
    $ADMIN->add('localplugins', $settings);

    if ($ADMIN->fulltree) {
        $settings->add(new admin_setting_configtext(
            'local_submissioncheck/defaulttags',
            get_string('defaulttags', 'local_submissioncheck'),
            get_string('defaulttags_desc', 'local_submissioncheck'),
            '',
            PARAM_TEXT
        ));
        $settings->add(new admin_setting_configtext(
            'local_submissioncheck/windowminutes',
            get_string('windowminutes', 'local_submissioncheck'),
            get_string('windowminutes_desc', 'local_submissioncheck'),
            15,
            PARAM_INT
        ));
        $settings->add(new admin_setting_configtext(
            'local_submissioncheck/reminderminutes',
            get_string('reminderminutes', 'local_submissioncheck'),
            get_string('reminderminutes_desc', 'local_submissioncheck'),
            3,
            PARAM_INT
        ));
        $settings->add(new admin_setting_configtext(
            'local_submissioncheck/helplink',
            get_string('helplink', 'local_submissioncheck'),
            get_string('helplink_desc', 'local_submissioncheck'),
            'https://aka.ms/file_embedding',
            PARAM_URL
        ));
        $settings->add(new admin_setting_configtextarea(
            'local_submissioncheck/signinhosts',
            get_string('signinhosts', 'local_submissioncheck'),
            get_string('signinhosts_desc', 'local_submissioncheck'),
            implode("\n", \local_submissioncheck\local\checks\linked_media::DEFAULT_SIGNIN_HOSTS),
            PARAM_RAW_TRIMMED
        ));
    }
}
