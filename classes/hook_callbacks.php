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
use local_submissioncheck\local\adapter\quiz as quiz_adapter;

/**
 * Places the check report on activity pages and follows quiz attempts.
 *
 * @package    local_submissioncheck
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {
    /**
     * Add the report (and, for staff, a link to the results) to assignment and quiz pages.
     *
     * The report is printed after the main region and moved to the top of it by JavaScript, so it
     * is on the page even without JavaScript. Any failure leaves the page as Moodle made it.
     *
     * @param \core\hook\output\after_standard_main_region_html_generation $hook
     */
    public static function after_main_region(\core\hook\output\after_standard_main_region_html_generation $hook): void {
        global $PAGE, $USER, $OUTPUT;
        try {
            if (during_initial_install() || !isloggedin() || isguestuser()) {
                return;
            }
            // The page's cm is a magic property, so empty() would always see it as missing.
            $cm = $PAGE->cm;
            if (!$cm) {
                return;
            }
            if (!in_array($cm->modname, activation::MODULES, true) || !activation::is_switched_on($cm)) {
                return;
            }
            $html = '';
            switch ($PAGE->pagetype) {
                case 'mod-assign-view':
                    $action = optional_param('action', '', PARAM_ALPHA);
                    if (in_array($action, ['', 'view'], true)) {
                        $data = assign_adapter::report($cm, (int) $USER->id);
                        if ($data) {
                            $html .= $OUTPUT->render_from_template('local_submissioncheck/assign_report', $data);
                        }
                        $html .= self::staff_notes($cm);
                    }
                    break;
                case 'mod-quiz-view':
                    $html .= self::staff_notes($cm);
                    break;
                case 'mod-quiz-attempt':
                case 'mod-quiz-summary':
                    $attemptid = optional_param('attempt', 0, PARAM_INT);
                    if ($attemptid && quiz_adapter::is_active($cm)) {
                        $PAGE->requires->js_call_amd('local_submissioncheck/quiz', 'init', [
                            (int) $cm->id, $attemptid, $PAGE->pagetype === 'mod-quiz-summary',
                        ]);
                    }
                    break;
            }
            if ($html !== '') {
                // Printed after the main region so it is there without JavaScript; the script moves it to the top.
                $id = \html_writer::random_id('local-submissioncheck-');
                $hook->add_html(\html_writer::div($html, 'local-submissioncheck-page', ['id' => $id]));
                $PAGE->requires->js_call_amd('local_submissioncheck/report', 'init', [$id]);
            }
        } catch (\Throwable $e) {
            debugging('Submission checks could not add to the page: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * For staff: the link to results, and why checks are not running if the activity is not set up for them.
     *
     * @param \cm_info $cm
     * @return string
     */
    protected static function staff_notes(\cm_info $cm): string {
        $html = '';
        if (has_capability('local/submissioncheck:viewresults', $cm->context)) {
            $url = new \moodle_url('/local/submissioncheck/report.php', ['id' => $cm->id]);
            $html .= \html_writer::tag(
                'p',
                \html_writer::link($url, get_string('resultslink', 'local_submissioncheck')),
                ['class' => 'local-submissioncheck-staff']
            );
        }
        if (
            $cm->modname === 'assign' && has_capability('moodle/course:manageactivities', $cm->context)
                && !assign_adapter::meets_requirements(assign_adapter::load($cm))
        ) {
            $html .= \html_writer::tag(
                'p',
                get_string('notrunning_assign', 'local_submissioncheck'),
                ['class' => 'alert alert-warning']
            );
        }
        return $html;
    }

    /**
     * Record the files of a quiz attempt when it is finished.
     *
     * @param \mod_quiz\hook\attempt_state_changed $hook
     */
    public static function quiz_attempt_state_changed(\mod_quiz\hook\attempt_state_changed $hook): void {
        quiz_adapter::attempt_state_changed($hook->get_original_attempt(), $hook->get_updated_attempt());
    }
}
