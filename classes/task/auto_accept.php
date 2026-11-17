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

namespace local_submissioncheck\task;

use local_submissioncheck\local\confirmation;

/**
 * Accept every upload whose confirmation window has ended without an answer.
 *
 * @package    local_submissioncheck
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class auto_accept extends \core\task\scheduled_task {
    /**
     * Name of this task.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_autoaccept', 'local_submissioncheck');
    }

    /**
     * Run the task.
     */
    public function execute() {
        $count = confirmation::autoaccept_expired(time());
        mtrace("Submission checks: {$count} upload(s) accepted automatically.");
    }
}
