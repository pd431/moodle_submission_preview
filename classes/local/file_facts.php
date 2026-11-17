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

/**
 * Facts about a submitted file read from the file itself, the way a marker will receive it: what kind of file it is,
 * pages, words, slides, sheets, formulas, charts, pictures embedded and linked, and size.
 *
 * A fact is only given when the file states it. Words are counted from the text; pages are the count the program that
 * saved the file stored (only it lays out pages). Nothing here needs a document converter.
 *
 * @package    local_submissioncheck
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class file_facts {
    /** Sheet names listed before "and N more". */
    public const MAX_SHEET_NAMES = 10;
    /** Parts read when counting formulas. */
    public const MAX_PARTS = 50;
    /** Biggest PDF whose pages are counted. */
    public const MAX_PDF_BYTES = 33554432;

    /**
     * The facts for a stored file, cached by content and extension.
     *
     * @param \stored_file $file
     * @return array [['label' => string, 'value' => string], ...]
     */
    public static function get(\stored_file $file): array {
        $cache = \cache::make('local_submissioncheck', 'facts');
        $key = sha1($file->get_contenthash() . '/' . \core_text::strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION))
            . '/' . current_language());
        $facts = $cache->get($key);
        if ($facts === false) {
            try {
                $facts = self::read(inspected_file::from_stored_file($file));
            } catch (\Throwable $e) {
                $facts = [self::fact('factsize', display_size($file->get_filesize()))];
            }
            $cache->set($key, $facts);
        }
        return $facts;
    }

    /**
     * Read the facts from an opened file.
     *
     * @param inspected_file $file
     * @return array
     */
    public static function read(inspected_file $file): array {
        $facts = [];
        $kind = self::kind($file);
        if ($kind !== null) {
            $facts[] = self::fact('factkind', get_string('factkind_' . $kind, 'local_submissioncheck'));
        }
        switch ($kind) {
            case 'word':
            case 'odt':
                $facts = array_merge($facts, self::document($file, $kind));
                break;
            case 'powerpoint':
            case 'odp':
                $facts = array_merge($facts, self::slides($file, $kind));
                break;
            case 'excel':
            case 'ods':
                $facts = array_merge($facts, self::workbook($file, $kind));
                break;
            case 'pdf':
                $pages = self::pdf_pages($file);
                if ($pages) {
                    $facts[] = self::fact('factpages', (string) $pages);
                }
                break;
            case 'image':
                $info = @getimagesize($file->get_path());
                if ($info) {
                    $facts[] = self::fact('factdimensions', get_string(
                        'factpixels',
                        'local_submissioncheck',
                        (object) ['width' => $info[0], 'height' => $info[1]]
                    ));
                }
                break;
            case 'text':
                $facts[] = self::fact('factwords', self::number(checks\empty_text::count_words($file->read_all())));
                break;
        }
        $saved = self::saved($file, $kind);
        if ($saved) {
            $facts[] = self::fact('factsaved', userdate($saved, get_string('strftimedatetimeshort', 'langconfig')));
        }
        $facts[] = self::fact('factsize', display_size($file->get_size()));
        return $facts;
    }

    /**
     * What kind of file this is, as a key of the factkind_ strings.
     *
     * @param inspected_file $file
     * @return string|null
     */
    protected static function kind(inspected_file $file): ?string {
        switch ($file->get_type()) {
            case inspected_file::TYPE_OOXML:
                if ($file->read_entry('word/document.xml', 1) !== null) {
                    return 'word';
                }
                if ($file->find_entries('#^ppt/slides/slide\d+\.xml$#')) {
                    return 'powerpoint';
                }
                return $file->read_entry('xl/workbook.xml', 1) !== null ? 'excel' : null;
            case inspected_file::TYPE_ODF:
                $mimetype = (string) $file->read_entry('mimetype', 200);
                foreach (['text' => 'odt', 'presentation' => 'odp', 'spreadsheet' => 'ods'] as $part => $kind) {
                    if (strpos($mimetype, 'opendocument.' . $part) !== false) {
                        return $kind;
                    }
                }
                return null;
            case inspected_file::TYPE_PDF:
                return 'pdf';
            case inspected_file::TYPE_JPEG:
            case inspected_file::TYPE_PNG:
            case inspected_file::TYPE_GIF:
            case inspected_file::TYPE_WEBP:
                return 'image';
            case inspected_file::TYPE_MP4:
            case inspected_file::TYPE_WEBM:
                return in_array($file->get_extension(), ['m4a'], true) ? 'audio' : 'video';
            case inspected_file::TYPE_MP3:
            case inspected_file::TYPE_WAV:
            case inspected_file::TYPE_OGG:
                return 'audio';
            case inspected_file::TYPE_TEXT:
                return 'text';
            case inspected_file::TYPE_HTML:
                return 'html';
            case inspected_file::TYPE_RTF:
                return 'rtf';
            case inspected_file::TYPE_OLE:
                return 'oldoffice';
        }
        return null;
    }

    /**
     * Pages, words and pictures of a Word or OpenDocument text.
     *
     * @param inspected_file $file
     * @param string $kind
     * @return array
     */
    protected static function document(inspected_file $file, string $kind): array {
        $facts = [];
        $pages = $kind === 'word'
            ? self::tag_value((string) $file->read_entry('docProps/app.xml'), 'Pages')
            : self::attribute_value((string) $file->read_entry('meta.xml'), 'meta:page-count');
        if ($pages) {
            $facts[] = self::fact('factpages', self::number($pages));
        }
        $sections = package_text::sections($file, 1);
        if ($sections !== null) {
            $words = 0;
            foreach ($sections as $section) {
                $words += checks\empty_text::count_words(implode(' ', $section['paragraphs']));
            }
            $facts[] = self::fact('factwords', self::number($words));
        }
        $pictures = self::pictures($file, $kind === 'word' ? '#^word/media/#' : '#^Pictures/#');
        return $pictures ? array_merge($facts, [$pictures]) : $facts;
    }

    /**
     * Slides, speaker notes and pictures of a presentation.
     *
     * @param inspected_file $file
     * @param string $kind
     * @return array
     */
    protected static function slides(inspected_file $file, string $kind): array {
        $facts = [];
        if ($kind === 'powerpoint') {
            $slides = count($file->find_entries('#^ppt/slides/slide\d+\.xml$#'));
            $notes = count($file->find_entries('#^ppt/notesSlides/notesSlide\d+\.xml$#'));
        } else {
            $sections = package_text::sections($file, PHP_INT_MAX);
            $slides = $sections === null ? 0 : count($sections);
            $notes = 0;
        }
        if ($slides) {
            $facts[] = self::fact('factslides', self::number($slides));
        }
        if ($notes) {
            $facts[] = self::fact('factnotes', self::plural('factnoteson', $notes));
        }
        $pictures = self::pictures($file, $kind === 'powerpoint' ? '#^ppt/media/#' : '#^Pictures/#');
        return $pictures ? array_merge($facts, [$pictures]) : $facts;
    }

    /**
     * Sheet names, formulas and charts of a workbook.
     *
     * @param inspected_file $file
     * @param string $kind
     * @return array
     */
    protected static function workbook(inspected_file $file, string $kind): array {
        $facts = [];
        if ($kind === 'excel') {
            $workbook = (string) $file->read_entry('xl/workbook.xml');
            preg_match_all('#<(?:\w+:)?sheet\b[^>]*\sname="([^"]*)"#', $workbook, $m);
            $names = $m[1];
            $formulas = 0;
            foreach (array_slice($file->find_entries('#^xl/worksheets/[^/]+\.xml$#'), 0, self::MAX_PARTS) as $part) {
                $formulas += preg_match_all('#<(?:\w+:)?f[\s>/]#', (string) $file->read_entry($part));
            }
            $charts = count($file->find_entries('#^xl/charts/chart\d+\.xml$#'));
        } else {
            $content = (string) $file->read_entry('content.xml');
            preg_match_all('#<table:table\b[^>]*\stable:name="([^"]*)"#', $content, $m);
            $names = $m[1];
            $formulas = preg_match_all('#\stable:formula="#', $content);
            $charts = preg_match_all('#<chart:chart\b#', $content) + count($file->find_entries('#^Object \d+/content\.xml$#'));
        }
        if ($names) {
            $names = array_map(function ($name) {
                return html_entity_decode($name, ENT_QUOTES | ENT_XML1, 'UTF-8');
            }, $names);
            $shown = implode(', ', array_slice($names, 0, self::MAX_SHEET_NAMES));
            if (count($names) > self::MAX_SHEET_NAMES) {
                $shown = get_string(
                    'factandmore',
                    'local_submissioncheck',
                    (object) ['list' => $shown, 'more' => count($names) - self::MAX_SHEET_NAMES]
                );
            }
            $facts[] = self::fact('factsheets', $shown);
        }
        $contains = [];
        if ($formulas) {
            $contains[] = self::plural('factformulas', $formulas);
        }
        if ($charts) {
            $contains[] = self::plural('factcharts', $charts);
        }
        if ($contains) {
            $facts[] = self::fact('factcontains', implode(', ', $contains));
        }
        $pictures = self::pictures($file, $kind === 'excel' ? '#^xl/media/#' : '#^Pictures/#');
        return $pictures ? array_merge($facts, [$pictures]) : $facts;
    }

    /**
     * Pictures and media embedded in a package, and linked ones the marker will not get.
     *
     * @param inspected_file $file
     * @param string $mediaregex Entries holding embedded media.
     * @return array|null
     */
    protected static function pictures(inspected_file $file, string $mediaregex): ?array {
        $embedded = count(array_filter($file->find_entries($mediaregex), function ($name) {
            return substr($name, -1) !== '/';
        }));
        $linked = count(array_unique(array_column(linked_media::locate($file), 'name')));
        $parts = [];
        if ($embedded) {
            $parts[] = get_string('factembedded', 'local_submissioncheck', self::number($embedded));
        }
        if ($linked) {
            $parts[] = get_string('factlinked', 'local_submissioncheck', self::number($linked));
        }
        return $parts ? self::fact('factpictures', implode(', ', $parts)) : null;
    }

    /**
     * Pages of a PDF, counted from its page objects.
     *
     * @param inspected_file $file
     * @return int
     */
    protected static function pdf_pages(inspected_file $file): int {
        if ($file->get_size() > self::MAX_PDF_BYTES) {
            return 0;
        }
        return (int) preg_match_all('#/Type\s*/Page(?![a-zA-Z])#', $file->read_all(self::MAX_PDF_BYTES));
    }

    /**
     * When the file says it was last saved.
     *
     * @param inspected_file $file
     * @param string|null $kind
     * @return int|null
     */
    protected static function saved(inspected_file $file, ?string $kind): ?int {
        if (in_array($kind, ['word', 'powerpoint', 'excel'], true)) {
            $value = self::tag_value((string) $file->read_entry('docProps/core.xml'), 'dcterms:modified', false);
        } else if (in_array($kind, ['odt', 'odp', 'ods'], true)) {
            $value = self::tag_value((string) $file->read_entry('meta.xml'), 'dc:date', false);
        } else {
            return null;
        }
        $time = $value ? strtotime($value) : false;
        return $time && $time > 0 ? $time : null;
    }

    /**
     * The text of the first element with a given name.
     *
     * @param string $xml
     * @param string $tag
     * @param bool $number Only a whole number counts.
     * @return string|null
     */
    protected static function tag_value(string $xml, string $tag, bool $number = true) {
        if (!preg_match('#<' . preg_quote($tag, '#') . '(?:\s[^>]*)?>([^<]*)</' . preg_quote($tag, '#') . '>#', $xml, $m)) {
            return null;
        }
        $value = trim($m[1]);
        if ($number) {
            return ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
        }
        return $value === '' ? null : $value;
    }

    /**
     * A whole-number attribute value.
     *
     * @param string $xml
     * @param string $attribute
     * @return int|null
     */
    protected static function attribute_value(string $xml, string $attribute): ?int {
        return preg_match('#\s' . preg_quote($attribute, '#') . '="(\d+)"#', $xml, $m) && (int) $m[1] > 0 ? (int) $m[1] : null;
    }

    /**
     * A fact.
     *
     * @param string $label String key of the label.
     * @param string $value
     * @return array
     */
    protected static function fact(string $label, string $value): array {
        return ['label' => get_string($label, 'local_submissioncheck'), 'value' => $value];
    }

    /**
     * A count with its noun, singular for one.
     *
     * @param string $key String key; key_one is the singular.
     * @param int $count
     * @return string
     */
    protected static function plural(string $key, int $count): string {
        return $count === 1 ? get_string($key . '_one', 'local_submissioncheck')
            : get_string($key, 'local_submissioncheck', self::number($count));
    }

    /**
     * A whole number for display.
     *
     * @param int $number
     * @return string
     */
    protected static function number(int $number): string {
        return format_float($number, 0, true);
    }
}
