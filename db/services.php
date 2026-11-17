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
 * Web services.
 *
 * @package    local_submissioncheck
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_submissioncheck_get_assign_report' => [
        'classname' => \local_submissioncheck\external\get_assign_report::class,
        'description' => 'The check report for the current user on an assignment page.',
        'type' => 'read',
        'ajax' => true,
    ],
    'local_submissioncheck_check_quiz_draft' => [
        'classname' => \local_submissioncheck\external\check_quiz_draft::class,
        'description' => 'Checks and previews for files attached to a quiz essay question that is being answered.',
        'type' => 'read',
        'ajax' => true,
    ],
    'local_submissioncheck_get_quiz_summary' => [
        'classname' => \local_submissioncheck\external\get_quiz_summary::class,
        'description' => 'Checks for every file attached in a quiz attempt, for the summary page.',
        'type' => 'read',
        'ajax' => true,
    ],
    'local_submissioncheck_acknowledge_quiz' => [
        'classname' => \local_submissioncheck\external\acknowledge_quiz::class,
        'description' => 'Record that the student read the warnings about their quiz attachments.',
        'type' => 'write',
        'ajax' => true,
    ],
];
