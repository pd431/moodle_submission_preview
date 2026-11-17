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

namespace local_submissioncheck\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider: what students submitted, when, and what they said about it.
 *
 * @package    local_submissioncheck
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /** SQL fragment: uploads a user is part of. */
    private const USER_SQL = '(u.userid = :userid1 OR u.uploadedby = :userid2 OR u.resolvedby = :userid3)';

    /**
     * Data stored and sent by the plugin.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_submissioncheck_upload', [
            'userid' => 'privacy:metadata:upload:userid',
            'uploadedby' => 'privacy:metadata:upload:uploadedby',
            'timeuploaded' => 'privacy:metadata:upload:timeuploaded',
            'state' => 'privacy:metadata:upload:state',
            'acknowledged' => 'privacy:metadata:upload:acknowledged',
            'resolvedby' => 'privacy:metadata:upload:resolvedby',
            'timeresolved' => 'privacy:metadata:upload:timeresolved',
        ], 'privacy:metadata:upload');
        $collection->add_database_table('local_submissioncheck_file', [
            'filename' => 'privacy:metadata:file:filename',
            'contenthash' => 'privacy:metadata:file:contenthash',
        ], 'privacy:metadata:file');
        $collection->add_database_table('local_submissioncheck_result', [
            'filename' => 'privacy:metadata:result:filename',
            'findings' => 'privacy:metadata:result:findings',
        ], 'privacy:metadata:result');
        $collection->add_subsystem_link('core_files', [], 'privacy:metadata:core_files');
        $collection->add_user_preference('local_submissioncheck_seen', 'privacy:metadata:preference:seen');
        return $collection;
    }

    /**
     * Contexts with data for a user.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $contextlist->add_from_sql(
            "SELECT ctx.id
               FROM {local_submissioncheck_upload} u
               JOIN {context} ctx ON ctx.instanceid = u.cmid AND ctx.contextlevel = :level
              WHERE " . self::USER_SQL,
            ['level' => CONTEXT_MODULE, 'userid1' => $userid, 'userid2' => $userid, 'userid3' => $userid]
        );
        return $contextlist;
    }

    /**
     * Users with data in a context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if (!$context instanceof \context_module) {
            return;
        }
        foreach (['userid', 'uploadedby', 'resolvedby'] as $field) {
            $userlist->add_from_sql(
                $field,
                "SELECT {$field} FROM {local_submissioncheck_upload} WHERE cmid = :cmid",
                ['cmid' => $context->instanceid]
            );
        }
    }

    /**
     * Export a user's data in the given contexts.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;
        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_module) {
                continue;
            }
            $uploads = $DB->get_records_sql(
                "SELECT u.*
                   FROM {local_submissioncheck_upload} u
                  WHERE u.cmid = :cmid AND " . self::USER_SQL . "
               ORDER BY u.id",
                ['cmid' => $context->instanceid, 'userid1' => $userid, 'userid2' => $userid, 'userid3' => $userid]
            );
            $data = [];
            foreach ($uploads as $upload) {
                $files = $DB->get_fieldset_select('local_submissioncheck_file', 'filename', 'uploadid = ?', [$upload->id]);
                $data[] = [
                    'files' => implode(', ', $files),
                    'timeuploaded' => transform::datetime($upload->timeuploaded),
                    'state' => get_string('privacystate_' . (int) $upload->state, 'local_submissioncheck'),
                    'haswarnings' => transform::yesno($upload->haswarnings),
                    'acknowledged' => transform::yesno($upload->acknowledged),
                    'timeresolved' => $upload->timeresolved ? transform::datetime($upload->timeresolved) : '',
                ];
            }
            if ($data) {
                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'local_submissioncheck')],
                    (object) ['uploads' => $data]
                );
            }
        }
    }

    /**
     * Export a user's preferences.
     *
     * @param int $userid
     */
    public static function export_user_preferences(int $userid) {
        $seen = get_user_preferences('local_submissioncheck_seen', null, $userid);
        if ($seen !== null) {
            writer::export_user_preference(
                'local_submissioncheck',
                'local_submissioncheck_seen',
                $seen,
                get_string('privacy:metadata:preference:seen', 'local_submissioncheck')
            );
        }
    }

    /**
     * Delete everyone's data in a context.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        if ($context instanceof \context_module) {
            \local_submissioncheck\local\confirmation::delete_for_cm((int) $context->instanceid);
        }
    }

    /**
     * Delete a user's data in the given contexts.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_module) {
                self::delete_users((int) $context->instanceid, [$userid]);
            }
        }
    }

    /**
     * Delete the data of several users in a context.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        $context = $userlist->get_context();
        if ($context instanceof \context_module) {
            self::delete_users((int) $context->instanceid, $userlist->get_userids());
        }
    }

    /**
     * Delete the uploads of some users in an activity, and forget them where they only confirmed someone else's.
     *
     * @param int $cmid
     * @param int[] $userids
     */
    protected static function delete_users(int $cmid, array $userids): void {
        global $DB;
        if (!$userids) {
            return;
        }
        [$owner, $ownerparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'own');
        [$uploader, $uploaderparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'upl');
        $ids = $DB->get_fieldset_select(
            'local_submissioncheck_upload',
            'id',
            "cmid = :cmid AND (userid $owner OR (userid = 0 AND groupid = 0 AND uploadedby $uploader))",
            ['cmid' => $cmid] + $ownerparams + $uploaderparams
        );
        if ($ids) {
            [$idsql, $idparams] = $DB->get_in_or_equal($ids);
            $DB->delete_records_select('local_submissioncheck_file', "uploadid $idsql", $idparams);
            $DB->delete_records_select('local_submissioncheck_upload', "id $idsql", $idparams);
        }
        $DB->set_field_select(
            'local_submissioncheck_upload',
            'resolvedby',
            0,
            "cmid = :cmid AND resolvedby $owner",
            ['cmid' => $cmid] + $ownerparams
        );
        $DB->set_field_select(
            'local_submissioncheck_upload',
            'uploadedby',
            0,
            "cmid = :cmid AND uploadedby $owner",
            ['cmid' => $cmid] + $ownerparams
        );
    }
}
