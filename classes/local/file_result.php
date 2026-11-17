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
 * Everything the checks found in one file.
 *
 * @package    local_submissioncheck
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class file_result {
    /** @var string */
    public $filename;

    /** @var finding[] */
    public $findings;

    /** @var bool False when some checks could not run. */
    public $complete;

    /**
     * Constructor.
     *
     * @param string $filename
     * @param finding[] $findings
     * @param bool $complete
     */
    public function __construct(string $filename, array $findings, bool $complete) {
        $this->filename = $filename;
        $this->findings = $findings;
        $this->complete = $complete;
    }

    /**
     * Whether anything was found.
     *
     * @return bool
     */
    public function has_warnings(): bool {
        return !empty($this->findings);
    }

    /**
     * Whether no check could run at all.
     *
     * @return bool
     */
    public function is_unavailable(): bool {
        return !$this->complete && empty($this->findings);
    }
}
