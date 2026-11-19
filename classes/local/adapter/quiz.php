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
use mod_quiz\quiz_attempt;

/**
 * Submission checks for files attached to essay questions in a quiz.
 *
 * Results show under each question while the student answers and on the summary page. The quiz
 * cannot be held back by a plugin, so the acknowledgement is asked for in the page and recorded,
 * and a finished attempt is always recorded with whether it was acknowledged.
 *
 * @package    local_submissioncheck
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quiz {
    /** @var string */
    public const COMPONENT = 'mod_quiz';

    /**
     * Whether checks run for this quiz.
     *
     * @param \cm_info|\stdClass $cm
     * @return bool
     */
    public static function is_active($cm): bool {
        return $cm->modname === 'quiz' && activation::is_switched_on($cm);
    }

    /**
     * Open an attempt the current user may work on.
     *
     * @param int $cmid
     * @param int $attemptid
     * @param bool $mustbeinprogress
     * @return quiz_attempt
     */
    public static function own_attempt(int $cmid, int $attemptid, bool $mustbeinprogress): quiz_attempt {
        global $USER;
        $attemptobj = quiz_attempt::create($attemptid);
        if ((int) $attemptobj->get_cmid() !== $cmid || (int) $attemptobj->get_userid() !== (int) $USER->id) {
            throw new \moodle_exception('notyourattempt', 'quiz');
        }
        if ($mustbeinprogress && $attemptobj->is_finished()) {
            throw new \moodle_exception('attemptalreadyclosed', 'quiz');
        }
        if (!self::is_active($attemptobj->get_cm())) {
            throw new \moodle_exception('checksoff', 'local_submissioncheck');
        }
        return $attemptobj;
    }

    /**
     * Whether a slot holds an essay question that takes attachments.
     *
     * @param quiz_attempt $attemptobj
     * @param int $slot
     * @return bool
     */
    public static function is_attachment_slot(quiz_attempt $attemptobj, int $slot): bool {
        if (!in_array($slot, $attemptobj->get_slots(), false)) {
            return false;
        }
        $question = $attemptobj->get_question_attempt($slot)->get_question(false);
        return $question instanceof \qtype_essay_question && (int) $question->attachments !== 0;
    }

    /**
     * Files the student has attached to a question so far (saved with the attempt).
     *
     * @param quiz_attempt $attemptobj
     * @param int $slot
     * @return \stored_file[]
     */
    public static function saved_files(quiz_attempt $attemptobj, int $slot): array {
        $qa = $attemptobj->get_question_attempt($slot);
        return array_values($qa->get_last_qt_files('attachments', \context_module::instance($attemptobj->get_cmid())->id));
    }

    /**
     * Template data for files the student has just attached, before the page is saved.
     *
     * @param int $cmid
     * @param int $attemptid
     * @param int $slot
     * @param int $draftitemid
     * @return array
     */
    public static function draft_report(int $cmid, int $attemptid, int $slot, int $draftitemid): array {
        global $USER;
        $attemptobj = self::own_attempt($cmid, $attemptid, true);
        if (!self::is_attachment_slot($attemptobj, $slot)) {
            throw new \moodle_exception('invalidparameter', 'debug');
        }
        $fs = get_file_storage();
        $files = [];
        foreach (
            $fs->get_area_files(
                \context_user::instance($USER->id)->id,
                'user',
                'draft',
                $draftitemid,
                'filepath, filename',
                false
            ) as $file
        ) {
            $files[] = ['file' => $file, 'slot' => $slot];
        }
        return self::files_data($attemptobj, $files, []);
    }

    /**
     * Template data for every attachment in the attempt, for the summary page.
     *
     * @param int $cmid
     * @param int $attemptid
     * @return array
     */
    public static function summary_report(int $cmid, int $attemptid): array {
        $attemptobj = self::own_attempt($cmid, $attemptid, true);
        $files = self::attempt_files($attemptobj);
        $data = self::files_data($attemptobj, $files, ['attemptid' => $attemptid]);
        $acknowledged = self::is_acknowledged($attemptobj, $files);
        $nearend = self::near_end($attemptobj);
        $data['uniqid'] = \html_writer::random_id('local-submissioncheck-');
        $data['acknowledged'] = $acknowledged;
        $data['needsack'] = $data['haswarnings'] && !$acknowledged && !$nearend;
        $data['nearend'] = $data['haswarnings'] && $nearend;
        $data['nearendmessage'] = get_string('quiznearend', 'local_submissioncheck');
        $data['armedlabel'] = report_builder::confirm_again_label($data['files'], 'quizconfirmagain');
        return $data;
    }

    /**
     * Record that the student read the warnings about the attempt's attachments.
     *
     * @param int $cmid
     * @param int $attemptid
     */
    public static function acknowledge(int $cmid, int $attemptid): void {
        global $USER, $DB;
        $attemptobj = self::own_attempt($cmid, $attemptid, true);
        $files = self::attempt_files($attemptobj);
        if (!$files) {
            return;
        }
        $record = confirmation::record($cmid, self::COMPONENT, $attemptid, (int) $USER->id, 0, (int) $USER->id, $files);
        confirmation::confirm($record, (int) $USER->id);
    }

    /**
     * When an attempt is finished, record its attachments and whether their warnings were acknowledged.
     *
     * @param \stdClass|null $original
     * @param \stdClass|null $updated
     */
    public static function attempt_state_changed(?\stdClass $original, ?\stdClass $updated): void {
        try {
            if (!$updated || $updated->state !== quiz_attempt::FINISHED || ($original && $original->state === $updated->state)) {
                return;
            }
            $attemptobj = quiz_attempt::create($updated->id);
            if (!self::is_active($attemptobj->get_cm())) {
                return;
            }
            $files = self::attempt_files($attemptobj);
            if (!$files || self::is_acknowledged($attemptobj, $files)) {
                return;
            }
            confirmation::record(
                (int) $attemptobj->get_cmid(),
                self::COMPONENT,
                (int) $updated->id,
                (int) $updated->userid,
                0,
                (int) $updated->userid,
                $files,
                confirmation::AUTOACCEPTED,
                (int) $updated->timefinish ?: time()
            );
        } catch (\Throwable $e) {
            debugging('Submission checks could not record a quiz attempt: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * All attachments in the attempt.
     *
     * @param quiz_attempt $attemptobj
     * @return array [['file' => stored_file, 'slot' => int], ...]
     */
    public static function attempt_files(quiz_attempt $attemptobj): array {
        $files = [];
        foreach ($attemptobj->get_slots() as $slot) {
            if (!self::is_attachment_slot($attemptobj, (int) $slot)) {
                continue;
            }
            foreach (self::saved_files($attemptobj, (int) $slot) as $file) {
                $files[] = ['file' => $file, 'slot' => (int) $slot];
            }
        }
        return $files;
    }

    /**
     * Whether the student acknowledged exactly these files.
     *
     * @param quiz_attempt $attemptobj
     * @param array $files
     * @return bool
     */
    protected static function is_acknowledged(quiz_attempt $attemptobj, array $files): bool {
        global $DB;
        $record = confirmation::current(self::COMPONENT, (int) $attemptobj->get_attemptid());
        if (!$record || (int) $record->state !== confirmation::CONFIRMED) {
            return false;
        }
        $recorded = $DB->get_fieldset_select('local_submissioncheck_file', 'contenthash', 'uploadid = ?', [$record->id]);
        $current = array_map(function ($entry) {
            return $entry['file']->get_contenthash();
        }, $files);
        sort($recorded);
        sort($current);
        return $recorded === $current;
    }

    /**
     * Whether the attempt is close enough to its end that no acknowledgement is asked for.
     *
     * @param quiz_attempt $attemptobj
     * @return bool
     */
    protected static function near_end(quiz_attempt $attemptobj): bool {
        $due = $attemptobj->get_due_date();
        return $due && $due - time() <= confirmation::window();
    }

    /**
     * Template data shared by the attempt and summary pages.
     *
     * @param quiz_attempt $attemptobj
     * @param array $files
     * @param array $urlparams
     * @return array
     */
    protected static function files_data(quiz_attempt $attemptobj, array $files, array $urlparams): array {
        $cm = \cm_info::create($attemptobj->get_cm());
        $built = report_builder::files($files, $urlparams, 'quiz');
        $timelimit = (int) $attemptobj->get_quizobj()->get_quiz()->timelimit;
        return [
            'hasfiles' => !empty($files),
            'files' => $built['files'],
            'haswarnings' => $built['haswarnings'],
            'pending' => $built['pending'],
            'disclaimer' => report_builder::disclaimer(),
            'timelimitnote' => $timelimit ? get_string('quiztimelimitnote', 'local_submissioncheck') : '',
        ];
    }

    /**
     * Whether a stored file belongs to an attempt (an attachment saved in one of its steps).
     *
     * @param \stored_file $file
     * @param \stdClass $attempt quiz_attempts record.
     * @return bool
     */
    public static function file_belongs_to_attempt(\stored_file $file, \stdClass $attempt): bool {
        global $DB;
        if ($file->get_component() !== 'question' || $file->get_filearea() !== 'response_attachments') {
            return false;
        }
        return $DB->record_exists_sql(
            'SELECT 1
               FROM {question_attempt_steps} qas
               JOIN {question_attempts} qa ON qa.id = qas.questionattemptid
              WHERE qas.id = :stepid AND qa.questionusageid = :usageid',
            ['stepid' => $file->get_itemid(), 'usageid' => $attempt->uniqueid]
        );
    }
}
