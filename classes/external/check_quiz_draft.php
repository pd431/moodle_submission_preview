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
 * Checks and previews for the files attached to one quiz essay question, as they stand in the page.
 *
 * @package    local_submissioncheck
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class check_quiz_draft extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id of the quiz'),
            'attemptid' => new external_value(PARAM_INT, 'Attempt id'),
            'slot' => new external_value(PARAM_INT, 'Question slot'),
            'draftitemid' => new external_value(PARAM_INT, 'Draft area of the question\'s file manager'),
        ]);
    }

    /**
     * Check the files.
     *
     * @param int $cmid
     * @param int $attemptid
     * @param int $slot
     * @param int $draftitemid
     * @return array
     */
    public static function execute(int $cmid, int $attemptid, int $slot, int $draftitemid): array {
        global $OUTPUT;
        $params = self::validate_parameters(self::execute_parameters(), compact('cmid', 'attemptid', 'slot', 'draftitemid'));
        [, $cm] = get_course_and_cm_from_cmid($params['cmid'], 'quiz');
        self::validate_context($cm->context);
        require_capability('mod/quiz:attempt', $cm->context);
        $data = quiz_adapter::draft_report($params['cmid'], $params['attemptid'], $params['slot'], $params['draftitemid']);
        return [
            'html' => $data['hasfiles'] ? $OUTPUT->render_from_template('local_submissioncheck/quiz_question', $data) : '',
            'pending' => (bool) $data['pending'],
        ];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'html' => new external_value(PARAM_RAW, 'Report HTML, empty when no file is attached'),
            'pending' => new external_value(PARAM_BOOL, 'Whether a preview is still being prepared'),
        ]);
    }
}
