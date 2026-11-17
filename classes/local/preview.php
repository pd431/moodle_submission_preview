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

use core_files\conversion;
use core_files\converter;

/**
 * A preview of a submitted file as a marker would see it, made on the server.
 *
 * Office and OpenDocument files are converted to PDF with the site's document converter, on the
 * server, which cannot see anything on the student's computer: media the file only links to is
 * missing from the preview, as it would be for a marker. PDFs and images are shown as they are.
 *
 * @package    local_submissioncheck
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class preview {
    /** @var string The preview can be shown. */
    public const READY = 'ready';
    /** @var string The conversion is queued or running. */
    public const PENDING = 'pending';
    /** @var string This kind of file has no preview. */
    public const UNSUPPORTED = 'unsupported';
    /** @var string The converter failed or is not set up. */
    public const UNAVAILABLE = 'unavailable';

    /** Extensions the site's converter is expected to handle when it is set up. */
    public const DOCUMENT_EXTENSIONS = ['doc', 'docx', 'docm', 'dot', 'dotx', 'rtf', 'odt', 'ppt', 'pptx', 'pptm', 'pps', 'ppsx',
        'odp', 'xls', 'xlsx', 'xlsm', 'ods', 'txt', 'csv', 'html', 'htm'];

    /** Image types browsers show directly. */
    public const IMAGE_TYPES = [inspected_file::TYPE_JPEG, inspected_file::TYPE_PNG, inspected_file::TYPE_GIF];

    /**
     * Where a file's preview stands, starting the conversion if it is not already under way.
     *
     * @param \stored_file $file
     * @param bool $start Queue a conversion when none exists.
     * @return array ['status' => one of the constants, 'kind' => 'pdf'|'image'|null]
     */
    public static function get_status(\stored_file $file, bool $start = true): array {
        $ext = \core_text::strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION));
        $mimetype = $file->get_mimetype();

        if ($ext === 'pdf' && $mimetype === 'application/pdf' && self::sniff($file) === inspected_file::TYPE_PDF) {
            return ['status' => self::READY, 'kind' => 'pdf'];
        }
        if (in_array(self::sniff($file), self::IMAGE_TYPES, true)) {
            return ['status' => self::READY, 'kind' => 'image'];
        }
        if (!in_array($ext, self::DOCUMENT_EXTENSIONS, true)) {
            return ['status' => self::UNSUPPORTED, 'kind' => null];
        }

        if (testing::converter_down()) {
            return ['status' => self::UNAVAILABLE, 'kind' => null];
        }
        if (testing::use_test_converter()) {
            return self::test_preview($file) ? ['status' => self::READY, 'kind' => 'pdf']
                : ['status' => self::UNAVAILABLE, 'kind' => null];
        }

        $converter = new converter();
        if (!$converter->can_convert_storedfile_to($file, 'pdf')) {
            return ['status' => self::UNAVAILABLE, 'kind' => null];
        }
        $conversions = conversion::get_conversions_for_file($file, 'pdf');
        $conversion = $conversions ? reset($conversions) : null;
        if ($conversion && $conversion->get('status') == conversion::STATUS_COMPLETE && $conversion->get_destfile()) {
            return ['status' => self::READY, 'kind' => 'pdf'];
        }
        if ($conversion && $conversion->get('status') == conversion::STATUS_FAILED) {
            return ['status' => self::UNAVAILABLE, 'kind' => null];
        }
        if ($start && !$conversion) {
            self::queue($file);
        }
        return ['status' => self::PENDING, 'kind' => null];
    }

    /**
     * Queue the conversion to run in the background, once per file.
     *
     * @param \stored_file $file
     */
    public static function queue(\stored_file $file): void {
        $task = new \local_submissioncheck\task\convert_preview();
        $task->set_custom_data(['fileid' => $file->get_id()]);
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * Convert now. Called from the background task.
     *
     * @param \stored_file $file
     */
    public static function convert(\stored_file $file): void {
        $converter = new converter();
        if ($converter->can_convert_storedfile_to($file, 'pdf')) {
            $converter->start_conversion($file, 'pdf');
        }
    }

    /**
     * The file to send to the browser for a ready preview.
     *
     * @param \stored_file $file The submitted file.
     * @return \stored_file|null
     */
    public static function get_preview_file(\stored_file $file): ?\stored_file {
        $status = self::get_status($file, false);
        if ($status['status'] !== self::READY) {
            return null;
        }
        if ($status['kind'] === 'image' || \core_text::strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION)) === 'pdf') {
            return $file;
        }
        if (testing::use_test_converter()) {
            return self::test_preview($file);
        }
        $conversions = conversion::get_conversions_for_file($file, 'pdf');
        foreach ($conversions as $conversion) {
            if ($conversion->get('status') == conversion::STATUS_COMPLETE && ($dest = $conversion->get_destfile())) {
                return $dest;
            }
        }
        return null;
    }

    /**
     * What a stored file really is.
     *
     * @param \stored_file $file
     * @return string
     */
    protected static function sniff(\stored_file $file): string {
        static $cache = [];
        $key = $file->get_contenthash() . '/' . $file->get_filename();
        if (!isset($cache[$key])) {
            $handle = $file->get_content_file_handle();
            $head = $handle ? (string) fread($handle, 16) : '';
            if ($handle) {
                fclose($handle);
            }
            if (strncmp($head, '%PDF-', 5) === 0) {
                $cache[$key] = inspected_file::TYPE_PDF;
            } else if (strncmp($head, "\xFF\xD8\xFF", 3) === 0) {
                $cache[$key] = inspected_file::TYPE_JPEG;
            } else if (strncmp($head, "\x89PNG\r\n\x1A\n", 8) === 0) {
                $cache[$key] = inspected_file::TYPE_PNG;
            } else if (strncmp($head, 'GIF8', 4) === 0) {
                $cache[$key] = inspected_file::TYPE_GIF;
            } else {
                $cache[$key] = inspected_file::TYPE_UNKNOWN;
            }
        }
        return $cache[$key];
    }

    /**
     * The stand-in converter's output: a fixed PDF stored against the source file.
     *
     * @param \stored_file $file
     * @return \stored_file|null
     */
    protected static function test_preview(\stored_file $file): ?\stored_file {
        global $CFG;
        $fs = get_file_storage();
        $record = [
            'contextid' => \context_system::instance()->id,
            'component' => 'local_submissioncheck',
            'filearea' => 'testpreview',
            'itemid' => $file->get_id(),
            'filepath' => '/',
            'filename' => 'preview.pdf',
        ];
        $existing = $fs->get_file(
            $record['contextid'],
            $record['component'],
            $record['filearea'],
            $record['itemid'],
            $record['filepath'],
            $record['filename']
        );
        if ($existing) {
            return $existing;
        }
        return $fs->create_file_from_pathname($record, $CFG->dirroot . '/local/submissioncheck/tests/fixtures/preview.pdf');
    }
}
