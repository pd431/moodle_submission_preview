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

    /** Default for the signinhosts setting: storage where items open only for people they are shared with. */
    public const DEFAULT_SIGNIN_HOSTS = ['sharepoint.com', 'onedrive.live.com', '1drv.ms', 'svc.ms', 'microsoftstream.com',
        'drive.google.com', 'docs.google.com', 'dropbox.com', 'box.com', 'icloud.com'];

    /** Service names shown for known hosts: host pattern => name. The first that matches wins. */
    public const SERVICE_NAMES = [
        '#-my\.sharepoint\.[a-z]+$#' => 'OneDrive',
        '#(^|\.)onedrive\.live\.com$#' => 'OneDrive',
        '#(^|\.)1drv\.ms$#' => 'OneDrive',
        '#(^|\.)svc\.ms$#' => 'OneDrive',
        '#(^|\.)sharepoint\.[a-z]+$#' => 'SharePoint',
        '#(^|\.)microsoftstream\.com$#' => 'Microsoft Stream',
        '#(^|\.)(drive|docs)\.google\.com$#' => 'Google Drive',
        '#(^|\.)dropbox(usercontent)?\.com$#' => 'Dropbox',
        '#(^|\.)box\.com$#' => 'Box',
        '#(^|\.)icloud\.com$#' => 'iCloud',
    ];

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
        return 3;
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
        $findings = [];
        $found = self::locate($file);
        if ($found) {
            $names = array_values(array_unique(array_column($found, 'name')));
            $findings[] = new finding('linkedmedia', ['name' => $file->get_filename(), 'slides' => self::slides($found)], $names);
        }
        $cloud = self::locate_cloud($file);
        if ($cloud) {
            $names = array_values(array_unique(array_column($cloud, 'name')));
            $findings[] = new finding('cloudmedia', [
                'name' => $file->get_filename(),
                'slides' => self::slides($cloud),
                'hosts' => array_column($cloud, 'host', 'name'),
            ], $names);
        }
        return $findings;
    }

    /**
     * The first slide each named item is on.
     *
     * @param array $found
     * @return array name => slide
     */
    protected static function slides(array $found): array {
        $slides = [];
        foreach ($found as $item) {
            if ($item['slide'] !== null && !isset($slides[$item['name']])) {
                $slides[$item['name']] = $item['slide'];
            }
        }
        return $slides;
    }

    /**
     * Media the file plays or shows from sign-in storage, where access depends on sharing settings: Word online videos,
     * and linked pictures, video and sound on those hosts. Plain hyperlinks in text are not media.
     *
     * @param inspected_file $file
     * @return array [['name' => string, 'host' => service name, 'slide' => int|null], ...]
     */
    public static function locate_cloud(inspected_file $file): array {
        return array_values(array_filter(self::locate_web_media($file), function ($item) {
            return $item['signin'];
        }));
    }

    /**
     * Every online video and linked web picture, video or sound file in a Word, PowerPoint or OpenDocument file.
     *
     * @param inspected_file $file
     * @return array [['name' => string, 'host' => string, 'domain' => string, 'signin' => bool, 'slide' => int|null], ...]
     */
    public static function locate_web_media(inspected_file $file): array {
        $found = [];
        $add = function (string $url, string $name, ?int $slide) use (&$found) {
            $domain = \core_text::strtolower((string) parse_url(trim($url), PHP_URL_HOST));
            if ($domain === '' || !preg_match('#^https?://#i', trim($url))) {
                return;
            }
            $signin = self::is_signin_host($domain);
            $item = ['name' => $name, 'host' => $signin ? self::service_name($domain) : self::short_domain($domain),
                'domain' => $domain, 'signin' => $signin, 'slide' => $slide];
            if (!in_array($item, $found, true)) {
                $found[] = $item;
            }
        };
        $online = get_string('onlinevideo', 'local_submissioncheck');
        if ($file->get_type() === inspected_file::TYPE_OOXML) {
            $parts = array_slice($file->find_entries('#^(word|ppt)/.*\.xml(\.rels)?$#'), 0, self::MAX_PARTS);
            natsort($parts);
            foreach ($parts as $part) {
                $xml = (string) $file->read_entry($part);
                $slide = preg_match('#^ppt/slides/(_rels/)?slide(\d+)\.xml(\.rels)?$#', $part, $m) ? (int) $m[2] : null;
                if (substr($part, -5) === '.rels') {
                    if (stripos($xml, 'External') === false) {
                        continue;
                    }
                    foreach ((new self())->elements($xml, 'Relationship') as $attrs) {
                        $type = \core_text::strtolower((string) substr(strrchr($attrs['Type'] ?? '', '/') ?: '', 1));
                        if (($attrs['TargetMode'] ?? '') === 'External' && in_array($type, self::CONTENT_RELATIONSHIPS, true)) {
                            $target = (string) ($attrs['Target'] ?? '');
                            $add($target, self::web_name($target, $online), $slide);
                        }
                    }
                } else if (strpos($xml, 'webVideoPr') !== false) {
                    foreach (self::word_online_videos($xml) as $video) {
                        $add($video['url'], $video['name'] ?? $online, $slide);
                    }
                }
            }
        } else if ($file->get_type() === inspected_file::TYPE_ODF) {
            foreach ((new self())->elements((string) $file->read_entry('content.xml'), self::ODF_CONTENT_ELEMENTS) as $attrs) {
                $href = (string) ($attrs['xlink:href'] ?? '');
                $add($href, self::web_name($href, $online), $attrs['#page']);
            }
        }
        return $found;
    }

    /**
     * Word online videos in a part: the player address and the video's stored description.
     *
     * @param string $xml
     * @return array [['url' => string, 'name' => string|null, 'drawing' => string], ...]
     */
    public static function word_online_videos(string $xml): array {
        $videos = [];
        if (!preg_match_all('#<w:drawing\b.*?</w:drawing>#s', $xml, $drawings)) {
            return [];
        }
        foreach ($drawings[0] as $drawing) {
            if (!preg_match('#<wp15:webVideoPr\b[^>]*\bembeddedHtml="([^"]*)"#', $drawing, $m)) {
                continue;
            }
            $html = html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
            if (!preg_match('#\ssrc\s*=\s*["\']([^"\']+)["\']#i', $html, $src)) {
                continue;
            }
            $name = null;
            if (preg_match('#<wp:docPr\b[^>]*\bdescr="([^"]+)"#', $drawing, $d)) {
                $name = trim(html_entity_decode($d[1], ENT_QUOTES | ENT_XML1, 'UTF-8')) ?: null;
            }
            $videos[] = ['url' => html_entity_decode($src[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'name' => $name,
                'drawing' => $drawing];
        }
        return $videos;
    }

    /**
     * The sign-in storage hosts from the site setting; sub-sites match.
     *
     * @return string[]
     */
    public static function signin_hosts(): array {
        $setting = get_config('local_submissioncheck', 'signinhosts');
        if ($setting === false) {
            return self::DEFAULT_SIGNIN_HOSTS;
        }
        $hosts = preg_split('#[\s,]+#', \core_text::strtolower((string) $setting), -1, PREG_SPLIT_NO_EMPTY);
        return array_values(array_unique(array_map(function ($host) {
            return trim(preg_replace('#^(https?://)?(www\.)?#', '', $host), './');
        }, $hosts)));
    }

    /**
     * Whether a host is sign-in storage: one of the setting's hosts or a sub-site of one.
     *
     * @param string $domain
     * @return bool
     */
    public static function is_signin_host(string $domain): bool {
        foreach (self::signin_hosts() as $host) {
            if ($host !== '' && ($domain === $host || substr($domain, -strlen($host) - 1) === '.' . $host)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The service a sign-in storage host belongs to, or the host when it is not a known one.
     *
     * @param string $domain
     * @return string
     */
    public static function service_name(string $domain): string {
        foreach (self::SERVICE_NAMES as $pattern => $name) {
            if (preg_match($pattern, $domain)) {
                return $name;
            }
        }
        return self::short_domain($domain);
    }

    /**
     * A host without "www.".
     *
     * @param string $domain
     * @return string
     */
    protected static function short_domain(string $domain): string {
        return preg_replace('#^www\.#', '', $domain);
    }

    /**
     * A name for linked web media: the file name when the address has one, else the fallback.
     *
     * @param string $url
     * @param string $fallback
     * @return string
     */
    protected static function web_name(string $url, string $fallback): string {
        $candidates = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        foreach (['id', 'file', 'path'] as $key) {
            if (!empty($query[$key]) && is_string($query[$key])) {
                $candidates[] = $query[$key];
            }
        }
        $candidates[] = (string) parse_url($url, PHP_URL_PATH);
        foreach ($candidates as $path) {
            $name = self::basename($path);
            if (preg_match('#\.[A-Za-z0-9]{2,5}$#', $name) && !preg_match('#\.(aspx|php|html?)$#i', $name)) {
                return $name;
            }
        }
        return $fallback;
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
