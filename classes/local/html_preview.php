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

use local_submissioncheck\local\checks\linked_media;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Html;

/**
 * Previews made on the server as a standalone HTML page, without a document converter: spreadsheets as tables, text
 * files as text, web pages as they are, and the text of presentations and OpenDocument text.
 *
 * The page is sent with a sandbox Content-Security-Policy (see preview.php), so nothing in it can run scripts or load
 * anything from elsewhere.
 *
 * @package    local_submissioncheck
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class html_preview {
    /** @var string Shown as tables. */
    public const SHEET = 'sheet';
    /** @var string Shown as text. */
    public const TEXT = 'text';
    /** @var string A web page, shown as it is. */
    public const PAGE = 'page';
    /** @var string The text of a presentation or document, without layout. */
    public const OUTLINE = 'outline';

    /** Spreadsheet extensions and the PhpSpreadsheet reader for each. */
    public const SHEET_READERS = ['xlsx' => 'Xlsx', 'xlsm' => 'Xlsx', 'xls' => 'Xls', 'ods' => 'Ods', 'csv' => 'Csv'];

    /** Extensions of plain text files. */
    public const TEXT_EXTENSIONS = ['txt', 'text', 'md', 'markdown', 'tsv', 'json', 'xml', 'tex', 'bib', 'log', 'rmd', 'py',
        'r', 'm', 'java', 'c', 'h', 'cpp', 'hpp', 'cs', 'js', 'ts', 'css', 'sql', 'sh', 'yml', 'yaml'];

    /** Extensions of web pages. */
    public const PAGE_EXTENSIONS = ['html', 'htm'];

    /** Extensions whose text is shown without layout. */
    public const OUTLINE_EXTENSIONS = ['pptx', 'pptm', 'ppsx', 'odp', 'odt'];

    /** Sheets shown from one workbook. */
    public const MAX_SHEETS = 10;
    /** Rows shown from one sheet. */
    public const MAX_ROWS = 1000;
    /** Columns shown from one sheet. */
    public const MAX_COLUMNS = 50;
    /** Bytes of a text file shown. */
    public const MAX_TEXT_BYTES = 1048576;
    /** Slides or pages shown. */
    public const MAX_SECTIONS = 200;
    /** Biggest spreadsheet read; reading one takes far more memory than its size. */
    public const MAX_SHEET_BYTES = 10485760;

    /**
     * Which kind of HTML preview a file name gets, if any.
     *
     * @param string $extension Lower case.
     * @return string|null
     */
    public static function kind_for(string $extension): ?string {
        if (isset(self::SHEET_READERS[$extension]) && class_exists(IOFactory::class)) {
            return self::SHEET;
        }
        if (in_array($extension, self::TEXT_EXTENSIONS, true)) {
            return self::TEXT;
        }
        if (in_array($extension, self::PAGE_EXTENSIONS, true)) {
            return self::PAGE;
        }
        if (in_array($extension, self::OUTLINE_EXTENSIONS, true)) {
            return self::OUTLINE;
        }
        return null;
    }

    /**
     * The biggest file of a kind this renders.
     *
     * @param string $kind
     * @return int Bytes.
     */
    public static function max_bytes(string $kind): int {
        return $kind === self::SHEET ? self::MAX_SHEET_BYTES : preview::MAX_BUNDLED_BYTES;
    }

    /**
     * Render a file as a standalone HTML page.
     *
     * @param \stored_file $file
     * @return string|null Null when the file cannot be read as its kind.
     */
    public static function render(\stored_file $file): ?string {
        $extension = \core_text::strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION));
        $kind = self::kind_for($extension);
        if ($kind === null || $file->get_filesize() > self::max_bytes($kind)) {
            return null;
        }
        try {
            switch ($kind) {
                case self::PAGE:
                    return $file->get_content();
                case self::TEXT:
                    return self::page($file->get_filename(), self::text($file));
                case self::SHEET:
                    return self::sheet($file, self::SHEET_READERS[$extension]);
                case self::OUTLINE:
                    return self::outline($file);
            }
        } catch (\Throwable $e) {
            debugging('Submission checks could not preview ' . $file->get_filename() . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
        return null;
    }

    /**
     * A text file as preformatted text, shortened past the limit, read as UTF-8 or else Windows-1252.
     *
     * @param \stored_file $file
     * @return string HTML.
     */
    protected static function text(\stored_file $file): string {
        $handle = $file->get_content_file_handle();
        $text = $handle ? (string) fread($handle, self::MAX_TEXT_BYTES) : '';
        if ($handle) {
            fclose($handle);
        }
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
        if (!preg_match('//u', $text)) {
            // Shortening may have cut a character in two; otherwise it is another encoding.
            $whole = $file->get_filesize() <= self::MAX_TEXT_BYTES;
            $trimmed = $whole ? '' : preg_replace('/[\x80-\xFF]{1,3}$/', '', $text);
            $text = !$whole && preg_match('//u', $trimmed) ? $trimmed : \core_text::convert($text, 'windows-1252', 'utf-8');
        }
        $html = '<pre>' . s($text) . '</pre>';
        if ($file->get_filesize() > self::MAX_TEXT_BYTES) {
            $html .= '<p class="note">' . s(get_string('previewshortened', 'local_submissioncheck')) . '</p>';
        }
        return $html;
    }

    /**
     * A workbook as tables, one per sheet, with stored values rather than recalculated formulas.
     *
     * @param \stored_file $file
     * @param string $readertype
     * @return string|null
     */
    protected static function sheet(\stored_file $file, string $readertype): ?string {
        $path = $file->copy_content_to_temp('local_submissioncheck');
        if ($path === false) {
            return null;
        }
        try {
            $reader = IOFactory::createReader($readertype);
            if (method_exists($reader, 'setAllowExternalImages')) {
                // Keep formatting; never fetch pictures the workbook only links to.
                $reader->setAllowExternalImages(false);
                $reader->setReadDataOnly(false);
            } else {
                // Older PhpSpreadsheet (Moodle 4.5) cannot turn off linked pictures: read values only.
                $reader->setReadDataOnly(true);
            }
            if (method_exists($reader, 'setIncludeCharts')) {
                $reader->setIncludeCharts(false);
            }
            // phpcs:disable moodle.NamingConventions.ValidFunctionName.LowercaseMethod -- PhpSpreadsheet's interface.
            $reader->setReadFilter(new class implements IReadFilter {
                /**
                 * Only cells inside the shown rows and columns.
                 *
                 * @param string $columnaddress
                 * @param int $row
                 * @param string $worksheetname
                 * @return bool
                 */
                public function readCell($columnaddress, $row, $worksheetname = ''): bool {
                    return $row <= html_preview::MAX_ROWS
                        && \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($columnaddress)
                            <= html_preview::MAX_COLUMNS;
                }
            });
            // phpcs:enable moodle.NamingConventions.ValidFunctionName.LowercaseMethod
            if ($readertype !== 'Csv') {
                $reader->setLoadSheetsOnly(array_slice($reader->listWorksheetNames($path), 0, self::MAX_SHEETS));
            }
            $spreadsheet = $reader->load($path);
            self::freeze_values($spreadsheet);
            $writer = new Html($spreadsheet);
            $writer->writeAllSheets();
            $writer->setEmbedImages(true);
            $writer->setPreCalculateFormulas(false);
            $html = $writer->generateHtmlAll();
            $spreadsheet->disconnectWorksheets();
            // Links keep their text; following them is not part of a preview.
            return preg_replace('/\shref\s*=\s*(["\'])(?!#)[^"\']*\1/i', '', $html);
        } finally {
            @unlink($path);
        }
    }

    /**
     * Replace every formula with the value the spreadsheet program last stored for it, so nothing is calculated here
     * (some functions fetch web addresses).
     *
     * @param Spreadsheet $spreadsheet
     */
    protected static function freeze_values(Spreadsheet $spreadsheet): void {
        foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
            foreach ($sheet->getCellCollection()->getCoordinates() as $coordinate) {
                $cell = $sheet->getCell($coordinate);
                if ($cell->isFormula()) {
                    $stored = $cell->getOldCalculatedValue();
                    if ($stored === null || is_array($stored)) {
                        $cell->setValueExplicit((string) $cell->getValue(), DataType::TYPE_STRING);
                    } else if (is_numeric($stored) && !is_string($stored)) {
                        $cell->setValueExplicit($stored, DataType::TYPE_NUMERIC);
                    } else {
                        $cell->setValueExplicit((string) $stored, DataType::TYPE_STRING);
                    }
                }
            }
        }
    }

    /**
     * The text of a presentation or document, one heading per slide.
     *
     * @param \stored_file $file
     * @return string|null
     */
    protected static function outline(\stored_file $file): ?string {
        global $OUTPUT;
        $inspected = inspected_file::from_stored_file($file);
        $sections = package_text::sections($inspected, self::MAX_SECTIONS);
        if ($sections === null) {
            return null;
        }
        $missing = [];
        foreach (linked_media::locate($inspected) as $item) {
            $missing[$item['slide'] ?? 0][] = $item['name'];
        }
        $box = function (string $name) use ($OUTPUT): string {
            return $OUTPUT->render_from_template(
                'local_submissioncheck/missing_item',
                ['message' => get_string('missingitem', 'local_submissioncheck', $name)]
            );
        };
        $html = '';
        foreach ($sections as $section) {
            if ($section['slide'] !== null) {
                $html .= '<h2>' . s(get_string('previewslide', 'local_submissioncheck', $section['slide'])) . '</h2>';
            }
            foreach ($section['paragraphs'] as $paragraph) {
                $html .= '<p>' . nl2br(s($paragraph)) . '</p>';
            }
            if (!$section['paragraphs'] && empty($missing[$section['slide'] ?? 0])) {
                $html .= '<p class="note">' . s(get_string('previewnotext', 'local_submissioncheck')) . '</p>';
            }
            foreach ($missing[$section['slide'] ?? 0] ?? [] as $name) {
                $html .= $box($name);
            }
        }
        return self::page($file->get_filename(), $html);
    }

    /**
     * Wrap a body in a standalone page.
     *
     * @param string $title
     * @param string $body HTML.
     * @return string
     */
    protected static function page(string $title, string $body): string {
        return '<!DOCTYPE html><html lang="' . s(current_language()) . '"><head><meta charset="utf-8"><title>' . s($title) .
            '</title><style>body{font-family:system-ui,sans-serif;margin:1rem;line-height:1.5;color:#1d2125;background:#fff}' .
            'pre{white-space:pre-wrap;word-break:break-word;font-size:.875rem}h2{font-size:1rem;margin:1.5rem 0 .5rem;' .
            'padding-top:.5rem;border-top:1px solid #dee2e6}.note{color:#6a737b;font-style:italic}</style></head><body>' .
            $body . '</body></html>';
    }
}
