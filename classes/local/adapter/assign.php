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

namespace local_submissioncheck\local\adapter;

use local_submissioncheck\local\activation;
use local_submissioncheck\local\confirmation;
use local_submissioncheck\output\report_builder;

/**
 * Submission checks for assignments with file submissions.
 *
 * Checks need "Require students to click the submit button" off: the upload is the submission,
 * timed by Moodle, and the student can replace it while submissions are open. The report and the
 * confirmation window sit on top of that and never change the submission.
 *
 * @package    local_submissioncheck
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assign {
    /** @var string */
    public const COMPONENT = 'mod_assign';

    /**
     * Load the assignment for a course module.
     *
     * @param \cm_info|\stdClass $cm
     * @return \assign
     */
    public static function load($cm): \assign {
        global $CFG;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        $context = \context_module::instance($cm->id);
        return new \assign($context, $cm, get_course($cm->course));
    }

    /**
     * Whether checks run for this assignment.
     *
     * @param \cm_info|\stdClass $cm
     * @param \assign|null $assign
     * @return bool
     */
    public static function is_active($cm, ?\assign $assign = null): bool {
        if (!activation::is_switched_on($cm)) {
            return false;
        }
        $assign = $assign ?? self::load($cm);
        return self::meets_requirements($assign);
    }

    /**
     * Whether the assignment is set up the way checks need.
     *
     * @param \assign $assign
     * @return bool
     */
    public static function meets_requirements(\assign $assign): bool {
        $plugin = $assign->get_submission_plugin_by_type('file');
        return $plugin && $plugin->is_enabled() && empty($assign->get_instance()->submissiondrafts);
    }

    /**
     * Record a file submission and check it. Never throws: a failure here must not touch the submission.
     *
     * @param \assignsubmission_file\event\assessable_uploaded $event
     */
    public static function uploaded(\assignsubmission_file\event\assessable_uploaded $event): void {
        global $DB;
        try {
            $cm = get_coursemodule_from_id('assign', $event->contextinstanceid, 0, false, IGNORE_MISSING);
            if (!$cm) {
                return;
            }
            $cm = \cm_info::create($cm);
            $assign = self::load($cm);
            if (!self::is_active($cm, $assign)) {
                return;
            }
            $submission = $DB->get_record('assign_submission', ['id' => $event->objectid]);
            if (!$submission) {
                return;
            }
            $fs = get_file_storage();
            $files = [];
            foreach (
                $fs->get_area_files(
                    $event->contextid,
                    'assignsubmission_file',
                    'submission_files',
                    $submission->id,
                    'filepath, filename',
                    false
                ) as $file
            ) {
                $files[] = ['file' => $file, 'slot' => 0];
            }
            if (!$files) {
                return;
            }
            confirmation::record(
                $cm->id,
                self::COMPONENT,
                $submission->id,
                (int) $submission->userid,
                (int) $submission->groupid,
                (int) $event->userid,
                $files
            );
            self::flag_for_review($cm->id, (int) $event->userid);
        } catch (\Throwable $e) {
            debugging('Submission checks could not record an assignment upload: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Remember, for this session only, that the student just uploaded here, so the next assignment page they open sends
     * them to the review page. Uploads made by someone else for them are not flagged in their session.
     *
     * @param int $cmid
     * @param int $uploaderid
     */
    protected static function flag_for_review(int $cmid, int $uploaderid): void {
        global $SESSION, $USER;
        if ($uploaderid === (int) $USER->id) {
            $SESSION->local_submissioncheck_review = $cmid;
        }
    }

    /**
     * Whether to send the student from the assignment page to the review page now: they just uploaded here in this
     * session and the upload is awaiting their answer. Asking clears the flag.
     *
     * @param \cm_info $cm
     * @param int $userid
     * @return bool
     */
    public static function take_review_flag(\cm_info $cm, int $userid): bool {
        global $SESSION;
        if ((int) ($SESSION->local_submissioncheck_review ?? 0) !== (int) $cm->id) {
            return false;
        }
        unset($SESSION->local_submissioncheck_review);
        $data = self::report($cm, $userid, false);
        return $data && $data['awaiting'];
    }

    /**
     * The submission the user sees on the assignment page.
     *
     * @param \assign $assign
     * @param int $userid
     * @return \stdClass|null
     */
    public static function get_submission(\assign $assign, int $userid): ?\stdClass {
        if ($assign->get_instance()->teamsubmission) {
            $submission = $assign->get_group_submission($userid, 0, false);
        } else {
            $submission = $assign->get_user_submission($userid, false);
        }
        return $submission ?: null;
    }

    /**
     * Template data for the report a student sees, or null when there is nothing to show.
     *
     * @param \cm_info $cm
     * @param int $userid
     * @param bool $markseen Remember that the student has seen this upload's report (false for a look ahead).
     * @return array|null
     */
    public static function report(\cm_info $cm, int $userid, bool $markseen = true): ?array {
        global $DB;
        $assign = self::load($cm);
        if (!self::is_active($cm, $assign) || !has_capability('mod/assign:submit', $cm->context, $userid)) {
            return null;
        }
        $submission = self::get_submission($assign, $userid);
        if (!$submission) {
            return null;
        }
        $record = confirmation::current(self::COMPONENT, $submission->id);
        if (!$record || (int) $record->state === confirmation::REPLACED) {
            return null;
        }
        $files = confirmation::files($record);
        if (!$files) {
            return null;
        }

        $course = get_course($cm->course);
        $isgroup = !empty($assign->get_instance()->teamsubmission);
        $built = report_builder::files($files, ['uploadid' => $record->id], 'assign');
        $awaiting = confirmation::is_open($record);

        $uploadtime = report_builder::time((int) $record->timeuploaded);
        $plural = count($files) > 1 ? 'plural' : 'single';
        if ((int) $record->uploadedby === $userid) {
            $submitted = get_string('submittedat_you_' . $plural, 'local_submissioncheck', $uploadtime);
        } else {
            $uploader = $DB->get_record('user', ['id' => $record->uploadedby]);
            $submitted = get_string(
                'submittedat_other_' . $plural,
                'local_submissioncheck',
                (object) ['name' => $uploader ? fullname($uploader) : '', 'time' => $uploadtime]
            );
        }

        $replace = self::replace_state($assign, $userid, count($files) > 1);
        $data = [
            'uniqid' => \html_writer::random_id('local-submissioncheck-'),
            'cmid' => $cm->id,
            'heading' => get_string('reportheading', 'local_submissioncheck'),
            'continuing' => $awaiting && self::seen_before($record, $userid),
            'continuingmessage' => get_string('continuechecking', 'local_submissioncheck'),
            'submitted' => $submitted,
            'awaiting' => $awaiting,
            'windowmessage' => get_string('window_' . $plural, 'local_submissioncheck', (object) [
                'minutes' => confirmation::window() / MINSECS,
                'time' => $uploadtime,
                'deadline' => report_builder::time(confirmation::deadline($record)),
            ]),
            'statusmessage' => $awaiting ? '' : self::status_message($record, $userid, $isgroup, $plural),
            'latemessage' => $replace['late'] ? get_string('replacelate_' . $plural, 'local_submissioncheck') : '',
            'multiple' => count($files) > 1,
            'confirmby' => get_string('confirmby', 'local_submissioncheck', report_builder::time(confirmation::deadline($record))),
            'reviewurl' => self::review_url($cm->id)->out(false),
            'disclaimer' => report_builder::disclaimer($course, $cm),
            'files' => $built['files'],
            'pending' => $built['pending'],
            'actions' => $awaiting ? [
                'url' => (new \moodle_url('/local/submissioncheck/confirm.php'))->out(false),
                'sesskey' => sesskey(),
                'uploadid' => $record->id,
                'confirmlabel' => get_string('confirm_' . $plural, 'local_submissioncheck'),
                'backlabel' => get_string('goback', 'local_submissioncheck'),
                'backdisabled' => !$replace['can'],
                'backreason' => $replace['reason'],
            ] : null,
        ];
        if ($markseen) {
            self::mark_seen($record, $userid);
        }
        return $data;
    }

    /**
     * Whether the student can still replace the submission, and why not.
     *
     * @param \assign $assign
     * @param int $userid
     * @param bool $plural The reason talks about several files.
     * @return array ['can' => bool, 'late' => bool, 'reason' => string]
     */
    public static function replace_state(\assign $assign, int $userid, bool $plural = false): array {
        $instance = $assign->get_instance($userid);
        $flags = $assign->get_user_flags($userid, false);
        $due = (int) $instance->duedate;
        if ($flags && !empty($flags->extensionduedate)) {
            $due = (int) $flags->extensionduedate;
        }
        $can = $assign->submissions_open($userid) && has_capability('mod/assign:submit', $assign->get_context(), $userid);
        if ($can) {
            return ['can' => true, 'late' => $due && time() > $due, 'reason' => ''];
        }
        $cutoff = (int) $instance->cutoffdate;
        $pastcutoff = $cutoff && time() > $cutoff && !($flags && $flags->extensionduedate > time());
        return [
            'can' => false,
            'late' => false,
            'reason' => get_string(
                ($pastcutoff ? 'cannotreplace_cutoff' : 'cannotreplace') . ($plural ? '_plural' : ''),
                'local_submissioncheck'
            ),
        ];
    }

    /**
     * What happened to an upload whose window is over.
     *
     * @param \stdClass $record
     * @param int $userid The viewer.
     * @param bool $isgroup
     * @param string $plural 'single' or 'plural'.
     * @return string
     */
    protected static function status_message(\stdClass $record, int $userid, bool $isgroup, string $plural = 'single'): string {
        global $DB;
        if ((int) $record->state === confirmation::AUTOACCEPTED) {
            return get_string(
                $record->haswarnings ? 'autoaccepted_warnings' : 'autoaccepted',
                'local_submissioncheck',
                confirmation::window() / MINSECS
            );
        }
        $suffix = ($record->acknowledged ? '_ack' : '') . ($plural === 'plural' ? '_plural' : '');
        if (!$isgroup && (int) $record->resolvedby === $userid) {
            return get_string('confirmed_you' . $suffix, 'local_submissioncheck');
        }
        $who = $DB->get_record('user', ['id' => $record->resolvedby]);
        return get_string('confirmed_other' . $suffix, 'local_submissioncheck', $who ? fullname($who) : '');
    }

    /**
     * Whether this student has already seen the report for this upload.
     *
     * @param \stdClass $record
     * @param int $userid
     * @return bool
     */
    protected static function seen_before(\stdClass $record, int $userid): bool {
        return (int) get_user_preferences('local_submissioncheck_seen', 0, $userid) === (int) $record->id;
    }

    /**
     * Remember that this student has seen the report for this upload.
     *
     * @param \stdClass $record
     * @param int $userid
     */
    protected static function mark_seen(\stdClass $record, int $userid): void {
        if (!self::seen_before($record, $userid)) {
            set_user_preference('local_submissioncheck_seen', $record->id, $userid);
        }
    }

    /**
     * Whether a user may act on an upload: its owner, or a member of the group that owns it.
     *
     * @param \stdClass $record
     * @param int $userid
     * @return bool
     */
    public static function can_act(\stdClass $record, int $userid): bool {
        if ((int) $record->userid === $userid) {
            return true;
        }
        if ($record->userid == 0 && $record->groupid) {
            return groups_is_member($record->groupid, $userid);
        }
        return false;
    }

    /**
     * Where "take me back" goes.
     *
     * @param int $cmid
     * @return \moodle_url
     */
    public static function edit_url(int $cmid): \moodle_url {
        return new \moodle_url('/mod/assign/view.php', ['id' => $cmid, 'action' => 'editsubmission']);
    }

    /**
     * The review page for the student's current upload.
     *
     * @param int $cmid
     * @return \moodle_url
     */
    public static function review_url(int $cmid): \moodle_url {
        return new \moodle_url('/local/submissioncheck/review.php', ['id' => $cmid]);
    }

    /**
     * The assignment page.
     *
     * @param int $cmid
     * @return \moodle_url
     */
    public static function view_url(int $cmid): \moodle_url {
        return new \moodle_url('/mod/assign/view.php', ['id' => $cmid]);
    }
}
