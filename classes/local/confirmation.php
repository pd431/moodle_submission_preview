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
 * The confirmation window after a submission: who submitted what and when, and whether the
 * student confirmed it, went back, or let it be accepted automatically.
 *
 * The window never changes the submission or its time. It only records what the student was
 * shown and what they said about it.
 *
 * @package    local_submissioncheck
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class confirmation {
    /** @var int Waiting for the student. */
    public const AWAITING = 0;
    /** @var int The student confirmed it. */
    public const CONFIRMED = 1;
    /** @var int The window ended without an answer. */
    public const AUTOACCEPTED = 2;
    /** @var int A newer upload replaced it. */
    public const REPLACED = 3;

    /** Seconds between arming an answer that has problems and confirming it. */
    public const ARM_SECONDS = 1.0;

    /**
     * Arm the answer for an upload that has problems: the student pressed "Yes" once.
     *
     * @param \stdClass $record
     */
    public static function arm(\stdClass $record): void {
        global $SESSION;
        $SESSION->local_submissioncheck_armed = [(int) $record->id => microtime(true)];
    }

    /**
     * Whether an answer may be confirmed now: always without problems; with problems, only on a second press
     * (acknowledged) at least ARM_SECONDS after the first.
     *
     * @param \stdClass $record
     * @param bool $acknowledged The second press says the student accepts the problems.
     * @return bool
     */
    public static function is_armed(\stdClass $record, bool $acknowledged): bool {
        global $SESSION;
        if (empty($record->haswarnings)) {
            return true;
        }
        $armed = $SESSION->local_submissioncheck_armed[(int) $record->id] ?? null;
        return $acknowledged && $armed !== null && microtime(true) - $armed >= self::ARM_SECONDS;
    }

    /**
     * Whether the answer for an upload is armed now (pressed once, waiting for the second press).
     *
     * @param \stdClass $record
     * @return bool
     */
    public static function is_armed_now(\stdClass $record): bool {
        global $SESSION;
        return isset($SESSION->local_submissioncheck_armed[(int) $record->id]);
    }

    /**
     * Length of the window in seconds.
     *
     * @return int
     */
    public static function window(): int {
        $minutes = (int) get_config('local_submissioncheck', 'windowminutes');
        return max(1, $minutes ?: 15) * MINSECS;
    }

    /**
     * Seconds after upload before an unconfirmed student is reminded.
     *
     * @return int
     */
    public static function reminder_delay(): int {
        $minutes = (int) get_config('local_submissioncheck', 'reminderminutes');
        return max(1, $minutes ?: 3) * MINSECS;
    }

    /**
     * Record a new upload, replacing any earlier one for the same submission still awaiting an answer.
     *
     * @param int $cmid
     * @param string $component mod_assign or mod_quiz
     * @param int $itemid Assign submission id or quiz attempt id.
     * @param int $userid Owner of the submission, 0 for a group submission.
     * @param int $groupid
     * @param int $uploadedby
     * @param array $files [['file' => stored_file, 'slot' => int], ...]
     * @param int $state Initial state.
     * @param int|null $time Upload time, now when null.
     * @return \stdClass The upload record.
     */
    public static function record(
        int $cmid,
        string $component,
        int $itemid,
        int $userid,
        int $groupid,
        int $uploadedby,
        array $files,
        int $state = self::AWAITING,
        ?int $time = null
    ): \stdClass {
        global $DB;
        $time = $time ?? time();
        $haswarnings = false;
        foreach ($files as $entry) {
            if (result_store::get($entry['file'])->has_warnings()) {
                $haswarnings = true;
            }
        }

        $transaction = $DB->start_delegated_transaction();
        $DB->set_field_select(
            'local_submissioncheck_upload',
            'state',
            self::REPLACED,
            'component = :component AND itemid = :itemid AND state = :awaiting',
            ['component' => $component, 'itemid' => $itemid, 'awaiting' => self::AWAITING]
        );
        $record = (object) [
            'cmid' => $cmid,
            'component' => $component,
            'itemid' => $itemid,
            'userid' => $userid,
            'groupid' => $groupid,
            'uploadedby' => $uploadedby,
            'timeuploaded' => $time,
            'state' => $state,
            'haswarnings' => $haswarnings ? 1 : 0,
            'acknowledged' => 0,
            'resolvedby' => 0,
            'timeresolved' => 0,
            'reminded' => 0,
        ];
        $record->id = $DB->insert_record('local_submissioncheck_upload', $record);
        foreach ($files as $entry) {
            $DB->insert_record('local_submissioncheck_file', (object) [
                'uploadid' => $record->id,
                'fileid' => $entry['file']->get_id(),
                'contenthash' => $entry['file']->get_contenthash(),
                'filename' => $entry['file']->get_filename(),
                'slot' => (int) ($entry['slot'] ?? 0),
            ]);
        }
        $transaction->allow_commit();
        return $record;
    }

    /**
     * The latest upload for a submission, with an expired window settled first.
     *
     * @param string $component
     * @param int $itemid
     * @return \stdClass|null
     */
    public static function current(string $component, int $itemid): ?\stdClass {
        global $DB;
        $records = $DB->get_records(
            'local_submissioncheck_upload',
            ['component' => $component, 'itemid' => $itemid],
            'id DESC',
            '*',
            0,
            1
        );
        $record = $records ? reset($records) : null;
        if ($record && (int) $record->state === self::AWAITING && time() >= self::deadline($record)) {
            self::autoaccept($record);
        }
        return $record;
    }

    /**
     * When the window for an upload ends.
     *
     * @param \stdClass $record
     * @return int
     */
    public static function deadline(\stdClass $record): int {
        return (int) $record->timeuploaded + self::window();
    }

    /**
     * Whether the student can still answer.
     *
     * @param \stdClass $record
     * @return bool
     */
    public static function is_open(\stdClass $record): bool {
        return (int) $record->state === self::AWAITING && time() < self::deadline($record);
    }

    /**
     * The student confirms the upload. Warnings on screen are then acknowledged.
     *
     * @param \stdClass $record Updated in place.
     * @param int $userid Who confirmed.
     * @return bool False when the window had already ended.
     */
    public static function confirm(\stdClass $record, int $userid): bool {
        global $DB;
        if (!self::is_open($record)) {
            if ((int) $record->state === self::AWAITING) {
                self::autoaccept($record);
            }
            return false;
        }
        $record->state = self::CONFIRMED;
        $record->acknowledged = (int) $record->haswarnings;
        $record->resolvedby = $userid;
        $record->timeresolved = time();
        $changed = $DB->execute('UPDATE {local_submissioncheck_upload}
                                    SET state = :state, acknowledged = :ack, resolvedby = :by, timeresolved = :t
                                  WHERE id = :id AND state = :awaiting', [
            'state' => self::CONFIRMED, 'ack' => $record->acknowledged, 'by' => $userid, 't' => $record->timeresolved,
            'id' => $record->id, 'awaiting' => self::AWAITING,
        ]);
        self::trigger('upload_confirmed', $record, $userid);
        return (bool) $changed;
    }

    /**
     * The window ended without an answer: accept it, with any warnings not acknowledged.
     *
     * @param \stdClass $record Updated in place.
     */
    public static function autoaccept(\stdClass $record): void {
        global $DB;
        $record->state = self::AUTOACCEPTED;
        $record->acknowledged = 0;
        $record->timeresolved = self::deadline($record);
        $DB->execute('UPDATE {local_submissioncheck_upload} SET state = :state, timeresolved = :t
                       WHERE id = :id AND state = :awaiting', [
            'state' => self::AUTOACCEPTED, 't' => $record->timeresolved, 'id' => $record->id, 'awaiting' => self::AWAITING,
        ]);
    }

    /**
     * Settle every window that has ended.
     *
     * @param int $now
     * @return int How many were accepted.
     */
    public static function autoaccept_expired(int $now): int {
        global $DB;
        $records = $DB->get_records_select(
            'local_submissioncheck_upload',
            'state = :awaiting AND timeuploaded <= :cutoff',
            ['awaiting' => self::AWAITING, 'cutoff' => $now - self::window()]
        );
        foreach ($records as $record) {
            self::autoaccept($record);
        }
        return count($records);
    }

    /**
     * Uploads still awaiting an answer whose student has not been reminded yet.
     *
     * @param int $now
     * @return \stdClass[]
     */
    public static function due_reminders(int $now): array {
        global $DB;
        return $DB->get_records_select(
            'local_submissioncheck_upload',
            'state = :awaiting AND reminded = 0 AND timeuploaded <= :remindafter AND timeuploaded > :expired',
            ['awaiting' => self::AWAITING, 'remindafter' => $now - self::reminder_delay(), 'expired' => $now - self::window()]
        );
    }

    /**
     * Mark an upload's student as reminded.
     *
     * @param \stdClass $record
     */
    public static function mark_reminded(\stdClass $record): void {
        global $DB;
        $DB->set_field('local_submissioncheck_upload', 'reminded', 1, ['id' => $record->id]);
    }

    /**
     * The files of an upload that still exist, with their slots.
     *
     * @param \stdClass $record
     * @return array [['file' => stored_file, 'slot' => int, 'filename' => string], ...]
     */
    public static function files(\stdClass $record): array {
        global $DB;
        $fs = get_file_storage();
        $files = [];
        foreach ($DB->get_records('local_submissioncheck_file', ['uploadid' => $record->id], 'slot, id') as $row) {
            $file = $fs->get_file_by_id($row->fileid);
            if ($file && !$file->is_directory()) {
                $files[] = ['file' => $file, 'slot' => (int) $row->slot, 'filename' => $row->filename];
            }
        }
        return $files;
    }

    /**
     * Log a confirmation for audit.
     *
     * @param string $eventname
     * @param \stdClass $record
     * @param int $userid
     */
    protected static function trigger(string $eventname, \stdClass $record, int $userid): void {
        $class = '\\local_submissioncheck\\event\\' . $eventname;
        $event = $class::create([
            'objectid' => $record->id,
            'context' => \context_module::instance($record->cmid),
            'relateduserid' => $record->userid ?: null,
            'other' => ['component' => $record->component, 'itemid' => $record->itemid,
                'acknowledged' => (int) $record->acknowledged, 'timeuploaded' => (int) $record->timeuploaded],
        ]);
        $event->trigger();
    }

    /**
     * Remove everything recorded for an activity.
     *
     * @param int $cmid
     */
    public static function delete_for_cm(int $cmid): void {
        global $DB;
        $ids = $DB->get_fieldset_select('local_submissioncheck_upload', 'id', 'cmid = ?', [$cmid]);
        if ($ids) {
            [$insql, $params] = $DB->get_in_or_equal($ids);
            $DB->delete_records_select('local_submissioncheck_file', "uploadid $insql", $params);
            $DB->delete_records_select('local_submissioncheck_upload', "id $insql", $params);
        }
    }
}
