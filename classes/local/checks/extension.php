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
 * Whether the file's name says what it really is.
 *
 * @package    local_submissioncheck
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class extension extends check {
    /** Extension => [types its content may be, language string naming what the name promises]. */
    public const EXPECTED = [
        'docx' => [['ooxml'], 'kind_word'], 'docm' => [['ooxml'], 'kind_word'], 'dotx' => [['ooxml'], 'kind_word'],
        'pptx' => [['ooxml'], 'kind_powerpoint'], 'pptm' => [['ooxml'], 'kind_powerpoint'],
        'ppsx' => [['ooxml'], 'kind_powerpoint'],
        'xlsx' => [['ooxml'], 'kind_excel'], 'xlsm' => [['ooxml'], 'kind_excel'],
        'doc' => [['ole', 'rtf'], 'kind_word'], 'ppt' => [['ole'], 'kind_powerpoint'], 'pps' => [['ole'], 'kind_powerpoint'],
        'xls' => [['ole'], 'kind_excel'],
        'pdf' => [['pdf'], 'kind_pdf'],
        'odt' => [['odf'], 'kind_odf'], 'odp' => [['odf'], 'kind_odf'], 'ods' => [['odf'], 'kind_odf'],
        'rtf' => [['rtf'], 'kind_rtf'],
        'jpg' => [['jpeg'], 'kind_image'], 'jpeg' => [['jpeg'], 'kind_image'], 'png' => [['png'], 'kind_image'],
        'gif' => [['gif'], 'kind_image'],
        'zip' => [['zip', 'ooxml', 'odf', 'iwork'], 'kind_zip'],
        'html' => [['html'], 'kind_html'], 'htm' => [['html'], 'kind_html'],
        'pages' => [['iwork'], 'kind_iwork'], 'key' => [['iwork'], 'kind_iwork'], 'numbers' => [['iwork'], 'kind_iwork'],
    ];

    /** Detected type => language string naming what the content is. */
    public const ACTUAL = [
        'ooxml' => 'kind_office', 'ole' => 'kind_oldoffice', 'pdf' => 'kind_pdf', 'odf' => 'kind_odf', 'rtf' => 'kind_rtf',
        'jpeg' => 'kind_image', 'png' => 'kind_image', 'gif' => 'kind_image', 'zip' => 'kind_zip', 'html' => 'kind_html',
        'iwork' => 'kind_iwork', 'text' => 'kind_text',
    ];

    /**
     * Name of this check, stored with its results.
     *
     * @return string
     */
    public function get_name(): string {
        return 'extension';
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
        return isset(self::EXPECTED[$file->get_extension()]);
    }

    /**
     * Run the check.
     *
     * @param inspected_file $file
     * @return finding[]
     */
    public function run(inspected_file $file): array {
        [$allowed, $promised] = self::EXPECTED[$file->get_extension()];
        $type = $file->get_type();
        if (in_array($type, $allowed, true) || !isset(self::ACTUAL[$type])) {
            return [];
        }
        if ($type === inspected_file::TYPE_OLE && in_array('ooxml', $allowed, true)) {
            // Password-protected Office files are compound files; the password check reports those.
            return [];
        }
        return [new finding('wrongextension', [
            'name' => $file->get_filename(),
            'expected' => $promised,
            'actual' => self::ACTUAL[$type],
        ])];
    }
}
