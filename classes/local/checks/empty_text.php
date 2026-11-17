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
 * Whether a document has any text in it, such as a blank template saved by mistake.
 *
 * @package    local_submissioncheck
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class empty_text extends check {
    /** Most slide or sheet parts read. */
    public const MAX_PARTS = 1000;

    /**
     * Name of this check, stored with its results.
     *
     * @return string
     */
    public function get_name(): string {
        return 'empty';
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
        return in_array(
            $file->get_type(),
            [inspected_file::TYPE_OOXML, inspected_file::TYPE_ODF, inspected_file::TYPE_TEXT],
            true
        ) && $file->is_inspectable();
    }

    /**
     * Run the check.
     *
     * @param inspected_file $file
     * @return finding[]
     */
    public function run(inspected_file $file): array {
        $count = $this->count_content($file);
        return $count === 0 ? [new finding('notext', ['name' => $file->get_filename()])] : [];
    }

    /**
     * Words (or spreadsheet values) found, or null when the kind of package is not one this reads.
     *
     * @param inspected_file $file
     * @return int|null
     */
    protected function count_content(inspected_file $file): ?int {
        switch ($file->get_type()) {
            case inspected_file::TYPE_TEXT:
                return self::count_words($file->read_all());
            case inspected_file::TYPE_ODF:
                $xml = $file->read_entry('content.xml');
                if ($xml === null) {
                    return null;
                }
                $body = preg_match('#<office:body>(.*)</office:body>#s', $xml, $m) ? $m[1] : $xml;
                $body = preg_replace('#<(text:s|text:tab|text:line-break)[^>]*/>#', ' ', $body);
                $body = preg_replace('#</text:(p|h)>#', ' ', $body);
                return self::count_words(strip_tags($body));
            case inspected_file::TYPE_OOXML:
                if ($file->read_entry('word/document.xml', 1) !== null) {
                    return $this->count_tagged($file, ['word/document.xml'], 'w:t');
                }
                if ($file->find_entries('#^ppt/slides/slide\d+\.xml$#')) {
                    return $this->count_tagged($file, $file->find_entries('#^ppt/slides/slide\d+\.xml$#'), 'a:t');
                }
                if ($file->find_entries('#^xl/worksheets/[^/]+\.xml$#')) {
                    foreach (array_slice($file->find_entries('#^xl/worksheets/[^/]+\.xml$#'), 0, self::MAX_PARTS) as $sheet) {
                        $xml = (string) $file->read_entry($sheet);
                        if (preg_match('#<v>[^<]+</v>|<is>#', $xml)) {
                            return 1;
                        }
                    }
                    return 0;
                }
                return null;
        }
        return null;
    }

    /**
     * Words inside one kind of text element across parts.
     *
     * @param inspected_file $file
     * @param string[] $parts
     * @param string $tag
     * @return int
     */
    protected function count_tagged(inspected_file $file, array $parts, string $tag): int {
        $total = 0;
        foreach (array_slice($parts, 0, self::MAX_PARTS) as $part) {
            $xml = (string) $file->read_entry($part);
            if (
                preg_match_all(
                    '#<' . preg_quote($tag, '#') . '(?:\s[^>]*)?>([^<]*)</' . preg_quote($tag, '#') . '>#',
                    $xml,
                    $matches
                )
            ) {
                $total += self::count_words(implode(' ', $matches[1]));
                if ($total > 0) {
                    return $total;
                }
            }
        }
        return $total;
    }

    /**
     * Count words in plain text.
     *
     * @param string $text
     * @return int
     */
    public static function count_words(string $text): int {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $words = preg_split('/[\s\x{00A0}]+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        return $words === false ? 0 : count($words);
    }
}
