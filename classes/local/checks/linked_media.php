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
        return 2;
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
        $found = self::locate($file);
        if (!$found) {
            return [];
        }
        $names = array_values(array_unique(array_column($found, 'name')));
        $locations = [];
        foreach ($found as $item) {
            if ($item['slide'] !== null && !isset($locations[$item['name']])) {
                $locations[$item['name']] = $item['slide'];
            }
        }
        return [new finding('linkedmedia', ['name' => $file->get_filename(), 'slides' => $locations], $names)];
    }

    /**
     * Every linked item in the file, with the slide or presentation page it sits on when that is known.
     *
     * @param inspected_file $file
     * @return array [['name' => string, 'slide' => int|null], ...] in document order, a name once per place.
     */
    public static function locate(inspected_file $file): array {
        $self = new self();
        if ($file->get_type() === inspected_file::TYPE_OOXML) {
            return $self->ooxml_links($file);
        }
        if ($file->get_type() === inspected_file::TYPE_ODF) {
            return $self->odf_links($file);
        }
        return [];
    }

    /**
     * Local files linked from an Office package, with the slide each sits on when it is in a slide.
     *
     * @param inspected_file $file
     * @return array [['name' => string, 'slide' => int|null], ...]
     */
    protected function ooxml_links(inspected_file $file): array {
        $names = [];
        $parts = array_slice($file->find_entries('#(^|/)_rels/[^/]+\.rels$#'), 0, self::MAX_PARTS);
        // Slides in slide order (slide2 before slide10), so items come in the order a reader meets them.
        natsort($parts);
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
                    $slide = preg_match('#^ppt/slides/_rels/slide(\d+)\.xml\.rels$#', $part, $m) ? (int) $m[1] : null;
                    $item = ['name' => self::basename($target), 'slide' => $slide];
                    if (!in_array($item, $names, true)) {
                        $names[] = $item;
                    }
                }
            }
        }
        return $names;
    }

    /**
     * Local files linked from an OpenDocument package, with the presentation page each sits on.
     *
     * @param inspected_file $file
     * @return array [['name' => string, 'slide' => int|null], ...]
     */
    protected function odf_links(inspected_file $file): array {
        $names = [];
        foreach (['content.xml', 'styles.xml'] as $part) {
            $xml = $file->read_entry($part);
            if ($xml === null) {
                continue;
            }
            foreach ($this->elements($xml, self::ODF_CONTENT_ELEMENTS) as $attrs) {
                $href = (string) ($attrs['xlink:href'] ?? '');
                if ($href !== '' && self::is_local($href) && !self::is_inside_odf_package($href)) {
                    $item = ['name' => self::basename($href), 'slide' => $part === 'content.xml' ? $attrs['#page'] : null];
                    if (!in_array($item, $names, true)) {
                        $names[] = $item;
                    }
                }
            }
        }
        return $names;
    }

    /**
     * Attributes of every element with one of the given names, read without resolving entities or the network. Each
     * also has '#page': the number of the presentation page (draw:page) it is in, or null.
     *
     * @param string $xml
     * @param string|string[] $names Qualified element names.
     * @return array[]
     */
    protected function elements(string $xml, $names): array {
        $names = (array) $names;
        $found = [];
        $page = null;
        $pages = 0;
        $reader = new \XMLReader();
        if (!@$reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT)) {
            return [];
        }
        while (@$reader->read()) {
            if ($reader->nodeType === \XMLReader::ELEMENT && $reader->name === 'draw:page') {
                $page = ++$pages;
            } else if ($reader->nodeType === \XMLReader::END_ELEMENT && $reader->name === 'draw:page') {
                $page = null;
            }
            if ($reader->nodeType === \XMLReader::ELEMENT && in_array($reader->name, $names, true)) {
                $attrs = ['#page' => $page];
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
