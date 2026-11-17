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
 * Whether the file opens as the kind of file it is: complete, not cut short or damaged.
 *
 * PDF, JPEG, PNG and GIF have end markers. Word, PowerPoint, Excel and OpenDocument files are zip
 * packages, so the test is the package's integrity and its main part. Old Office files are
 * compound files whose size is a whole number of sectors.
 *
 * @package    local_submissioncheck
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class opens extends check {
    /** Main part each kind of Office package must contain. */
    public const MAIN_PARTS = [
        'word' => 'word/document.xml',
        'ppt' => 'ppt/presentation.xml',
        'xl' => 'xl/workbook.xml',
    ];

    /**
     * Name of this check, stored with its results.
     *
     * @return string
     */
    public function get_name(): string {
        return 'opens';
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
        return in_array($file->get_type(), [
            inspected_file::TYPE_PDF, inspected_file::TYPE_OOXML, inspected_file::TYPE_ODF, inspected_file::TYPE_ZIP,
            inspected_file::TYPE_IWORK, inspected_file::TYPE_OLE, inspected_file::TYPE_JPEG, inspected_file::TYPE_PNG,
            inspected_file::TYPE_GIF,
        ], true);
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
        return $this->is_complete($file) ? [] : [new finding('cannotopen', ['name' => $file->get_filename()])];
    }

    /**
     * Whether the file looks whole.
     *
     * @param inspected_file $file
     * @return bool
     */
    protected function is_complete(inspected_file $file): bool {
        switch ($file->get_type()) {
            case inspected_file::TYPE_PDF:
                return strpos($file->tail(2048), '%%EOF') !== false;
            case inspected_file::TYPE_JPEG:
                return strpos($file->tail(4096), "\xFF\xD9") !== false;
            case inspected_file::TYPE_PNG:
                return strpos($file->tail(64), 'IEND') !== false;
            case inspected_file::TYPE_GIF:
                return strpos($file->tail(16), "\x3B") !== false;
            case inspected_file::TYPE_OLE:
                return $file->get_size() >= 1536 && $file->get_size() % 512 === 0;
            case inspected_file::TYPE_OOXML:
                if (!$file->is_inspectable()) {
                    return true;
                }
                if (!$file->zip_is_consistent() || $file->get_entries() === null) {
                    return false;
                }
                $entries = $file->get_entries();
                foreach (self::MAIN_PARTS as $folder => $part) {
                    if ($file->find_entries('#^' . $folder . '/#') && !in_array($part, $entries, true)) {
                        return false;
                    }
                }
                return true;
            case inspected_file::TYPE_ODF:
                if (!$file->is_inspectable()) {
                    return true;
                }
                return $file->zip_is_consistent() && in_array('content.xml', $file->get_entries() ?? [], true);
            default:
                return !$file->is_inspectable() || $file->zip_is_consistent();
        }
    }
}
