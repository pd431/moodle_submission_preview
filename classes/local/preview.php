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
 * A preview of a submitted file as a marker would see it.
 *
 * Office and OpenDocument files are converted to PDF with the site's document converter when it has one, on the
 * server, which cannot see anything on the student's computer: media the file only links to is missing from the
 * preview, as it would be for a marker. Without a converter (or when it fails) the plugin's own renderers are used:
 * Word documents are drawn in the browser, spreadsheets, text files, web pages and the text of presentations are
 * rendered on the server (html_preview). PDFs, pictures, video and audio are shown as they are.
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
    /** @var string The converter failed or is not set up, and the plugin has no renderer for this kind of file. */
    public const UNAVAILABLE = 'unavailable';
    /** @var string The file is bigger than the plugin's renderers take. */
    public const TOO_LARGE = 'toolarge';

    /** @var string Kind of preview: a PDF, the file itself or the converter's. */
    public const KIND_PDF = 'pdf';
    /** @var string Kind of preview: a picture shown as it is. */
    public const KIND_IMAGE = 'image';
    /** @var string Kind of preview: a video played as it is. */
    public const KIND_VIDEO = 'video';
    /** @var string Kind of preview: a sound file played as it is. */
    public const KIND_AUDIO = 'audio';
    /** @var string Kind of preview: a Word document drawn in the browser. */
    public const KIND_DOCX = 'docx';
    /** @var string Kind of preview: an HTML page made on the server (html_preview). */
    public const KIND_HTML = 'html';

    /** Extensions the site's converter is expected to handle when it is set up. */
    public const DOCUMENT_EXTENSIONS = ['doc', 'docx', 'docm', 'dot', 'dotx', 'rtf', 'odt', 'ppt', 'pptx', 'pptm', 'pps', 'ppsx',
        'odp', 'xls', 'xlsx', 'xlsm', 'ods', 'txt', 'csv', 'html', 'htm'];

    /** Image types browsers show directly. */
    public const IMAGE_TYPES = [inspected_file::TYPE_JPEG, inspected_file::TYPE_PNG, inspected_file::TYPE_GIF,
        inspected_file::TYPE_WEBP];

    /** Video extensions and the file types each may really be. */
    public const VIDEO = ['mp4' => [inspected_file::TYPE_MP4], 'm4v' => [inspected_file::TYPE_MP4],
        'mov' => [inspected_file::TYPE_MP4], 'webm' => [inspected_file::TYPE_WEBM]];

    /** Sound extensions and the file types each may really be. */
    public const AUDIO = ['mp3' => [inspected_file::TYPE_MP3], 'm4a' => [inspected_file::TYPE_MP4],
        'wav' => [inspected_file::TYPE_WAV], 'ogg' => [inspected_file::TYPE_OGG], 'oga' => [inspected_file::TYPE_OGG],
        'opus' => [inspected_file::TYPE_OGG]];

    /** Word documents the browser renderer draws. */
    public const DOCX_EXTENSIONS = ['docx', 'docm', 'dotx', 'dotm'];

    /** Biggest file the plugin's own renderers take. */
    public const MAX_BUNDLED_BYTES = 20971520;

    /**
     * Where a file's preview stands, starting the conversion if it is not already under way.
     *
     * @param \stored_file $file
     * @param bool $start Queue a conversion when none exists.
     * @return array ['status' => a status constant, 'kind' => a KIND_ constant or null,
     *     'textonly' => whether the preview shows the text without layout]
     */
    public static function get_status(\stored_file $file, bool $start = true): array {
        $ext = \core_text::strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION));
        $mimetype = $file->get_mimetype();
        $type = self::sniff($file);

        if ($ext === 'pdf' && $mimetype === 'application/pdf' && $type === inspected_file::TYPE_PDF) {
            return self::status(self::READY, self::KIND_PDF);
        }
        if (in_array($type, self::IMAGE_TYPES, true)) {
            return self::status(self::READY, self::KIND_IMAGE);
        }
        if (in_array($type, self::VIDEO[$ext] ?? [], true)) {
            return self::status(self::READY, self::KIND_VIDEO);
        }
        if (in_array($type, self::AUDIO[$ext] ?? [], true)) {
            return self::status(self::READY, self::KIND_AUDIO);
        }
        if (in_array($ext, self::DOCUMENT_EXTENSIONS, true)) {
            $converted = self::converter_status($file, $start);
            if ($converted !== null) {
                return $converted;
            }
        }
        return self::bundled_status($file, $ext, $type) ?? self::status(
            in_array($ext, self::DOCUMENT_EXTENSIONS, true) ? self::UNAVAILABLE : self::UNSUPPORTED
        );
    }

    /**
     * The site converter's preview, or null when it has none to give (not set up, down or failed).
     *
     * @param \stored_file $file
     * @param bool $start
     * @return array|null
     */
    protected static function converter_status(\stored_file $file, bool $start): ?array {
        if (testing::converter_down()) {
            return null;
        }
        if (testing::use_test_converter()) {
            return self::test_preview($file) ? self::status(self::READY, self::KIND_PDF) : null;
        }
        $converter = new converter();
        if (!$converter->can_convert_storedfile_to($file, 'pdf')) {
            return null;
        }
        $conversions = conversion::get_conversions_for_file($file, 'pdf');
        $conversion = $conversions ? reset($conversions) : null;
        if ($conversion && $conversion->get('status') == conversion::STATUS_COMPLETE && $conversion->get_destfile()) {
            return self::status(self::READY, self::KIND_PDF);
        }
        if ($conversion && $conversion->get('status') == conversion::STATUS_FAILED) {
            return null;
        }
        if ($start && !$conversion) {
            self::queue($file);
        }
        return self::status(self::PENDING);
    }

    /**
     * The plugin's own renderer for a file, or null when it has none.
     *
     * @param \stored_file $file
     * @param string $ext
     * @param string $type What the file's first bytes say it is.
     * @return array|null
     */
    protected static function bundled_status(\stored_file $file, string $ext, string $type): ?array {
        if (in_array($ext, self::DOCX_EXTENSIONS, true) && $type === inspected_file::TYPE_ZIP) {
            $kind = self::KIND_DOCX;
            $max = self::MAX_BUNDLED_BYTES;
            $textonly = false;
        } else if (($htmlkind = html_preview::kind_for($ext)) !== null) {
            $kind = self::KIND_HTML;
            $max = html_preview::max_bytes($htmlkind);
            $textonly = $htmlkind === html_preview::OUTLINE;
        } else {
            return null;
        }
        if ($file->get_filesize() > $max) {
            return self::status(self::TOO_LARGE);
        }
        return self::status(self::READY, $kind, $textonly);
    }

    /**
     * A status array.
     *
     * @param string $status
     * @param string|null $kind
     * @param bool $textonly
     * @return array
     */
    protected static function status(string $status, ?string $kind = null, bool $textonly = false): array {
        return ['status' => $status, 'kind' => $kind, 'textonly' => $textonly];
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
        $ext = \core_text::strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION));
        if ($status['kind'] !== self::KIND_PDF || $ext === 'pdf') {
            // Shown as it is, or rendered from it.
            return $file;
        }
        if (testing::use_test_converter() && !testing::converter_down()) {
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
            $head = $handle ? (string) fread($handle, 2048) : '';
            if ($handle) {
                fclose($handle);
            }
            $cache[$key] = inspected_file::type_from_head($head);
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
