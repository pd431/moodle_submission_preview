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
 * Switches used only by automated tests: a fast stand-in converter, a converter outage, a check outage.
 *
 * Nothing here is read unless a PHPUnit or Behat site is running.
 *
 * @package    local_submissioncheck
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class testing {
    /**
     * Whether this is a test site.
     *
     * @return bool
     */
    public static function enabled(): bool {
        return (defined('BEHAT_SITE_RUNNING') && BEHAT_SITE_RUNNING) || (defined('BEHAT_TEST') && BEHAT_TEST)
            || (defined('PHPUNIT_TEST') && PHPUNIT_TEST);
    }

    /**
     * Set a test switch.
     *
     * @param string $name test_converter, converter_down or service_down
     * @param bool $on
     */
    public static function set(string $name, bool $on): void {
        if (!self::enabled()) {
            throw new \coding_exception('Submission check test switches are only for test sites.');
        }
        set_config('testing_' . $name, $on ? 1 : 0, 'local_submissioncheck');
    }

    /**
     * Read a test switch.
     *
     * @param string $name
     * @return bool
     */
    protected static function get(string $name): bool {
        return self::enabled() && !empty(get_config('local_submissioncheck', 'testing_' . $name));
    }

    /**
     * Whether previews come from the stand-in converter.
     *
     * @return bool
     */
    public static function use_test_converter(): bool {
        return self::get('test_converter');
    }

    /**
     * Whether the converter is to behave as if it were down.
     *
     * @return bool
     */
    public static function converter_down(): bool {
        return self::get('converter_down');
    }

    /**
     * Whether the checks are to behave as if they were down.
     *
     * @return bool
     */
    public static function service_down(): bool {
        return self::get('service_down');
    }
}
