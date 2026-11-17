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

namespace local_submissioncheck\output;

use local_submissioncheck\local\file_result;
use local_submissioncheck\local\preview;
use local_submissioncheck\local\result_store;

/**
 * Builds the per-file part of a check report: findings, then the preview, for each file.
 *
 * @package    local_submissioncheck
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report_builder {
    /**
     * Template data for a list of files.
     *
     * @param array $files [['file' => stored_file, 'slot' => int], ...]
     * @param array $urlparams Extra parameters the preview URL needs for its access check.
     * @param string $unavailablekey String shown when checks or the preview cannot run.
     * @return array ['files' => array, 'haswarnings' => bool, 'pending' => bool]
     */
    public static function files(array $files, array $urlparams, string $unavailablekey): array {
        $out = [];
        $haswarnings = false;
        $pending = false;
        foreach ($files as $entry) {
            /** @var \stored_file $file */
            $file = $entry['file'];
            $result = result_store::get($file);
            $haswarnings = $haswarnings || $result->has_warnings();
            $status = preview::get_status($file);
            $pending = $pending || $status['status'] === preview::PENDING;
            $out[] = self::file($file, $result, $status, $urlparams, $unavailablekey);
        }
        return ['files' => $out, 'haswarnings' => $haswarnings, 'pending' => $pending];
    }

    /**
     * Template data for one file.
     *
     * @param \stored_file $file
     * @param file_result $result
     * @param array $status Preview status.
     * @param array $urlparams
     * @param string $unavailablekey
     * @return array
     */
    protected static function file(
        \stored_file $file,
        file_result $result,
        array $status,
        array $urlparams,
        string $unavailablekey
    ): array {
        $name = $file->get_filename();
        $findings = [];
        foreach ($result->findings as $finding) {
            $findings[] = [
                'message' => $finding->get_message(),
                'items' => array_map(function ($label) {
                    return ['name' => $label];
                }, $finding->item_labels()),
                'hasitems' => !empty($finding->items),
            ];
        }
        $url = new \moodle_url('/local/submissioncheck/preview.php', ['fileid' => $file->get_id()] + $urlparams);
        return [
            'name' => $name,
            'checkslabel' => get_string('checksfor', 'local_submissioncheck', $name),
            'previewlabel' => get_string('previewof', 'local_submissioncheck', $name),
            'ok' => !$result->has_warnings() && !$result->is_unavailable(),
            'okmessage' => get_string('noproblems', 'local_submissioncheck', $name),
            'findings' => $findings,
            'hasfindings' => !empty($findings),
            'checksunavailable' => $result->is_unavailable(),
            'checksunavailablemessage' => get_string('checksunavailable_' . $unavailablekey, 'local_submissioncheck', $name),
            'incomplete' => !$result->complete && !$result->is_unavailable(),
            'incompletemessage' => get_string('checksincomplete', 'local_submissioncheck', $name),
            'previewready' => $status['status'] === preview::READY,
            'previewpdf' => $status['kind'] === preview::KIND_PDF,
            'previewimage' => $status['kind'] === preview::KIND_IMAGE,
            'previewvideo' => $status['kind'] === preview::KIND_VIDEO,
            'previewaudio' => $status['kind'] === preview::KIND_AUDIO,
            'previewdocx' => $status['kind'] === preview::KIND_DOCX,
            'previewhtml' => $status['kind'] === preview::KIND_HTML,
            'previewtextonly' => $status['textonly'],
            'previewtextonlymessage' => get_string('previewtextonly', 'local_submissioncheck'),
            'previewloadingmessage' => get_string('previewloading', 'local_submissioncheck', $name),
            'previewurl' => $url->out(false),
            'openpreview' => get_string('openpreview', 'local_submissioncheck', $name),
            'previewpending' => $status['status'] === preview::PENDING,
            'previewpendingmessage' => get_string('previewpending', 'local_submissioncheck', $name),
            'previewunsupported' => $status['status'] === preview::UNSUPPORTED,
            'previewunsupportedmessage' => get_string('previewunsupported', 'local_submissioncheck', $name),
            'previewunavailable' => $status['status'] === preview::UNAVAILABLE,
            'previewunavailablemessage' => get_string('previewunavailable_' . $unavailablekey, 'local_submissioncheck'),
            'previewtoolarge' => $status['status'] === preview::TOO_LARGE,
            'previewtoolargemessage' => get_string('previewtoolarge', 'local_submissioncheck', $name),
        ];
    }

    /**
     * The approximate-preview notice naming the module and assessment.
     *
     * @param \stdClass $course
     * @param \cm_info $cm
     * @return string
     */
    public static function disclaimer(\stdClass $course, \cm_info $cm): string {
        $context = \context_course::instance($course->id);
        return get_string('disclaimer', 'local_submissioncheck', (object) [
            'modulecode' => format_string($course->shortname, true, ['context' => $context]),
            'assessment' => format_string($cm->name, true, ['context' => $cm->context]),
        ]);
    }

    /**
     * A time the student will read, in their own timezone.
     *
     * @param int $time
     * @return string
     */
    public static function time(int $time): string {
        $format = userdate($time, '%Y%m%d') === userdate(time(), '%Y%m%d')
            ? get_string('strftimetime24', 'langconfig')
            : get_string('strftimedatetimeshort', 'langconfig');
        return userdate($time, $format);
    }
}
