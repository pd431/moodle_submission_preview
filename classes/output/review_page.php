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
     * @param bool $armed The page was opened after a first press of "Yes".
     * @return array|null
     */
    public static function data(\cm_info $cm, int $userid, bool $armed = false): ?array {
        $data = assign_adapter::report($cm, $userid);
        if (!$data) {
            return null;
        }
        $data = self::from_report($data);
        if ($data['actions']) {
            // Shown already armed when the server armed it (the first press without JavaScript).
            $data['actions']['armed'] = $armed && $data['actions']['armednow'] && $data['actions']['needsack'];
        }
        return $data;
    }

    /**
     * Add what the review layout needs to the report data: tab ids and labels, and the viewed count.
     *
     * @param array $data From assign_adapter::report().
     * @return array
     */
    public static function from_report(array $data): array {
        foreach ($data['files'] as $index => &$file) {
            $file['index'] = $index;
            $file['first'] = $index === 0;
            // Only what is wrong gets a word on the tab; a healthy file shows its name alone.
            $file['tabred'] = in_array($file['frame'], ['unreadable', 'protected'], true) || $file['hasbroken'];
            if (in_array($file['frame'], ['unreadable', 'protected'], true)) {
                $file['tabstatus'] = get_string('tabunreadable', 'local_submissioncheck');
            } else if ($file['hasbroken']) {
                $file['tabstatus'] = $file['brokencount'] === 1 ? get_string('tabbroken_one', 'local_submissioncheck')
                    : get_string('tabbroken', 'local_submissioncheck', $file['brokencount']);
            } else if ($file['frame'] === 'notype') {
                $file['tabstatus'] = get_string('tabnopreview', 'local_submissioncheck');
            } else {
                $file['tabstatus'] = '';
            }
        }
        unset($file);
        $data['armedlabel'] = report_builder::confirm_again_label($data['files'], 'confirmagain');
        $data['viewed'] = $data['multiple'] ? get_string(
            'viewedfiles',
            'local_submissioncheck',
            (object) ['viewed' => 1, 'total' => count($data['files'])]
        ) : '';
        return $data;
    }
}
