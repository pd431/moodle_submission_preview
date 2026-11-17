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
 * Whether the file is password protected, so a marker cannot open it.
 *
 * Encrypted Word, PowerPoint and Excel files are saved as compound files holding an
 * EncryptionInfo stream; PDFs carry an /Encrypt entry; OpenDocument and zip files mark encrypted
 * entries.
 *
 * @package    local_submissioncheck
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class encrypted extends check {
    /** "EncryptionInfo" as a compound file directory entry name (UTF-16LE). */
    public const OLE_ENCRYPTIONINFO = "E\0n\0c\0r\0y\0p\0t\0i\0o\0n\0I\0n\0f\0o\0";

    /**
     * Name of this check, stored with its results.
     *
     * @return string
     */
    public function get_name(): string {
        return 'encrypted';
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
        return in_array($file->get_type(), [
            inspected_file::TYPE_OLE, inspected_file::TYPE_PDF, inspected_file::TYPE_ODF, inspected_file::TYPE_ZIP,
            inspected_file::TYPE_OOXML,
        ], true);
    }

    /**
     * Run the check.
     *
     * @param inspected_file $file
     * @return finding[]
     */
    public function run(inspected_file $file): array {
        return $this->is_protected($file) ? [new finding('passwordprotected', ['name' => $file->get_filename()])] : [];
    }

    /**
     * Whether the file is encrypted.
     *
     * @param inspected_file $file
     * @return bool
     */
    protected function is_protected(inspected_file $file): bool {
        switch ($file->get_type()) {
            case inspected_file::TYPE_OLE:
                return $file->contains(self::OLE_ENCRYPTIONINFO, 4 * 1024 * 1024);
            case inspected_file::TYPE_PDF:
                return $file->contains('/Encrypt');
            case inspected_file::TYPE_ODF:
                $manifest = $file->read_entry('META-INF/manifest.xml');
                return ($manifest !== null && strpos($manifest, 'encryption-data') !== false)
                    || $file->has_encrypted_entries();
            default:
                return $file->has_encrypted_entries();
        }
    }
}
