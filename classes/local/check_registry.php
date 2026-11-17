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

namespace local_submissioncheck\local;

/**
 * The checks that run, in order, and running them on one file within a time budget.
 *
 * @package    local_submissioncheck
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class check_registry {
    /**
     * The checks, in the order they run. A placeholder or damaged file stops the later checks.
     *
     * @return check[]
     */
    public static function get_checks(): array {
        return [
            new checks\placeholder(),
            new checks\opens(),
            new checks\extension(),
            new checks\encrypted(),
            new checks\linked_media(),
            new checks\empty_text(),
        ];
    }
}
