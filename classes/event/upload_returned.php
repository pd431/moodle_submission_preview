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

namespace local_submissioncheck\event;

/**
 * A student went back to replace a submitted file.
 *
 * @package    local_submissioncheck
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class upload_returned extends \core\event\base {
    /**
     * Set the event's CRUD level, education level and object table.
     */
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'local_submissioncheck_upload';
    }

    /**
     * Name of this event.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event_upload_returned', 'local_submissioncheck');
    }

    /**
     * Describe the event for logs.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '$this->userid' " . ('returned' === 'confirmed' ? 'confirmed' : 'went back to replace') .
            " the submitted files recorded as upload '$this->objectid' in the course module with id '$this->contextinstanceid'.";
    }

    /**
     * Where the event happened.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/local/submissioncheck/report.php', ['id' => $this->contextinstanceid]);
    }

    /**
     * How the object id is mapped on restore.
     *
     * @return array
     */
    public static function get_objectid_mapping() {
        return ['db' => 'local_submissioncheck_upload', 'restore' => \core\event\base::NOT_MAPPED];
    }

    /**
     * How the other data is mapped on restore.
     *
     * @return bool
     */
    public static function get_other_mapping() {
        return false;
    }
}
