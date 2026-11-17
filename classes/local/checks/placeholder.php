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

namespace local_submissioncheck\local\checks;

use local_submissioncheck\local\check;
use local_submissioncheck\local\finding;
use local_submissioncheck\local\inspected_file;

/**
 * Files that stand in for a document without being it: empty uploads, Word lock files and shortcuts.
 *
 * These are judged by content and name, not by size alone, so a short genuine file is never
 * flagged just for being small.
 *
 * @package    local_submissioncheck
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class placeholder extends check {
    /** Word's owner (lock) files are 162 bytes; allow some slack. */
    public const LOCKFILE_MAX_BYTES = 1024;

    /**
     * Name of this check, stored with its results.
     *
     * @return string
     */
    public function get_name(): string {
        return 'placeholder';
    }

    /**
     * Version of this check; changing it discards cached results.
     *
     * @return int
     */
    public function get_version(): int {
        return 1;
    }

    /**
     * Whether this check applies to the file.
     *
     * @param inspected_file $file
     * @return bool
     */
    public function applies(inspected_file $file): bool {
        return true;
    }

    /**
     * Whether a finding from this check makes later checks pointless.
     *
     * @return bool
     */
    public function stops_later_checks(): bool {
        return true;
    }

    /**
     * Run the check.
     *
     * @param inspected_file $file
     * @return finding[]
     */
    public function run(inspected_file $file): array {
        $name = $file->get_filename();
        $ext = $file->get_extension();
        $type = $file->get_type();
        $params = ['name' => $name];

        if ($type === inspected_file::TYPE_EMPTY) {
            return [new finding('zerobytes', $params)];
        }
        if (strpos($name, '~$') === 0 && $file->get_size() <= self::LOCKFILE_MAX_BYTES) {
            return [new finding('lockfile', $params)];
        }
        if ($type === inspected_file::TYPE_LNK || $ext === 'lnk') {
            return [new finding('windowsshortcut', $params)];
        }
        if ($type === inspected_file::TYPE_URLSHORTCUT || in_array($ext, ['url', 'webloc'], true)) {
            return [new finding('webshortcut', $params)];
        }
        if ($type === inspected_file::TYPE_GOOGLE || in_array($ext, ['gdoc', 'gsheet', 'gslides', 'gdraw', 'gform'], true)) {
            $kinds = ['gsheet' => 'googlesheet', 'gslides' => 'googleslides'];
            return [new finding('googleshortcut', $params + ['kind' => $kinds[$ext] ?? 'googledoc'])];
        }
        return [];
    }
}
