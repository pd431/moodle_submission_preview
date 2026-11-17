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

use local_submissioncheck\local\preview;

/**
 * Make the PDF preview of one submitted file in the background.
 *
 * @package    local_submissioncheck
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class convert_preview extends \core\task\adhoc_task {
    /**
     * Run the task.
     */
    public function execute() {
        $data = $this->get_custom_data();
        $file = get_file_storage()->get_file_by_id((int) ($data->fileid ?? 0));
        if ($file) {
            preview::convert($file);
        }
    }
}
