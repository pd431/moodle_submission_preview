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

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\tests\provider_testcase;
use local_submissioncheck\local\adapter\assign as assign_adapter;
use local_submissioncheck\local\testing;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/locallib.php');

/**
 * Privacy: finding, exporting and deleting what is recorded about students' uploads.
 *
 * @package    local_submissioncheck
 * @category   test
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_submissioncheck\privacy\provider
 */
final class provider_test extends provider_testcase {
    /** @var \stdClass */
    protected $course;

    /** @var \cm_info */
    protected $cm;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        testing::set('test_converter', true);
        $this->course = $this->getDataGenerator()->create_course();
        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $this->course->id,
            'assignsubmission_file_enabled' => 1,
            'assignsubmission_file_maxfiles' => 1,
            'assignsubmission_file_maxsizebytes' => 1024 * 1024,
            'submissiondrafts' => 0,
            'submissioncheck_mode' => 'on',
        ]);
        $this->cm = get_fast_modinfo($this->course)->get_cm($assign->cmid);
    }

    /**
     * Submit a fixture as a user.
     *
     * @param \stdClass $user
     * @param string $fixture
     */
    protected function submit(\stdClass $user, string $fixture): void {
        global $CFG;
        $this->setUser($user);
        $draftid = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_pathname([
            'contextid' => \context_user::instance($user->id)->id, 'component' => 'user', 'filearea' => 'draft',
            'itemid' => $draftid, 'filepath' => '/', 'filename' => $fixture,
        ], $CFG->dirroot . '/local/submissioncheck/tests/fixtures/' . $fixture);
        $notices = [];
        assign_adapter::load($this->cm)->save_submission(
            (object) ['files_filemanager' => $draftid, 'userid' => $user->id],
            $notices
        );
    }

    public function test_metadata(): void {
        $collection = provider::get_metadata(new \core_privacy\local\metadata\collection('local_submissioncheck'));
        $this->assertNotEmpty($collection->get_collection());
    }

    public function test_contexts_users_export_and_delete(): void {
        global $DB;
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $other = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->submit($student, 'linked-image.docx');
        $this->submit($other, 'essay.docx');
        $context = \context_module::instance($this->cm->id);

        $this->assertEquals([$context->id], provider::get_contexts_for_userid((int) $student->id)->get_contextids());

        $userlist = new userlist($context, 'local_submissioncheck');
        provider::get_users_in_context($userlist);
        $this->assertEqualsCanonicalizing([$student->id, $other->id], $userlist->get_userids());

        $this->export_context_data_for_user((int) $student->id, $context, 'local_submissioncheck');
        $data = writer::with_context($context)->get_data([get_string('pluginname', 'local_submissioncheck')]);
        $this->assertSame('linked-image.docx', $data->uploads[0]['files']);
        $this->assertSame(get_string('yes'), $data->uploads[0]['haswarnings']);

        provider::delete_data_for_user(new approved_contextlist($student, 'local_submissioncheck', [$context->id]));
        $this->assertFalse($DB->record_exists('local_submissioncheck_upload', ['userid' => $student->id]));
        $this->assertTrue($DB->record_exists('local_submissioncheck_upload', ['userid' => $other->id]));

        provider::delete_data_for_users(new approved_userlist($context, 'local_submissioncheck', [$other->id]));
        $this->assertFalse($DB->record_exists('local_submissioncheck_upload', ['cmid' => $this->cm->id]));
        $this->assertFalse($DB->record_exists('local_submissioncheck_file', []));
    }

    public function test_delete_all_in_context(): void {
        global $DB;
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->submit($student, 'essay.docx');
        provider::delete_data_for_all_users_in_context(\context_module::instance($this->cm->id));
        $this->assertFalse($DB->record_exists('local_submissioncheck_upload', ['cmid' => $this->cm->id]));
    }

    public function test_someone_who_only_confirmed_is_forgotten_but_the_upload_stays(): void {
        global $DB;
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $helper = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->submit($student, 'essay.docx');
        $DB->set_field('local_submissioncheck_upload', 'resolvedby', $helper->id, ['userid' => $student->id]);
        $context = \context_module::instance($this->cm->id);
        provider::delete_data_for_user(new approved_contextlist($helper, 'local_submissioncheck', [$context->id]));
        $this->assertEquals(0, $DB->get_field('local_submissioncheck_upload', 'resolvedby', ['userid' => $student->id]));
    }
}
