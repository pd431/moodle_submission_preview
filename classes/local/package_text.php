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

/**
 * The text of a Word, PowerPoint or OpenDocument package, paragraph by paragraph, per slide where there are slides.
 *
 * @package    local_submissioncheck
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class package_text {
    /** @var string Kind of package: a Word document. */
    public const KIND_WORD = 'word';
    /** @var string Kind of package: a PowerPoint presentation. */
    public const KIND_SLIDES = 'slides';
    /** @var string Kind of package: an OpenDocument text, presentation or spreadsheet. */
    public const KIND_ODF = 'odf';

    /**
     * Which kind of package this reads the file as, or null when it reads none.
     *
     * @param inspected_file $file
     * @return string|null
     */
    public static function kind(inspected_file $file): ?string {
        if ($file->get_type() === inspected_file::TYPE_ODF) {
            return $file->read_entry('content.xml', 1) !== null ? self::KIND_ODF : null;
        }
        if ($file->get_type() !== inspected_file::TYPE_OOXML) {
            return null;
        }
        if ($file->read_entry('word/document.xml', 1) !== null) {
            return self::KIND_WORD;
        }
        if (self::slide_parts($file)) {
            return self::KIND_SLIDES;
        }
        return null;
    }

    /**
     * The text, in sections: one for a document, one per slide or presentation page.
     *
     * @param inspected_file $file
     * @param int $maxsections Stop after this many sections.
     * @return array|null [['slide' => int|null, 'paragraphs' => string[]], ...], or null when the file is not a package
     *     this reads, or its content could not be read.
     */
    public static function sections(inspected_file $file, int $maxsections): ?array {
        switch (self::kind($file)) {
            case self::KIND_WORD:
                $doc = self::load($file->read_entry('word/document.xml'));
                return $doc ? [['slide' => null, 'paragraphs' => self::paragraphs($doc->documentElement, false)]]
                    : null;
            case self::KIND_SLIDES:
                $sections = [];
                foreach (array_slice(self::slide_parts($file), 0, $maxsections) as $number => $part) {
                    $doc = self::load($file->read_entry($part));
                    $sections[] = ['slide' => $number + 1,
                        'paragraphs' => $doc ? self::paragraphs($doc->documentElement, false) : []];
                }
                return $sections;
            case self::KIND_ODF:
                $doc = self::load($file->read_entry('content.xml'));
                if (!$doc) {
                    return null;
                }
                $pages = [];
                foreach (self::descendants($doc->documentElement) as $element) {
                    if ($element->localName === 'page') {
                        $pages[] = $element;
                    }
                }
                if (!$pages) {
                    return [['slide' => null, 'paragraphs' => self::paragraphs($doc->documentElement, true)]];
                }
                $sections = [];
                foreach ($pages as $number => $page) {
                    if ($number >= $maxsections) {
                        break;
                    }
                    $sections[] = ['slide' => $number + 1, 'paragraphs' => self::paragraphs($page, true)];
                }
                return $sections;
        }
        return null;
    }

    /**
     * Slide parts in slide order (slide2 before slide10).
     *
     * @param inspected_file $file
     * @return string[]
     */
    protected static function slide_parts(inspected_file $file): array {
        $parts = $file->find_entries('#^ppt/slides/slide\d+\.xml$#');
        usort($parts, function ($a, $b) {
            return (int) preg_replace('/\D/', '', $a) <=> (int) preg_replace('/\D/', '', $b);
        });
        return $parts;
    }

    /**
     * Parse one XML part without touching the network or expanding entities.
     *
     * @param string|null $xml
     * @return \DOMDocument|null
     */
    protected static function load(?string $xml): ?\DOMDocument {
        if ($xml === null || $xml === '') {
            return null;
        }
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $ok = $doc->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return $ok && $doc->documentElement ? $doc : null;
    }

    /**
     * Non-empty paragraphs under a node, in document order. A paragraph inside another (a text box) is its own entry.
     * Elements are matched by local name, as the parts' prefixes and namespaces vary between producers.
     *
     * @param \DOMNode $root
     * @param bool $odf OpenDocument (text in the paragraph and its spans, headings are "h") rather than Office (text in
     *     "t" elements).
     * @return string[]
     */
    protected static function paragraphs(\DOMNode $root, bool $odf): array {
        $out = [];
        foreach (self::descendants($root) as $node) {
            if (self::is_paragraph($node, $odf)) {
                $text = trim(preg_replace('/[ \x{00A0}]+/u', ' ', self::text_of($node, $odf)));
                if ($text !== '') {
                    $out[] = $text;
                }
            }
        }
        return $out;
    }

    /**
     * Every element under a node, in document order, leaving out alternative content kept for older readers.
     *
     * @param \DOMNode $root
     * @return \Generator
     */
    protected static function descendants(\DOMNode $root): \Generator {
        foreach ($root->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->localName !== 'Fallback') {
                yield $child;
                yield from self::descendants($child);
            }
        }
    }

    /**
     * Whether an element is a paragraph (or an OpenDocument heading).
     *
     * @param \DOMNode $node
     * @param bool $odf
     * @return bool
     */
    protected static function is_paragraph(\DOMNode $node, bool $odf): bool {
        return $node instanceof \DOMElement && ($node->localName === 'p' || ($odf && $node->localName === 'h'));
    }

    /**
     * The text of one paragraph, leaving out paragraphs nested in it.
     *
     * @param \DOMNode $node
     * @param bool $odf
     * @return string
     */
    protected static function text_of(\DOMNode $node, bool $odf): string {
        $text = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText) {
                if ($odf || $node->localName === 't') {
                    $text .= $child->data;
                }
            } else if ($child instanceof \DOMElement && $child->localName !== 'Fallback' && !self::is_paragraph($child, $odf)) {
                switch ($child->localName) {
                    case 'tab':
                    case 's':
                        $text .= ' ';
                        break;
                    case 'br':
                    case 'cr':
                    case 'line-break':
                        $text .= "\n";
                        break;
                    default:
                        $text .= self::text_of($child, $odf);
                }
            }
        }
        return $text;
    }
}
