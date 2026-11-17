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

namespace local_submissioncheck\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_submissioncheck\local\adapter\quiz as quiz_adapter;

/**
 * Record that the student read the warnings about their quiz attachments.
 *
 * @package    local_submissioncheck
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class acknowledge_quiz extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id of the quiz'),
            'attemptid' => new external_value(PARAM_INT, 'Attempt id'),
        ]);
    }

    /**
     * Record the acknowledgement.
     *
     * @param int $cmid
     * @param int $attemptid
     * @return array
     */
    public static function execute(int $cmid, int $attemptid): array {
        $params = self::validate_parameters(self::execute_parameters(), compact('cmid', 'attemptid'));
        [, $cm] = get_course_and_cm_from_cmid($params['cmid'], 'quiz');
        self::validate_context($cm->context);
        require_capability('mod/quiz:attempt', $cm->context);
        quiz_adapter::acknowledge($params['cmid'], $params['attemptid']);
        return ['acknowledged' => true];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'acknowledged' => new external_value(PARAM_BOOL, 'Recorded'),
        ]);
    }
}
