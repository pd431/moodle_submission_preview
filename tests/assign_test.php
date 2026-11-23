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

namespace local_submissioncheck;

use local_submissioncheck\local\activation;
use local_submissioncheck\local\adapter\assign as assign_adapter;
use local_submissioncheck\local\confirmation;
use local_submissioncheck\local\testing;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/locallib.php');

/**
 * Assignments: turning checks on, recording uploads, the confirmation window and the report.
 *
 * @package    local_submissioncheck
 * @category   test
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_submissioncheck\local\activation
 * @covers     \local_submissioncheck\local\adapter\assign
 * @covers     \local_submissioncheck\local\confirmation
 * @covers     \local_submissioncheck\local\result_store
 */
#[\PHPUnit\Framework\Attributes\CoversClass(local\activation::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(local\adapter\assign::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(local\confirmation::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(local\result_store::class)]
final class assign_test extends \advanced_testcase {
    /** @var \stdClass */
    protected $course;

    /** @var \stdClass */
    protected $student;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        testing::set('test_converter', true);
        $this->course = $this->getDataGenerator()->create_course(['shortname' => 'RS101']);
        $this->student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
    }

    /**
     * An assignment with file submissions.
     *
     * @param array $params
     * @return \cm_info
     */
    protected function make_assign(array $params = []): \cm_info {
        $assign = $this->getDataGenerator()->create_module('assign', $params + [
            'course' => $this->course->id,
            'name' => 'Essay 1',
            'assignsubmission_file_enabled' => 1,
            'assignsubmission_file_maxfiles' => 3,
            'assignsubmission_file_maxsizebytes' => 1024 * 1024,
            'submissiondrafts' => 0,
            'submissioncheck_mode' => 'on',
            'duedate' => time() + WEEKSECS,
        ]);
        return get_fast_modinfo($this->course)->get_cm($assign->cmid);
    }

    /**
     * Submit fixture files as a user through the assignment.
     *
     * @param \cm_info $cm
     * @param \stdClass $user
     * @param string[] $fixtures
     */
    protected function submit(\cm_info $cm, \stdClass $user, array $fixtures): void {
        global $CFG;
        $this->setUser($user);
        $draftid = file_get_unused_draft_itemid();
        foreach ($fixtures as $fixture) {
            get_file_storage()->create_file_from_pathname([
                'contextid' => \context_user::instance($user->id)->id, 'component' => 'user', 'filearea' => 'draft',
                'itemid' => $draftid, 'filepath' => '/', 'filename' => $fixture,
            ], $CFG->dirroot . '/local/submissioncheck/tests/fixtures/' . $fixture);
        }
        $notices = [];
        $this->assertTrue(assign_adapter::load($cm)->save_submission((object) ['files_filemanager' => $draftid,
            'userid' => $user->id], $notices), implode(' ', $notices));
    }

    /**
     * The current upload record for a user.
     *
     * @param \cm_info $cm
     * @param \stdClass $user
     * @return \stdClass|null
     */
    protected function current(\cm_info $cm, \stdClass $user): ?\stdClass {
        $submission = assign_adapter::get_submission(assign_adapter::load($cm), (int) $user->id);
        return $submission ? confirmation::current('mod_assign', (int) $submission->id) : null;
    }

    public function test_modes_and_tags(): void {
        set_config('defaulttags', 'Summative, formative', 'local_submissioncheck');
        $this->assertTrue(activation::resolve('auto', ['summative']));
        $this->assertTrue(activation::resolve('auto', ['FORMATIVE', 'other']));
        $this->assertFalse(activation::resolve('auto', ['other']));
        $this->assertFalse(activation::resolve('off', ['summative']));
        $this->assertTrue(activation::resolve('on', []));

        $cm = $this->make_assign(['submissioncheck_mode' => 'auto']);
        $this->assertFalse(activation::is_switched_on($cm));
        \core_tag_tag::set_item_tags('core', 'course_modules', $cm->id, $cm->context, ['summative']);
        $this->assertTrue(activation::is_switched_on($cm));
        $this->assertSame('Checks are on because this activity is tagged summative', activation::describe($cm->id));
    }

    public function test_drafts_on_means_checks_do_not_run(): void {
        $cm = $this->make_assign(['submissiondrafts' => 1]);
        $this->assertFalse(assign_adapter::is_active($cm));
        $this->submit($cm, $this->student, ['essay.docx']);
        $this->assertNull($this->current($cm, $this->student));
    }

    public function test_validation_refuses_drafts(): void {
        $errors = local_submissioncheck_coursemodule_validation(null, ['modulename' => 'assign', 'submissioncheck_mode' => 'on',
            'assignsubmission_file_enabled' => 1, 'submissiondrafts' => 1]);
        $this->assertArrayHasKey('submissioncheck_mode', $errors);
        $errors = local_submissioncheck_coursemodule_validation(null, ['modulename' => 'assign', 'submissioncheck_mode' => 'on',
            'assignsubmission_file_enabled' => 1, 'submissiondrafts' => 0]);
        $this->assertSame([], $errors);
        set_config('defaulttags', 'summative', 'local_submissioncheck');
        $errors = local_submissioncheck_coursemodule_validation(null, ['modulename' => 'assign', 'submissioncheck_mode' => 'auto',
            'tags' => ['summative'], 'assignsubmission_file_enabled' => 1, 'submissiondrafts' => 1]);
        $this->assertArrayHasKey('submissioncheck_mode', $errors);
    }

    public function test_upload_is_recorded_and_checked(): void {
        $cm = $this->make_assign();
        $this->submit($cm, $this->student, ['linked-media.pptx']);
        $record = $this->current($cm, $this->student);
        $this->assertNotNull($record);
        $this->assertEquals(confirmation::AWAITING, $record->state);
        $this->assertEquals(1, $record->haswarnings);
        $this->assertEquals($this->student->id, $record->userid);

        $report = assign_adapter::report($cm, (int) $this->student->id);
        $this->assertTrue($report['awaiting']);
        $this->assertStringContainsString('unless you replace the file.', $report['submitted']);
        $this->assertStringStartsWith('Confirm by ', $report['confirmby']);
        $this->assertStringContainsString('<strong>Essay 1</strong>', $report['headinghtml']);
        $this->assertSame(
            [
                ['graph1.png', 'Not in the file: on your computer', 'slide 1'],
                ['interview.mp4', 'Not in the file: on your computer', 'slide 1'],
            ],
            array_map(fn($i) => [$i['name'], $i['reason'], $i['place']], $report['files'][0]['brokenshown'])
        );
        $this->assertTrue($report['files'][0]['previewready']);
        $this->assertSame('Previews are approximate.', $report['disclaimer']);
        $this->assertFalse($report['continuing']);
        // Seen once: coming back shows the banner.
        $this->assertTrue(assign_adapter::report($cm, (int) $this->student->id)['continuing']);
    }

    public function test_confirm_and_resubmit(): void {
        $cm = $this->make_assign();
        $this->submit($cm, $this->student, ['linked-media.pptx']);
        $record = $this->current($cm, $this->student);
        $this->assertTrue(confirmation::confirm($record, (int) $this->student->id));
        $record = $this->current($cm, $this->student);
        $this->assertEquals(confirmation::CONFIRMED, $record->state);
        $this->assertEquals(1, $record->acknowledged);
        $report = assign_adapter::report($cm, (int) $this->student->id);
        $this->assertNull($report['actions']);
        $this->assertSame('You confirmed this file and read the warnings about it.', $report['statusmessage']);

        // Confirming does not lock: a new file starts a new window.
        $this->submit($cm, $this->student, ['essay.docx']);
        $new = $this->current($cm, $this->student);
        $this->assertNotEquals($record->id, $new->id);
        $this->assertEquals(confirmation::AWAITING, $new->state);
        $this->assertEquals(0, $new->haswarnings);
    }

    public function test_window_ends_with_autoaccept(): void {
        global $DB;
        $cm = $this->make_assign();
        $this->submit($cm, $this->student, ['empty.docx']);
        $record = $this->current($cm, $this->student);
        $DB->set_field('local_submissioncheck_upload', 'timeuploaded', time() - 16 * MINSECS, ['id' => $record->id]);
        $this->assertSame(1, confirmation::autoaccept_expired(time()));
        $record = $DB->get_record('local_submissioncheck_upload', ['id' => $record->id]);
        $this->assertEquals(confirmation::AUTOACCEPTED, $record->state);
        $this->assertEquals(0, $record->acknowledged);
        $this->assertFalse(confirmation::confirm($record, (int) $this->student->id));
        $report = assign_adapter::report($cm, (int) $this->student->id);
        $this->assertSame(
            'Accepted automatically after 15 minutes. The warnings below were not acknowledged.',
            $report['statusmessage']
        );
    }

    public function test_expired_window_settles_on_read(): void {
        global $DB;
        $cm = $this->make_assign();
        $this->submit($cm, $this->student, ['essay.docx']);
        $record = $this->current($cm, $this->student);
        $DB->set_field('local_submissioncheck_upload', 'timeuploaded', time() - 20 * MINSECS, ['id' => $record->id]);
        $this->assertEquals(confirmation::AUTOACCEPTED, $this->current($cm, $this->student)->state);
    }

    public function test_reminders(): void {
        global $DB;
        $cm = $this->make_assign();
        $this->submit($cm, $this->student, ['essay.docx']);
        $record = $this->current($cm, $this->student);
        $this->assertCount(0, confirmation::due_reminders(time()));
        $DB->set_field('local_submissioncheck_upload', 'timeuploaded', time() - 4 * MINSECS, ['id' => $record->id]);
        $this->assertCount(1, confirmation::due_reminders(time()));

        $sink = $this->redirectMessages();
        $this->setAdminUser();
        (new task\remind_unconfirmed())->execute();
        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);
        $this->assertSame('Check your submission to Essay 1', $messages[0]->subject);
        $this->assertEquals($this->student->id, $messages[0]->useridto);
        $this->assertCount(0, confirmation::due_reminders(time()));
        $this->expectOutputRegex('/1 reminder/');
    }

    public function test_replace_state(): void {
        $cm = $this->make_assign(['duedate' => time() - HOURSECS]);
        $this->submit($cm, $this->student, ['essay.docx']);
        $state = assign_adapter::replace_state(assign_adapter::load($cm), (int) $this->student->id);
        $this->assertTrue($state['can']);
        $this->assertTrue($state['late']);

        $cm = $this->make_assign(['duedate' => time() - 2 * HOURSECS, 'cutoffdate' => time() - HOURSECS, 'name' => 'Essay 2']);
        $state = assign_adapter::replace_state(assign_adapter::load($cm), (int) $this->student->id);
        $this->assertFalse($state['can']);
        $this->assertStringContainsString('cut-off date has passed', $state['reason']);
    }

    public function test_results_are_cached(): void {
        global $DB;
        $cm = $this->make_assign();
        $this->submit($cm, $this->student, ['essay.docx']);
        $count = $DB->count_records('local_submissioncheck_result');
        $this->assertGreaterThan(0, $count);
        $other = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->submit($cm, $other, ['essay.docx']);
        $this->assertSame($count, $DB->count_records('local_submissioncheck_result'));
    }

    public function test_failure_never_blocks_the_submission(): void {
        testing::set('service_down', true);
        testing::set('converter_down', true);
        $cm = $this->make_assign();
        $this->submit($cm, $this->student, ['essay.rtf', 'essay.docx']);
        $report = assign_adapter::report($cm, (int) $this->student->id);
        $files = array_column($report['files'], null, 'name');
        $this->assertTrue($files['essay.rtf']['checksunavailable']);
        $this->assertTrue($files['essay.rtf']['framenotype']);
        // Without the converter, a Word document is still drawn in the browser.
        $this->assertTrue($files['essay.docx']['previewdocx']);
    }

    public function test_group_submission(): void {
        $group = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $other = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $this->student->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $other->id]);
        $cm = $this->make_assign(['teamsubmission' => 1]);
        $this->submit($cm, $this->student, ['linked-media.pptx']);
        $record = $this->current($cm, $other);
        $this->assertNotNull($record);
        $this->assertTrue(assign_adapter::can_act($record, (int) $other->id));
        $this->assertTrue(confirmation::confirm($record, (int) $other->id));
        $report = assign_adapter::report($cm, (int) $this->student->id);
        $this->assertSame(fullname($other) . ' confirmed this file and read the warnings about it.', $report['statusmessage']);
    }

    public function test_duplicate_keeps_setting(): void {
        $cm = $this->make_assign(['submissioncheck_mode' => 'off']);
        $this->setAdminUser();
        $actions = \core_courseformat\formatactions::cm($this->course);
        $newcm = method_exists($actions, 'duplicate') ? $actions->duplicate((int) $cm->id) : duplicate_module($this->course, $cm);
        $this->assertSame('off', activation::get_mode((int) $newcm->id));
    }

    public function test_delete_module_cleans_up(): void {
        global $DB;
        $cm = $this->make_assign();
        $this->submit($cm, $this->student, ['essay.docx']);
        $this->setAdminUser();
        $actions = \core_courseformat\formatactions::cm($this->course);
        if (method_exists($actions, 'delete')) {
            $actions->delete((int) $cm->id);
        } else {
            course_delete_module($cm->id);
        }
        $this->assertFalse($DB->record_exists('local_submissioncheck_upload', ['cmid' => $cm->id]));
        $this->assertFalse($DB->record_exists('local_submissioncheck_cfg', ['cmid' => $cm->id]));
    }

    /**
     * The review page: a tab per file with its warning count, plural wording for several files, the confirm-by time
     * and the viewed count.
     */
    public function test_review_page(): void {
        $cm = $this->make_assign();
        $this->submit($cm, $this->student, ['essay.docx', 'linked-media.pptx']);
        $data = \local_submissioncheck\output\review_page::data($cm, (int) $this->student->id);
        $this->assertTrue($data['multiple']);
        $files = array_column($data['files'], null, 'name');
        $this->assertSame('', $files['essay.docx']['tabstatus']);
        $this->assertSame('2 broken', $files['linked-media.pptx']['tabstatus']);
        $this->assertTrue($data['files'][0]['first']);
        $this->assertSame('Viewed 1 of 2 files', $data['viewed']);
        $this->assertSame('Yes, these are the files I intended to submit', $data['actions']['confirmlabel']);
        $this->assertStringStartsWith('Confirm by ', $data['confirmby']);
        $this->assertStringContainsString('unless you replace the files.', $data['submitted']);
        $this->assertNotEmpty($files['essay.docx']['facts']);
    }

    /**
     * Saving files sends the student to the review page once, for that upload only.
     */
    public function test_review_flag(): void {
        $cm = $this->make_assign();
        $this->submit($cm, $this->student, ['essay.docx']);
        $this->assertTrue(assign_adapter::take_review_flag($cm, (int) $this->student->id));
        $this->assertFalse(assign_adapter::take_review_flag($cm, (int) $this->student->id));
    }

    /**
     * After answering, wording follows the number of files.
     */
    public function test_plural_outcome(): void {
        $cm = $this->make_assign();
        $this->submit($cm, $this->student, ['essay.docx', 'marks.xlsx']);
        $record = $this->current($cm, $this->student);
        \local_submissioncheck\local\confirmation::confirm($record, (int) $this->student->id);
        $data = assign_adapter::report($cm, (int) $this->student->id);
        $this->assertSame('You confirmed these files.', $data['statusmessage']);
        $this->assertNull($data['actions']);
    }

    /**
     * With problems, confirming needs a second press at least a second after the first: a single or a too-quick
     * second press does not confirm. Without problems, one press is enough.
     */
    public function test_confirm_asks_twice_with_problems(): void {
        global $SESSION;
        $cm = $this->make_assign();
        $this->submit($cm, $this->student, ['linked-media.pptx']);
        $record = $this->current($cm, $this->student);
        $this->assertFalse(confirmation::is_armed($record, true));
        confirmation::arm($record);
        $this->assertTrue(confirmation::is_armed_now($record));
        $this->assertFalse(confirmation::is_armed($record, true));
        $SESSION->local_submissioncheck_armed[(int) $record->id] -= 1.5;
        $this->assertFalse(confirmation::is_armed($record, false));
        $this->assertTrue(confirmation::is_armed($record, true));

        $data = \local_submissioncheck\output\review_page::data($cm, (int) $this->student->id, true);
        $this->assertTrue($data['actions']['armed']);
        $this->assertSame('Click again to confirm with 2 broken items', $data['armedlabel']);

        $clean = $this->make_assign(['name' => 'Essay 2']);
        $this->submit($clean, $this->student, ['essay.docx']);
        $this->assertTrue(confirmation::is_armed($this->current($clean, $this->student), false));
    }

    /**
     * The follow-tags choice says what the tags give: on with the matching tag, off with none, or no tags set up.
     */
    public function test_follow_label(): void {
        $cm = $this->make_assign();
        set_config('defaulttags', 'summative, formative', 'local_submissioncheck');
        $this->assertSame('Follow tags (off: no matching tag)', activation::follow_label($cm->id));
        \core_tag_tag::set_item_tags('core', 'course_modules', $cm->id, \context_module::instance($cm->id), ['summative']);
        $this->assertSame('Follow tags (on: tagged summative)', activation::follow_label($cm->id));
        $this->assertSame('Follow tags (off: no matching tag)', activation::follow_label(0));
        set_config('defaulttags', '', 'local_submissioncheck');
        $this->assertSame('Follow tags (off: no tags set up)', activation::follow_label($cm->id));
    }
}
