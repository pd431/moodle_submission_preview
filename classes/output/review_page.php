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

namespace local_submissioncheck\output;

use local_submissioncheck\local\adapter\assign as assign_adapter;

/**
 * The review page for an assignment upload: one tab per file with its checks and facts beside its preview, and the
 * answers in a bar fixed to the bottom of the window. Built from the report data the assign adapter makes.
 *
 * @package    local_submissioncheck
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class review_page {
    /**
     * Template data for the review page, or null when the student has no upload to review.
     *
     * @param \cm_info $cm
     * @param int $userid
     * @return array|null
     */
    public static function data(\cm_info $cm, int $userid): ?array {
        $data = assign_adapter::report($cm, $userid);
        return $data ? self::from_report($data) : null;
    }

    /**
     * Add what the review layout needs to the report data: tab ids and labels, and the viewed count.
     *
     * @param array $data From assign_adapter::report().
     * @return array
     */
    public static function from_report(array $data): array {
        foreach ($data['files'] as $index => &$file) {
            $warnings = count($file['findings']);
            $file['index'] = $index;
            $file['first'] = $index === 0;
            $file['haswarnings'] = $warnings > 0;
            if ($warnings) {
                $file['tabstatus'] = $warnings === 1 ? get_string('tabwarnings_one', 'local_submissioncheck')
                    : get_string('tabwarnings', 'local_submissioncheck', $warnings);
            } else if (!empty($file['checksunavailable'])) {
                $file['tabstatus'] = get_string('checksnotrun', 'local_submissioncheck');
            } else {
                $file['tabstatus'] = get_string('noproblemsshort', 'local_submissioncheck');
            }
        }
        unset($file);
        $data['viewed'] = $data['multiple'] ? get_string(
            'viewedfiles',
            'local_submissioncheck',
            (object) ['viewed' => 1, 'total' => count($data['files'])]
        ) : '';
        return $data;
    }
}
