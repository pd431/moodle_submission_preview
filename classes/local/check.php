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
 * One check run on a submitted file.
 *
 * A check looks at one file and returns findings. It never changes the file or the submission.
 * Results are cached by content and name, so a check must depend only on those, and its version
 * must rise whenever what it reports for the same file would change.
 *
 * @package    local_submissioncheck
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class check {
    /**
     * Short name, used as the cache key and in language string keys.
     *
     * @return string
     */
    abstract public function get_name(): string;

    /**
     * Version of what this check reports; raising it invalidates cached results.
     *
     * @return int
     */
    abstract public function get_version(): int;

    /**
     * Whether this check has anything to say about this kind of file.
     *
     * @param inspected_file $file
     * @return bool
     */
    abstract public function applies(inspected_file $file): bool;

    /**
     * Run the check.
     *
     * @param inspected_file $file
     * @return finding[]
     */
    abstract public function run(inspected_file $file): array;

    /**
     * Whether a finding from this check makes the remaining checks meaningless for the file.
     *
     * @return bool
     */
    public function stops_later_checks(): bool {
        return false;
    }
}
