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
 * Check results for files, run once per content and name and then read from the cache.
 *
 * @package    local_submissioncheck
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class result_store {
    /** Seconds the checks for one file may take before the rest are skipped. */
    public const BUDGET_SECONDS = 2.0;

    /**
     * Results for one file, running any check whose result is not cached yet.
     *
     * @param \stored_file $file
     * @return file_result
     */
    public static function get(\stored_file $file): file_result {
        global $DB;

        if (testing::service_down()) {
            return new file_result($file->get_filename(), [], false);
        }

        $checks = check_registry::get_checks();
        $cached = $DB->get_records('local_submissioncheck_result', [
            'contenthash' => $file->get_contenthash(),
            'filename' => $file->get_filename(),
        ]);
        $bykey = [];
        foreach ($cached as $row) {
            $bykey[$row->checkname . '/' . $row->checkversion] = $row;
        }

        $findings = [];
        $complete = true;
        $inspected = null;
        $started = microtime(true);
        try {
            foreach ($checks as $check) {
                $key = $check->get_name() . '/' . $check->get_version();
                if (isset($bykey[$key])) {
                    $found = array_map([finding::class, 'from_array'], (array) json_decode($bykey[$key]->findings, true));
                } else {
                    if (microtime(true) - $started > self::BUDGET_SECONDS) {
                        $complete = false;
                        break;
                    }
                    if ($inspected === null) {
                        $inspected = inspected_file::from_stored_file($file);
                    }
                    $found = $check->applies($inspected) ? self::run_check($check, $inspected) : [];
                    if ($found === null) {
                        $complete = false;
                        continue;
                    }
                    self::save($file, $check, $found);
                }
                $findings = array_merge($findings, $found);
                if ($found && $check->stops_later_checks()) {
                    break;
                }
            }
        } catch (\Throwable $e) {
            debugging('Submission checks could not read ' . $file->get_filename() . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            $complete = false;
        }
        unset($inspected);
        return new file_result($file->get_filename(), $findings, $complete);
    }

    /**
     * Run one check, turning a failure into "no result" rather than an error for the student.
     *
     * @param check $check
     * @param inspected_file $file
     * @return finding[]|null Null when the check failed.
     */
    protected static function run_check(check $check, inspected_file $file): ?array {
        try {
            return $check->run($file);
        } catch (\Throwable $e) {
            debugging('Submission check ' . $check->get_name() . ' failed on ' . $file->get_filename() . ': ' .
                $e->getMessage(), DEBUG_DEVELOPER);
            return null;
        }
    }

    /**
     * Cache a check's findings for this content and name.
     *
     * @param \stored_file $file
     * @param check $check
     * @param finding[] $found
     */
    protected static function save(\stored_file $file, check $check, array $found): void {
        global $DB;
        $record = (object) [
            'contenthash' => $file->get_contenthash(),
            'filename' => $file->get_filename(),
            'checkname' => $check->get_name(),
            'checkversion' => $check->get_version(),
            'findings' => json_encode(array_map(function (finding $f) {
                return $f->to_array();
            }, $found)),
            'timecreated' => time(),
        ];
        try {
            $DB->insert_record('local_submissioncheck_result', $record);
        } catch (\dml_exception $e) {
            // Another request cached the same result first; theirs is as good as ours.
            debugging('Submission check result not cached: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Remove cached results no current upload refers to.
     *
     * @param int $olderthan Only results cached before this time.
     */
    public static function purge_unused(int $olderthan): void {
        global $DB;
        $DB->execute(
            "DELETE FROM {local_submissioncheck_result}
              WHERE timecreated < :olderthan
                AND NOT EXISTS (SELECT 1 FROM {local_submissioncheck_file} f
                                 WHERE f.contenthash = {local_submissioncheck_result}.contenthash)",
            ['olderthan' => $olderthan]
        );
    }
}
