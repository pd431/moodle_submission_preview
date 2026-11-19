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

namespace local_submissioncheck\task;

use local_submissioncheck\local\adapter\assign as assign_adapter;
use local_submissioncheck\local\confirmation;
use local_submissioncheck\output\report_builder;

/**
 * Remind students who left the check report without answering, with a link back to it.
 *
 * @package    local_submissioncheck
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class remind_unconfirmed extends \core\task\scheduled_task {
    /**
     * Name of this task.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_remind', 'local_submissioncheck');
    }

    /**
     * Run the task.
     */
    public function execute() {
        global $DB;
        $sent = 0;
        foreach (confirmation::due_reminders(time()) as $record) {
            confirmation::mark_reminded($record);
            if ($record->component !== assign_adapter::COMPONENT) {
                continue;
            }
            $cm = get_coursemodule_from_id('assign', $record->cmid, 0, false, IGNORE_MISSING);
            if (!$cm) {
                continue;
            }
            $userids = $record->userid ? [(int) $record->userid] : array_keys(groups_get_members($record->groupid, 'u.id'));
            foreach ($userids as $userid) {
                $user = \core_user::get_user($userid);
                if (!$user || !\core_user::is_real_user($userid)) {
                    continue;
                }
                $this->send($user, $cm, $record);
                $sent++;
            }
        }
        mtrace("Submission checks: {$sent} reminder(s) sent.");
    }

    /**
     * Send one reminder.
     *
     * @param \stdClass $user
     * @param \stdClass $cm
     * @param \stdClass $record
     */
    protected function send(\stdClass $user, \stdClass $cm, \stdClass $record): void {
        $context = \context_module::instance($cm->id);
        $activity = format_string($cm->name, true, ['context' => $context]);
        \core\cron::setup_user($user);
        $a = (object) [
            'activity' => $activity,
            'time' => report_builder::time((int) $record->timeuploaded),
            'deadline' => report_builder::time(confirmation::deadline($record)),
        ];
        \core\cron::setup_user();

        $message = new \core\message\message();
        $message->component = 'local_submissioncheck';
        $message->name = 'unconfirmed';
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $user;
        $message->courseid = $cm->course;
        $message->subject = get_string('remindersubject', 'local_submissioncheck', $activity);
        $message->fullmessage = get_string('reminderbody', 'local_submissioncheck', $a);
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = '';
        $message->smallmessage = $message->subject;
        $message->notification = 1;
        $message->contexturl = assign_adapter::review_url($cm->id)->out(false);
        $message->contexturlname = get_string('reminderlink', 'local_submissioncheck');
        message_send($message);
    }
}
