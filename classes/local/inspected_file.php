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
 * A submitted file opened for inspection: what it really is, and safe access to its contents.
 *
 * Every read is bounded. Zip packages are opened once, with a cap on the number of entries and on
 * the bytes read from any one entry, so a hostile or damaged file cannot exhaust memory or time.
 *
 * @package    local_submissioncheck
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class inspected_file {
    /** Files larger than this are only sniffed, never unpacked. */
    public const MAX_INSPECT_BYTES = 200 * 1024 * 1024;

    /** Most entries a zip package may list before it is treated as hostile. */
    public const MAX_ZIP_ENTRIES = 20000;

    /** Most bytes read from one entry of a zip package. */
    public const MAX_ENTRY_BYTES = 32 * 1024 * 1024;

    /** @var string Detected types. */
    public const TYPE_PDF = 'pdf';
    /** @var string */
    public const TYPE_OOXML = 'ooxml';
    /** @var string */
    public const TYPE_ODF = 'odf';
    /** @var string */
    public const TYPE_IWORK = 'iwork';
    /** @var string */
    public const TYPE_ZIP = 'zip';
    /** @var string */
    public const TYPE_OLE = 'ole';
    /** @var string */
    public const TYPE_JPEG = 'jpeg';
    /** @var string */
    public const TYPE_PNG = 'png';
    /** @var string */
    public const TYPE_GIF = 'gif';
    /** @var string */
    public const TYPE_RTF = 'rtf';
    /** @var string */
    public const TYPE_HTML = 'html';
    /** @var string */
    public const TYPE_LNK = 'lnk';
    /** @var string */
    public const TYPE_URLSHORTCUT = 'urlshortcut';
    /** @var string */
    public const TYPE_GOOGLE = 'google';
    /** @var string */
    public const TYPE_TEXT = 'text';
    /** @var string */
    public const TYPE_EMPTY = 'empty';
    /** @var string */
    public const TYPE_UNKNOWN = 'unknown';

    /** @var string Path to a local copy of the content. */
    protected $path;

    /** @var bool Whether $path is a temporary copy this object must delete. */
    protected $ownspath;

    /** @var string File name as the student named it. */
    protected $filename;

    /** @var int Size in bytes. */
    protected $size;

    /** @var string|null Cached detected type. */
    protected $type = null;

    /** @var \ZipArchive|null|false Open package, false when it could not be opened. */
    protected $zip = null;

    /** @var string[]|null Entry names of the package. */
    protected $entries = null;

    /**
     * Constructor.
     *
     * @param string $path Local path to the content.
     * @param string $filename The file's name.
     * @param bool $ownspath Delete $path when this object is destroyed.
     */
    public function __construct(string $path, string $filename, bool $ownspath = false) {
        $this->path = $path;
        $this->filename = $filename;
        $this->ownspath = $ownspath;
        $this->size = (int) @filesize($path);
    }

    /**
     * Open a stored file for inspection.
     *
     * @param \stored_file $file
     * @return self
     */
    public static function from_stored_file(\stored_file $file): self {
        $path = $file->copy_content_to_temp('local_submissioncheck');
        if ($path === false) {
            throw new \moodle_exception('cannotreadfile', 'error', '', $file->get_filename());
        }
        return new self($path, $file->get_filename(), true);
    }

    /**
     * Destructor: close the package and remove the temporary copy.
     */
    public function __destruct() {
        if ($this->zip instanceof \ZipArchive) {
            @$this->zip->close();
        }
        if ($this->ownspath && $this->path && file_exists($this->path)) {
            @unlink($this->path);
        }
    }

    /**
     * The file's name.
     *
     * @return string
     */
    public function get_filename(): string {
        return $this->filename;
    }

    /**
     * Lower-case extension without the dot, or ''.
     *
     * @return string
     */
    public function get_extension(): string {
        $pos = strrpos($this->filename, '.');
        return $pos === false ? '' : \core_text::strtolower(substr($this->filename, $pos + 1));
    }

    /**
     * Size in bytes.
     *
     * @return int
     */
    public function get_size(): int {
        return $this->size;
    }

    /**
     * Whether the file is small enough to unpack and read.
     *
     * @return bool
     */
    public function is_inspectable(): bool {
        return $this->size > 0 && $this->size <= self::MAX_INSPECT_BYTES;
    }

    /**
     * First bytes of the file.
     *
     * @param int $length
     * @return string
     */
    public function head(int $length): string {
        $fh = @fopen($this->path, 'rb');
        if (!$fh) {
            return '';
        }
        $data = (string) fread($fh, max(1, $length));
        fclose($fh);
        return $data;
    }

    /**
     * Last bytes of the file.
     *
     * @param int $length
     * @return string
     */
    public function tail(int $length): string {
        $fh = @fopen($this->path, 'rb');
        if (!$fh) {
            return '';
        }
        $length = min($length, $this->size);
        if ($length <= 0) {
            fclose($fh);
            return '';
        }
        fseek($fh, -$length, SEEK_END);
        $data = (string) fread($fh, $length);
        fclose($fh);
        return $data;
    }

    /**
     * Whether a byte string occurs anywhere in the file, read in bounded chunks.
     *
     * @param string $needle
     * @param int $maxbytes Stop after this many bytes.
     * @return bool
     */
    public function contains(string $needle, int $maxbytes = self::MAX_INSPECT_BYTES): bool {
        $fh = @fopen($this->path, 'rb');
        if (!$fh || $needle === '') {
            return false;
        }
        $overlap = strlen($needle) - 1;
        $carry = '';
        $read = 0;
        $found = false;
        while (!feof($fh) && $read < $maxbytes) {
            $chunk = (string) fread($fh, 1024 * 1024);
            $read += strlen($chunk);
            if (strpos($carry . $chunk, $needle) !== false) {
                $found = true;
                break;
            }
            $carry = $overlap > 0 ? substr($chunk, -$overlap) : '';
        }
        fclose($fh);
        return $found;
    }

    /**
     * What the file really is, from its content rather than its name.
     *
     * @return string One of the TYPE_ constants.
     */
    public function get_type(): string {
        if ($this->type === null) {
            $this->type = $this->detect_type();
        }
        return $this->type;
    }

    /**
     * Detect the type from magic bytes and, for zip packages, from their entries.
     *
     * @return string
     */
    protected function detect_type(): string {
        if ($this->size === 0) {
            return self::TYPE_EMPTY;
        }
        $head = $this->head(2048);
        $type = self::type_from_head($head);
        if ($type === self::TYPE_ZIP) {
            return $this->detect_package_type();
        }
        if ($type !== self::TYPE_UNKNOWN) {
            return $type;
        }
        $text = ltrim(preg_replace('/^\xEF\xBB\xBF/', '', $head));
        if (
            $this->size < 4096 && strpos($text, '{') === 0 && preg_match('/"(doc_id|resource_id)"\s*:/', $text)
                && preg_match('/google\.com/', $text)
        ) {
            return self::TYPE_GOOGLE;
        }
        if (preg_match('//u', substr($head, 0, 1500)) && !preg_match('/[\x00-\x08\x0E-\x1F]/', $head)) {
            return self::TYPE_TEXT;
        }
        return self::TYPE_UNKNOWN;
    }

    /**
     * What the first bytes of a file say it is. A zip package comes back as TYPE_ZIP; telling apart its kinds needs
     * its entries. Text that isn't one of the marked kinds comes back as TYPE_UNKNOWN.
     *
     * @param string $head The first bytes of the file (up to 2048 are used).
     * @return string One of the TYPE_ constants.
     */
    public static function type_from_head(string $head): string {
        if (strpos(substr($head, 0, 1024), '%PDF-') !== false) {
            return self::TYPE_PDF;
        }
        if (strncmp($head, "PK\x03\x04", 4) === 0 || strncmp($head, "PK\x05\x06", 4) === 0) {
            return self::TYPE_ZIP;
        }
        if (strncmp($head, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1", 8) === 0) {
            return self::TYPE_OLE;
        }
        if (strncmp($head, "\xFF\xD8\xFF", 3) === 0) {
            return self::TYPE_JPEG;
        }
        if (strncmp($head, "\x89PNG\r\n\x1A\n", 8) === 0) {
            return self::TYPE_PNG;
        }
        if (strncmp($head, 'GIF87a', 6) === 0 || strncmp($head, 'GIF89a', 6) === 0) {
            return self::TYPE_GIF;
        }
        if (strncmp($head, "L\x00\x00\x00\x01\x14\x02\x00", 8) === 0) {
            return self::TYPE_LNK;
        }
        $text = ltrim(preg_replace('/^\xEF\xBB\xBF/', '', $head));
        if (strncmp($text, '{\\rtf', 5) === 0) {
            return self::TYPE_RTF;
        }
        if (stripos($text, '[InternetShortcut]') === 0) {
            return self::TYPE_URLSHORTCUT;
        }
        $lower = \core_text::strtolower(substr($text, 0, 1024));
        if (strpos($lower, '<!doctype html') === 0 || strpos($lower, '<html') !== false) {
            return self::TYPE_HTML;
        }
        return self::TYPE_UNKNOWN;
    }

    /**
     * Tell apart the kinds of zip package.
     *
     * @return string
     */
    protected function detect_package_type(): string {
        $entries = $this->get_entries();
        if ($entries === null) {
            return self::TYPE_ZIP;
        }
        if (in_array('[Content_Types].xml', $entries, true)) {
            return self::TYPE_OOXML;
        }
        if (in_array('mimetype', $entries, true)) {
            $mimetype = (string) $this->read_entry('mimetype', 200);
            if (strpos($mimetype, 'application/vnd.oasis.opendocument') === 0) {
                return self::TYPE_ODF;
            }
        }
        if (
            in_array('index.xml', $entries, true) || in_array('Index/Document.iwa', $entries, true)
                || in_array('Index.zip', $entries, true)
        ) {
            return self::TYPE_IWORK;
        }
        return self::TYPE_ZIP;
    }

    /**
     * Open the zip package once.
     *
     * @return \ZipArchive|null Null when it is not a readable package.
     */
    public function get_zip(): ?\ZipArchive {
        if ($this->zip === null) {
            $this->zip = false;
            if ($this->is_inspectable() && class_exists('ZipArchive')) {
                $zip = new \ZipArchive();
                if ($zip->open($this->path, \ZipArchive::RDONLY) === true) {
                    $this->zip = $zip;
                }
            }
        }
        return $this->zip ?: null;
    }

    /**
     * Whether the package passes the zip library's consistency checks.
     *
     * @return bool
     */
    public function zip_is_consistent(): bool {
        if (!$this->is_inspectable() || !class_exists('ZipArchive')) {
            return true;
        }
        $zip = new \ZipArchive();
        $result = $zip->open($this->path, \ZipArchive::RDONLY | \ZipArchive::CHECKCONS);
        if ($result !== true) {
            return false;
        }
        $ok = $zip->numFiles > 0;
        $zip->close();
        return $ok;
    }

    /**
     * Names of the package's entries, or null when it is not a readable package.
     *
     * @return string[]|null
     */
    public function get_entries(): ?array {
        if ($this->entries === null) {
            $zip = $this->get_zip();
            if (!$zip || $zip->numFiles > self::MAX_ZIP_ENTRIES) {
                return null;
            }
            $this->entries = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if ($name !== false) {
                    $this->entries[] = $name;
                }
            }
        }
        return $this->entries;
    }

    /**
     * Whether any entry of the package is encrypted.
     *
     * @return bool
     */
    public function has_encrypted_entries(): bool {
        $zip = $this->get_zip();
        if (!$zip || $zip->numFiles > self::MAX_ZIP_ENTRIES) {
            return false;
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat && !empty($stat['encryption_method'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Read one entry of the package, bounded.
     *
     * @param string $name
     * @param int $maxbytes
     * @return string|null Null when the entry is missing or unreadable.
     */
    public function read_entry(string $name, int $maxbytes = self::MAX_ENTRY_BYTES): ?string {
        $zip = $this->get_zip();
        if (!$zip) {
            return null;
        }
        $stat = $zip->statName($name);
        if (!$stat) {
            return null;
        }
        $data = $zip->getFromName($name, min($maxbytes, max(1, (int) $stat['size'])));
        return $data === false ? null : $data;
    }

    /**
     * Entries whose names match a pattern.
     *
     * @param string $regex
     * @return string[]
     */
    public function find_entries(string $regex): array {
        $entries = $this->get_entries() ?? [];
        return array_values(array_filter($entries, function ($name) use ($regex) {
            return preg_match($regex, $name) === 1;
        }));
    }

    /**
     * Read the whole file as text, bounded.
     *
     * @param int $maxbytes
     * @return string
     */
    public function read_all(int $maxbytes = self::MAX_ENTRY_BYTES): string {
        return $this->head(min($maxbytes, max(1, $this->size)));
    }
}
