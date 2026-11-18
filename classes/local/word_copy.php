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
 * The temporary copy of a Word document that the browser draws: each picture, video or sound file it only links to becomes a
 * text marker at its place, which amd/src/preview.js replaces with the missing-item box. The student's file is not
 * changed; this works on a temporary copy.
 *
 * @package    local_submissioncheck
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class word_copy {
    /** Start of a linked-item marker; amd/src/preview.js looks for the same text. */
    public const START = '[[submissioncheck-missing|';
    /** Start of a marker for media on sign-in storage; amd/src/preview.js looks for the same text. */
    public const CLOUD = '[[submissioncheck-cloud|';
    /** Start of a marker for a drawing the browser cannot show; amd/src/preview.js looks for the same text. */
    public const NOT_SHOWN = '[[submissioncheck-notshown|';
    /** End of a marker. */
    public const END = ']]';
    /** Parts of the document that are prepared: the body, then headers and footers. */
    public const PARTS_REGEX = '#^word/(document|header\d*|footer\d*)\.xml$#';
    /** Picture graphic data. */
    public const PICTURE_URI = 'http://schemas.openxmlformats.org/drawingml/2006/picture';
    /** English Metric Units per CSS pixel. */
    public const EMU_PER_PX = 9525;

    /**
     * A copy of the document the browser can draw, or null when it needs no change (send it as it is): drawing groups
     * become the pictures in them, text in text boxes joins the text flow, drawings with neither get a "not shown"
     * marker, and linked items get a missing-item marker.
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
        $parts = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (preg_match(self::PARTS_REGEX, $name)) {
                $parts[] = $name;
            }
        }
        foreach ($parts as $part) {
            $xml = $zip->getFromName($part);
            if ($xml === false) {
                continue;
            }
            $rels = $zip->getFromName(dirname($part) . '/_rels/' . basename($part) . '.rels');
            $marked = self::mark(self::flatten($xml), $rels === false ? [] : self::linked_targets($rels));
            $marked = self::mark_cloud($marked, $rels === false ? [] : self::cloud_targets($rels));
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
     * Make every drawing one the browser renderer can draw: alternative content becomes Word's first choice, a group
     * becomes the pictures in it, text in text boxes joins the text flow at the box's place, and a drawing with no
     * picture and no text becomes a "not shown" marker at its size.
     *
     * @param string $xml A document part.
     * @return string
     */
    public static function flatten(string $xml): string {
        if (strpos($xml, '<w:drawing') === false) {
            return $xml;
        }
        $original = $xml;
        // Innermost alternative content first, so a block inside another is never cut in two (found by plain string
        // search: patterns run out of backtracking on long documents). Blocks whose first choice holds no drawing are
        // left for the renderer, their tags set aside under other names while this runs.
        $tags = ['AlternateContent', 'Choice', 'Fallback'];
        $kept = function (string $block) use ($tags): string {
            foreach ($tags as $tag) {
                $block = str_replace(
                    ['<mc:' . $tag . '>', '<mc:' . $tag . ' ', '</mc:' . $tag . '>'],
                    ['<mc:' . $tag . 'Kept>', '<mc:' . $tag . 'Kept ', '</mc:' . $tag . 'Kept>'],
                    $block
                );
            }
            return $block;
        };
        $open = '<mc:AlternateContent';
        $close = '</mc:AlternateContent>';
        while (($end = strpos($xml, $close)) !== false) {
            // The last opening tag before the first closing tag starts the innermost block.
            $before = substr($xml, 0, $end);
            $start = max((int) strrpos($before, $open . '>'), (int) strrpos($before, $open . ' '));
            if ($start === 0 && strpos($before, $open . '>') !== 0 && strpos($before, $open . ' ') !== 0) {
                return $original;
            }
            $block = substr($xml, $start, $end + strlen($close) - $start);
            $inner = substr($block, strpos($block, '>') + 1, -strlen($close));
            if (
                preg_match('#^\s*<mc:Choice\b[^>]*>(.*?)</mc:Choice>#s', $inner, $choice)
                    && strpos($choice[1], '<w:drawing') !== false
            ) {
                $replacement = $choice[1];
            } else {
                $replacement = $kept($block);
            }
            $xml = substr($xml, 0, $start) . $replacement . substr($xml, $end + strlen($close));
        }
        foreach ($tags as $tag) {
            $xml = str_replace(['<mc:' . $tag . 'Kept', '</mc:' . $tag . 'Kept>'], ['<mc:' . $tag, '</mc:' . $tag . '>'], $xml);
        }
        $flat = preg_replace_callback('#<w:drawing\b.*?</w:drawing>#s', function ($m) {
            $drawing = $m[0];
            if (strpos($drawing, 'uri="' . self::PICTURE_URI . '"') !== false && strpos($drawing, '<wpg:') === false) {
                return $drawing;
            }
            [$width, $height] = self::size($drawing);
            $out = '';
            if (preg_match_all('#<pic:pic\b.*?</pic:pic>#s', $drawing, $pictures)) {
                $scale = self::group_scale($drawing);
                foreach ($pictures[0] as $index => $picture) {
                    $out .= self::inline_picture($picture, $scale, $index);
                }
            }
            if (preg_match_all('#<w:txbxContent\b[^>]*>(.*?)</w:txbxContent>#s', $drawing, $boxes)) {
                foreach ($boxes[1] as $box) {
                    $out .= self::lifted_text($box);
                }
            }
            if ($out === '') {
                $marker = self::NOT_SHOWN . $width . '|' . $height . self::END;
                return '<w:t xml:space="preserve">' . $marker . '</w:t>';
            }
            return $out;
        }, $xml);
        return $flat ?? $original;
    }

    /**
     * How a group's child coordinates map to its size on the page.
     *
     * @param string $drawing
     * @return float[] [x, y]
     */
    protected static function group_scale(string $drawing): array {
        if (
            preg_match(
                '#<wpg:grpSpPr>.*?<a:ext cx="(\d+)" cy="(\d+)"\s*/>.*?<a:chExt cx="(\d+)" cy="(\d+)"\s*/>#s',
                $drawing,
                $m
            )
        ) {
            return [(int) $m[1] / max(1, (int) $m[3]), (int) $m[2] / max(1, (int) $m[4])];
        }
        return [1.0, 1.0];
    }

    /**
     * One picture from a group as an ordinary inline picture, at its size in the group.
     *
     * @param string $picture The pic:pic element.
     * @param float[] $scale
     * @param int $index
     * @return string
     */
    protected static function inline_picture(string $picture, array $scale, int $index): string {
        $cx = $cy = 0;
        if (preg_match('#<a:ext cx="(\d+)" cy="(\d+)"#', $picture, $m)) {
            $cx = (int) round($m[1] * $scale[0]);
            $cy = (int) round($m[2] * $scale[1]);
        }
        if (strpos($picture, 'xmlns:pic=') === false) {
            $picture = preg_replace('#^<pic:pic\b#', '<pic:pic xmlns:pic="' . self::PICTURE_URI . '"', $picture);
        }
        return '<w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0"><wp:extent cx="' . $cx . '" cy="' . $cy . '"/>' .
            '<wp:docPr id="' . (900000 + $index) . '" name="Picture"/>' .
            '<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><a:graphicData uri="' . self::PICTURE_URI .
            '">' . $picture . '</a:graphicData></a:graphic></wp:inline></w:drawing>';
    }

    /**
     * The text of a text box as runs in the surrounding paragraph, one line per paragraph of the box.
     *
     * @param string $box The inside of w:txbxContent.
     * @return string
     */
    protected static function lifted_text(string $box): string {
        $out = '';
        if (!preg_match_all('#<w:p\b.*?</w:p>#s', $box, $paragraphs)) {
            return '';
        }
        foreach ($paragraphs[0] as $paragraph) {
            preg_match_all('#<w:t\b[^>]*>([^<]*)</w:t>#', $paragraph, $texts);
            $text = implode('', $texts[1]);
            if (trim(html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8')) !== '') {
                $out .= '<w:br/><w:t xml:space="preserve">' . $text . '</w:t>';
            }
        }
        return $out;
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
     * Relationship ids of web pictures, video and sound on sign-in storage, with a name and the service.
     *
     * @param string $rels The part's relationships XML.
     * @return array id => ['name' => string, 'host' => string]
     */
    public static function cloud_targets(string $rels): array {
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
            $domain = \core_text::strtolower((string) parse_url($attrs['Target'] ?? '', PHP_URL_HOST));
            if (
                ($attrs['TargetMode'] ?? '') === 'External' && isset($attrs['Id']) && $domain !== ''
                    && in_array($type, linked_media::CONTENT_RELATIONSHIPS, true) && linked_media::is_signin_host($domain)
            ) {
                $name = linked_media::basename((string) parse_url($attrs['Target'], PHP_URL_PATH));
                $targets[$attrs['Id']] = [
                    'name' => preg_match('#\.[A-Za-z0-9]{2,5}$#', $name) ? $name
                        : get_string('onlinevideo', 'local_submissioncheck'),
                    'host' => linked_media::service_name($domain),
                ];
            }
        }
        return $targets;
    }

    /**
     * Replace each Word online video, and each picture or media link, stored on sign-in storage with a marker.
     *
     * @param string $xml A document part.
     * @param array $targets From cloud_targets().
     * @return string
     */
    public static function mark_cloud(string $xml, array $targets): string {
        if (!$targets && strpos($xml, 'webVideoPr') === false) {
            return $xml;
        }
        $online = get_string('onlinevideo', 'local_submissioncheck');
        $result = preg_replace_callback('#<w:drawing\b.*?</w:drawing>#s', function ($m) use ($targets, $online) {
            $item = null;
            foreach (linked_media::word_online_videos($m[0]) as $video) {
                $domain = \core_text::strtolower((string) parse_url($video['url'], PHP_URL_HOST));
                if ($domain !== '' && linked_media::is_signin_host($domain)) {
                    $item = ['name' => $video['name'] ?? $online, 'host' => linked_media::service_name($domain)];
                }
            }
            if ($item === null && preg_match_all('#\br:(?:link|embed)="([^"]+)"#', $m[0], $ids)) {
                foreach ($ids[1] as $id) {
                    $item = $item ?? ($targets[$id] ?? null);
                }
            }
            if ($item === null) {
                return $m[0];
            }
            [$width, $height] = self::size($m[0]);
            $marker = self::CLOUD . str_replace(['|', ']'], '', $item['name']) . '|' . str_replace(['|', ']'], '', $item['host']) .
                '|' . $width . '|' . $height . self::END;
            return '<w:t xml:space="preserve">' . htmlspecialchars($marker, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</w:t>';
        }, $xml);
        return $result ?? $xml;
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
