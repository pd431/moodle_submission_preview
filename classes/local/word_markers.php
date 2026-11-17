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
 * Prepares a Word document for drawing in the browser: each picture, video or sound file it only links to becomes a
 * text marker at its place, which amd/src/preview.js replaces with the missing-item box. The student's file is not
 * changed; this works on a temporary copy.
 *
 * @package    local_submissioncheck
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class word_markers {
    /** Start of a marker; amd/src/preview.js looks for the same text. */
    public const START = '[[submissioncheck-missing|';
    /** End of a marker. */
    public const END = ']]';
    /** Parts of the document whose linked items are marked. */
    public const PARTS = ['word/document.xml'];
    /** English Metric Units per CSS pixel. */
    public const EMU_PER_PX = 9525;

    /**
     * A copy of the document with markers in place of linked items, or null when it has none (send it as it is).
     *
     * @param \stored_file $file
     * @return string|null Path of the temporary copy; the caller removes it.
     */
    public static function prepare(\stored_file $file): ?string {
        $path = $file->copy_content_to_temp('local_submissioncheck');
        if ($path === false) {
            return null;
        }
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            @unlink($path);
            return null;
        }
        $changed = false;
        foreach (self::PARTS as $part) {
            $xml = $zip->getFromName($part);
            $rels = $zip->getFromName(dirname($part) . '/_rels/' . basename($part) . '.rels');
            if ($xml === false || $rels === false) {
                continue;
            }
            $marked = self::mark($xml, self::linked_targets($rels));
            if ($marked !== $xml) {
                $zip->addFromString($part, $marked);
                $changed = true;
            }
        }
        $zip->close();
        if (!$changed) {
            @unlink($path);
            return null;
        }
        return $path;
    }

    /**
     * Relationship ids of linked local files, with the file name each points to.
     *
     * @param string $rels The part's relationships XML.
     * @return array id => file name
     */
    public static function linked_targets(string $rels): array {
        $targets = [];
        if (!preg_match_all('#<(?:\w+:)?Relationship\b([^>]*)/?>#', $rels, $matches)) {
            return [];
        }
        foreach ($matches[1] as $attributes) {
            preg_match_all('#(\w+)="([^"]*)"#', $attributes, $pairs, PREG_SET_ORDER);
            $attrs = [];
            foreach ($pairs as $pair) {
                $attrs[$pair[1]] = html_entity_decode($pair[2], ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
            $type = \core_text::strtolower((string) substr(strrchr($attrs['Type'] ?? '', '/') ?: '', 1));
            if (
                ($attrs['TargetMode'] ?? '') === 'External' && isset($attrs['Id'])
                    && in_array($type, linked_media::CONTENT_RELATIONSHIPS, true) && linked_media::is_local($attrs['Target'] ?? '')
            ) {
                $targets[$attrs['Id']] = linked_media::basename($attrs['Target']);
            }
        }
        return $targets;
    }

    /**
     * Replace each drawing or picture that uses a linked target with a marker run text.
     *
     * @param string $xml A document part.
     * @param array $targets id => file name
     * @return string
     */
    public static function mark(string $xml, array $targets): string {
        if (!$targets) {
            return $xml;
        }
        return preg_replace_callback('#<w:(drawing|pict|object)\b.*?</w:\1>#s', function ($m) use ($targets) {
            if (!preg_match_all('#\br:(?:link|embed|id)="([^"]+)"#', $m[0], $ids)) {
                return $m[0];
            }
            foreach ($ids[1] as $id) {
                if (isset($targets[$id])) {
                    [$width, $height] = self::size($m[0]);
                    $name = str_replace(['|', ']'], '', $targets[$id]);
                    $marker = self::START . $name . '|' . $width . '|' . $height . self::END;
                    return '<w:t xml:space="preserve">' . htmlspecialchars($marker, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</w:t>';
                }
            }
            return $m[0];
        }, $xml);
    }

    /**
     * Width and height of a drawing in CSS pixels, 0 when not stated.
     *
     * @param string $drawing
     * @return int[]
     */
    protected static function size(string $drawing): array {
        if (preg_match('#<wp:extent\b[^>]*\bcx="(\d+)"[^>]*\bcy="(\d+)"#', $drawing, $m)) {
            return [(int) round($m[1] / self::EMU_PER_PX), (int) round($m[2] / self::EMU_PER_PX)];
        }
        return [0, 0];
    }
}
