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
 * Media and objects the file points to on the student's computer instead of containing.
 *
 * A video "inserted" into PowerPoint as a link, a picture linked into Word, or a chart linked to a
 * workbook all show on the student's machine and break on a marker's. Office files record these
 * as external relationships; OpenDocument files as links outside the package.
 *
 * @package    local_submissioncheck
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class linked_media extends check {
    /** Relationship types (last path segment) that carry content the reader is meant to see. */
    public const CONTENT_RELATIONSHIPS = ['image', 'video', 'audio', 'media', 'oleobject', 'package', 'subdocument',
        'externallinkpath', 'chart', 'diagramdata'];

    /** ODF elements whose link is content rather than a hyperlink. */
    public const ODF_CONTENT_ELEMENTS = ['draw:image', 'draw:plugin', 'draw:object', 'draw:object-ole', 'text:section-source',
        'table:table-source'];

    /** Most relationship parts read from one package. */
    public const MAX_PARTS = 2000;

    /**
     * Name of this check, stored with its results.
     *
     * @return string
     */
    public function get_name(): string {
        return 'linkedmedia';
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
        return in_array($file->get_type(), [inspected_file::TYPE_OOXML, inspected_file::TYPE_ODF], true)
            && $file->is_inspectable();
    }

    /**
     * Run the check.
     *
     * @param inspected_file $file
     * @return finding[]
     */
    public function run(inspected_file $file): array {
        $names = $file->get_type() === inspected_file::TYPE_OOXML ? $this->ooxml_links($file) : $this->odf_links($file);
        $names = array_values(array_unique($names));
        return $names ? [new finding('linkedmedia', ['name' => $file->get_filename()], $names)] : [];
    }

    /**
     * Local files linked from an Office package.
     *
     * @param inspected_file $file
     * @return string[]
     */
    protected function ooxml_links(inspected_file $file): array {
        $names = [];
        $parts = array_slice($file->find_entries('#(^|/)_rels/[^/]+\.rels$#'), 0, self::MAX_PARTS);
        foreach ($parts as $part) {
            $xml = $file->read_entry($part);
            if ($xml === null || stripos($xml, 'External') === false) {
                continue;
            }
            foreach ($this->elements($xml, 'Relationship') as $attrs) {
                if (($attrs['TargetMode'] ?? '') !== 'External') {
                    continue;
                }
                $type = \core_text::strtolower((string) substr(strrchr($attrs['Type'] ?? '', '/') ?: '', 1));
                $target = (string) ($attrs['Target'] ?? '');
                if (in_array($type, self::CONTENT_RELATIONSHIPS, true) && self::is_local($target)) {
                    $names[] = self::basename($target);
                }
            }
        }
        return $names;
    }

    /**
     * Local files linked from an OpenDocument package.
     *
     * @param inspected_file $file
     * @return string[]
     */
    protected function odf_links(inspected_file $file): array {
        $names = [];
        foreach (['content.xml', 'styles.xml'] as $part) {
            $xml = $file->read_entry($part);
            if ($xml === null) {
                continue;
            }
            foreach (self::ODF_CONTENT_ELEMENTS as $element) {
                foreach ($this->elements($xml, $element) as $attrs) {
                    $href = (string) ($attrs['xlink:href'] ?? '');
                    if ($href !== '' && self::is_local($href) && !self::is_inside_odf_package($href)) {
                        $names[] = self::basename($href);
                    }
                }
            }
        }
        return $names;
    }

    /**
     * Attributes of every element with a given name, read without resolving entities or the network.
     *
     * @param string $xml
     * @param string $name Qualified element name.
     * @return array[]
     */
    protected function elements(string $xml, string $name): array {
        $found = [];
        $reader = new \XMLReader();
        if (!@$reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT)) {
            return [];
        }
        while (@$reader->read()) {
            if ($reader->nodeType === \XMLReader::ELEMENT && $reader->name === $name) {
                $attrs = [];
                if ($reader->hasAttributes) {
                    while ($reader->moveToNextAttribute()) {
                        $attrs[$reader->name] = $reader->value;
                    }
                    $reader->moveToElement();
                }
                $found[] = $attrs;
            }
        }
        $reader->close();
        return $found;
    }

    /**
     * Whether a link target is on the author's own machine or network rather than the web.
     *
     * @param string $target
     * @return bool
     */
    public static function is_local(string $target): bool {
        $target = trim($target);
        if ($target === '' || $target[0] === '#') {
            return false;
        }
        return !preg_match('#^(https?|ftp|mailto|tel|data):#i', $target);
    }

    /**
     * Whether an OpenDocument link points inside the package itself.
     *
     * @param string $href
     * @return bool
     */
    protected static function is_inside_odf_package(string $href): bool {
        if (preg_match('#^(file:|/|\\\\|[A-Za-z]:|\.\./)#', $href)) {
            return false;
        }
        return true;
    }

    /**
     * The file name at the end of a path or file URL.
     *
     * @param string $target
     * @return string
     */
    public static function basename(string $target): string {
        $path = rawurldecode(preg_replace('#^file:/*#i', '', trim($target)));
        $parts = preg_split('#[/\\\\]#', $path);
        $name = end($parts);
        return $name === '' || $name === false ? $path : $name;
    }
}
