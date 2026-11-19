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

use local_submissioncheck\local\file_facts;
use local_submissioncheck\local\checks\opens;
use local_submissioncheck\local\file_result;
use local_submissioncheck\local\html_preview;
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
    /** Broken items listed before "and N more". */
    public const BROKEN_SHOWN = 5;

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
        $broken = [];
        $targets = [];
        $mediahelp = false;
        // Items can be jumped to where the preview marks them: a Word document drawn in the browser (boxes and links),
        // or the boxes under each slide of a text-only preview.
        $drawnword = $status['kind'] === preview::KIND_DOCX;
        foreach ($result->findings as $finding) {
            $items = $finding->broken_items();
            if ($items === null) {
                $broken[] = ['name' => $finding->get_message(), 'reason' => null, 'place' => null, 'jump' => false,
                    'anchor' => null];
                continue;
            }
            $mediahelp = true;
            foreach ($items as $item) {
                $islink = $finding->code === 'brokenlink';
                if ($islink && $item['target'] !== null) {
                    $targets[$item['target']] = $item['name'];
                }
                $broken[] = $item + [
                    'jump' => $drawnword || ($status['textonly'] && $finding->code === 'linkedmedia'),
                    'anchor' => html_preview::anchor($item['name']),
                ];
            }
        }
        $count = count($broken);
        $codes = array_column(array_map(function ($finding) {
            return $finding->to_array();
        }, $result->findings), 'code');
        $frame = self::frame($file, $status, $codes);
        $url = new \moodle_url('/local/submissioncheck/preview.php', ['fileid' => $file->get_id()] + $urlparams);
        return [
            'name' => $name,
            'checkslabel' => get_string('checksfor', 'local_submissioncheck', $name),
            'factslabel' => get_string('factabout', 'local_submissioncheck', $name),
            'facts' => file_facts::get($file),
            'previewlabel' => get_string('previewof', 'local_submissioncheck', $name),
            'hasbroken' => $count > 0,
            'brokencount' => $count,
            'brokenlabel' => get_string('brokenlabel', 'local_submissioncheck', $name),
            'brokenheading' => $count === 1 ? get_string('brokenheading_one', 'local_submissioncheck')
                : get_string('brokenheading', 'local_submissioncheck', $count),
            'brokenshown' => array_slice($broken, 0, self::BROKEN_SHOWN),
            'brokenmore' => array_slice($broken, self::BROKEN_SHOWN),
            'hasbrokenmore' => $count > self::BROKEN_SHOWN,
            'brokenmorelabel' => get_string('brokenmore', 'local_submissioncheck', $count - self::BROKEN_SHOWN),
            'brokenhelp' => $mediahelp ? (string) get_config('local_submissioncheck', 'helplink') : '',
            'brokentargets' => json_encode($targets),
            'checksunavailable' => $result->is_unavailable(),
            'checksunavailablemessage' => get_string('checksunavailable_' . $unavailablekey, 'local_submissioncheck', $name),
            'incomplete' => !$result->complete && !$result->is_unavailable(),
            'incompletemessage' => get_string('checksincomplete', 'local_submissioncheck', $name),
            'frame' => $frame,
            'previewready' => $frame === 'drawn',
            'previewpdf' => $status['kind'] === preview::KIND_PDF,
            'previewimage' => $status['kind'] === preview::KIND_IMAGE,
            'previewvideo' => $status['kind'] === preview::KIND_VIDEO,
            'previewaudio' => $status['kind'] === preview::KIND_AUDIO,
            'previewdocx' => $status['kind'] === preview::KIND_DOCX,
            'previewhtml' => $status['kind'] === preview::KIND_HTML,
            'previewtextonly' => $status['textonly'],
            'previewtextonlymessage' => get_string('previewtextonly', 'local_submissioncheck'),
            'previewloadingmessage' => get_string('previewloading', 'local_submissioncheck', $name),
            'previewdrawfailedmessage' => get_string('previewdrawfailed', 'local_submissioncheck'),
            'previewurl' => $url->out(false),
            'openpreview' => get_string('openpreview', 'local_submissioncheck', $name),
            'previewpending' => $frame === 'pending',
            'previewpendingmessage' => get_string('previewpending', 'local_submissioncheck', $name),
            'previewtoolarge' => $frame === 'toolarge',
            'previewtoolargemessage' => get_string('previewtoolarge', 'local_submissioncheck', $name),
            'frameunreadable' => $frame === 'unreadable',
            'frameprotected' => $frame === 'protected',
            'framenotype' => $frame === 'notype',
        ];
    }

    /**
     * What the preview frame shows for a file: its preview, or why there is none. A file the checks found cannot be
     * opened (or is password protected) says so even when a preview could be attempted.
     *
     * @param \stored_file $file
     * @param array $status From preview::get_status().
     * @param string[] $codes Finding codes for the file.
     * @return string drawn, pending, toolarge, unreadable, protected or notype
     */
    public static function frame(\stored_file $file, array $status, array $codes): string {
        if (in_array('passwordprotected', $codes, true)) {
            return 'protected';
        }
        $extension = \core_text::strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION));
        if (in_array('cannotopen', $codes, true) && in_array($extension, opens::SIGNED_EXTENSIONS, true)) {
            return 'unreadable';
        }
        switch ($status['status']) {
            case preview::READY:
                return 'drawn';
            case preview::PENDING:
                return 'pending';
            case preview::TOO_LARGE:
                return 'toolarge';
        }
        return 'notype';
    }

    /**
     * The label of the second press when going ahead with problems: how many broken items, or that a file can't be
     * read.
     *
     * @param array $files Per-file data from files().
     * @param string $key String key: confirmagain (assignment) or quizconfirmagain.
     * @return string
     */
    public static function confirm_again_label(array $files, string $key): string {
        foreach ($files as $file) {
            if (in_array($file['frame'], ['unreadable', 'protected'], true)) {
                return get_string($key . '_unreadable', 'local_submissioncheck');
            }
        }
        $count = array_sum(array_column($files, 'brokencount'));
        return $count === 1 ? get_string($key . '_one', 'local_submissioncheck')
            : get_string($key, 'local_submissioncheck', $count);
    }

    /**
     * The review heading naming the assessment and the module, both in bold. HTML.
     *
     * @param \stdClass $course
     * @param \cm_info $cm
     * @return string
     */
    public static function heading(\stdClass $course, \cm_info $cm): string {
        $context = \context_course::instance($course->id);
        return get_string('reviewheading', 'local_submissioncheck', (object) [
            'assessment' => \html_writer::tag('strong', s(format_string($cm->name, true, ['context' => $cm->context]))),
            'module' => \html_writer::tag('strong', s(format_string($course->shortname, true, ['context' => $context]))),
        ]);
    }

    /**
     * The short note that previews are approximate.
     *
     * @return string
     */
    public static function disclaimer(): string {
        return get_string('disclaimer', 'local_submissioncheck');
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
