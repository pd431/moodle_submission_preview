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
 * Whether checks are on for an activity: its own setting, or the site's tags when it is on Automatic.
 *
 * @package    local_submissioncheck
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class activation {
    /** @var string Follow the site's tag list. */
    public const MODE_AUTO = 'auto';
    /** @var string Always on. */
    public const MODE_ON = 'on';
    /** @var string Always off. */
    public const MODE_OFF = 'off';

    /** Activities the checks know how to work with. */
    public const MODULES = ['assign', 'quiz'];

    /**
     * The activity's own setting.
     *
     * @param int $cmid
     * @return string
     */
    public static function get_mode(int $cmid): string {
        global $DB;
        $mode = $DB->get_field('local_submissioncheck_cfg', 'mode', ['cmid' => $cmid]);
        return in_array($mode, [self::MODE_ON, self::MODE_OFF], true) ? $mode : self::MODE_AUTO;
    }

    /**
     * Save the activity's own setting.
     *
     * @param int $cmid
     * @param string $mode
     */
    public static function set_mode(int $cmid, string $mode): void {
        global $DB;
        if (!in_array($mode, [self::MODE_AUTO, self::MODE_ON, self::MODE_OFF], true)) {
            $mode = self::MODE_AUTO;
        }
        $existing = $DB->get_record('local_submissioncheck_cfg', ['cmid' => $cmid]);
        if ($existing) {
            if ($existing->mode !== $mode) {
                $DB->set_field('local_submissioncheck_cfg', 'mode', $mode, ['id' => $existing->id]);
            }
        } else {
            $DB->insert_record('local_submissioncheck_cfg', (object) ['cmid' => $cmid, 'mode' => $mode]);
        }
    }

    /**
     * The site's tags that turn checks on, lower case.
     *
     * @return string[]
     */
    public static function site_tags(): array {
        $raw = (string) get_config('local_submissioncheck', 'defaulttags');
        $tags = array_filter(array_map(function ($tag) {
            return \core_text::strtolower(trim($tag));
        }, explode(',', $raw)), 'strlen');
        return array_values(array_unique($tags));
    }

    /**
     * The activity's tags that are on the site's list.
     *
     * @param string[] $tagnames The activity's tag names.
     * @return string[]
     */
    public static function matching_tags(array $tagnames): array {
        $site = self::site_tags();
        $matches = [];
        foreach ($tagnames as $name) {
            if (in_array(\core_text::strtolower(trim($name)), $site, true)) {
                $matches[] = $name;
            }
        }
        return $matches;
    }

    /**
     * Names of an activity's tags.
     *
     * @param int $cmid
     * @return string[]
     */
    public static function activity_tags(int $cmid): array {
        return array_values(\core_tag_tag::get_item_tags_array('core', 'course_modules', $cmid));
    }

    /**
     * Whether a setting and a set of tags turn checks on.
     *
     * @param string $mode
     * @param string[] $tagnames
     * @return bool
     */
    public static function resolve(string $mode, array $tagnames): bool {
        if ($mode === self::MODE_ON) {
            return true;
        }
        if ($mode === self::MODE_OFF) {
            return false;
        }
        return !empty(self::matching_tags($tagnames));
    }

    /**
     * Whether checks are switched on for an activity (before any activity-specific requirement).
     *
     * @param \cm_info|\stdClass $cm
     * @return bool
     */
    public static function is_switched_on($cm): bool {
        if (!in_array($cm->modname, self::MODULES, true)) {
            return false;
        }
        return self::resolve(self::get_mode((int) $cm->id), self::activity_tags((int) $cm->id));
    }

    /**
     * Words explaining to staff why checks are on or off for an activity.
     *
     * @param int $cmid
     * @return string
     */
    public static function describe(int $cmid): string {
        $mode = self::get_mode($cmid);
        if ($mode === self::MODE_ON) {
            return get_string('status_on', 'local_submissioncheck');
        }
        if ($mode === self::MODE_OFF) {
            return get_string('status_off', 'local_submissioncheck');
        }
        $site = self::site_tags();
        if (!$site) {
            return get_string('status_auto_notags', 'local_submissioncheck');
        }
        $matches = self::matching_tags(self::activity_tags($cmid));
        if ($matches) {
            return get_string('status_auto_on', 'local_submissioncheck', implode(', ', $matches));
        }
        return get_string('status_auto_off', 'local_submissioncheck', implode(', ', $site));
    }

    /**
     * Forget an activity's setting.
     *
     * @param int $cmid
     */
    public static function delete(int $cmid): void {
        global $DB;
        $DB->delete_records('local_submissioncheck_cfg', ['cmid' => $cmid]);
    }
}
