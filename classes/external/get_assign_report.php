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
use local_submissioncheck\output\review_page;

/**
 * The review page content for the current user on an assignment, re-rendered while a preview is being made.
 *
 * @package    local_submissioncheck
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_assign_report extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id of the assignment'),
        ]);
    }

    /**
     * Render the report.
     *
     * @param int $cmid
     * @return array
     */
    public static function execute(int $cmid): array {
        global $USER, $OUTPUT, $PAGE;
        ['cmid' => $cmid] = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid]);
        [, $cm] = get_course_and_cm_from_cmid($cmid, 'assign');
        self::validate_context($cm->context);
        require_capability('mod/assign:submit', $cm->context);
        $data = review_page::data($cm, (int) $USER->id);
        if (!$data) {
            return ['html' => '', 'pending' => false];
        }
        return [
            'html' => $OUTPUT->render_from_template('local_submissioncheck/review', $data),
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
            'html' => new external_value(PARAM_RAW, 'Report HTML, empty when there is nothing to show'),
            'pending' => new external_value(PARAM_BOOL, 'Whether a preview is still being prepared'),
        ]);
    }
}
