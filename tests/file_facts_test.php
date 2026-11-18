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

use local_submissioncheck\local\file_facts;
use local_submissioncheck\local\inspected_file;

/**
 * Facts read from submitted files.
 *
 * @package    local_submissioncheck
 * @category   test
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_submissioncheck\local\file_facts
 */
#[\PHPUnit\Framework\Attributes\CoversClass(local\file_facts::class)]
final class file_facts_test extends \advanced_testcase {
    /**
     * Facts of a fixture as label => value.
     *
     * @param string $name
     * @return array
     */
    protected function facts(string $name): array {
        global $CFG;
        $facts = file_facts::read(new inspected_file($CFG->dirroot . '/local/submissioncheck/tests/fixtures/' . $name, $name));
        return array_column($facts, 'value', 'label');
    }

    /**
     * Word: kind, pages as Word saved them, words counted from the text, last saved, size.
     */
    public function test_word(): void {
        $facts = $this->facts('essay.docx');
        $this->assertSame('Word document', $facts['Kind']);
        $this->assertSame('1', $facts['Pages']);
        $this->assertSame('30', $facts['Words']);
        $this->assertArrayHasKey('Last saved', $facts);
        $this->assertArrayHasKey('File size', $facts);
        $this->assertArrayNotHasKey('Pictures and media', $facts);
        $this->assertSame('1 linked, not included', $this->facts('linked-image.docx')['Pictures and media']);
        $this->assertSame('1 online video (youtube.com)', $this->facts('youtube-video.docx')['Online videos']);
        $this->assertSame('1 online video (OneDrive)', $this->facts('sharepoint-video.docx')['Online videos']);
        // A picture inside a drawing group counts once, as embedded.
        $this->assertSame('1 embedded', $this->facts('grouped-pictures.docx')['Pictures and media']);
    }

    /**
     * PowerPoint: slides and linked media.
     */
    public function test_presentation(): void {
        $facts = $this->facts('linked-media.pptx');
        $this->assertSame('PowerPoint presentation', $facts['Kind']);
        $this->assertSame('1', $facts['Slides']);
        $this->assertStringContainsString('2 linked, not included', $facts['Pictures and media']);
    }

    /**
     * Excel: sheet names, formulas (singular for one).
     */
    public function test_workbook(): void {
        $facts = $this->facts('marks.xlsx');
        $this->assertSame('Excel workbook', $facts['Kind']);
        $this->assertSame('Marks', $facts['Sheets']);
        $this->assertSame('1 formula', $facts['Contains']);
    }

    /**
     * PDF pages; other files still get their kind and size; facts are cached per content.
     */
    public function test_other_kinds_and_cache(): void {
        $this->resetAfterTest();
        $this->assertSame('PDF', $this->facts('preview.pdf')['Kind']);
        $this->assertSame('1', $this->facts('preview.pdf')['Pages']);
        $this->assertSame('RTF document', $this->facts('essay.rtf')['Kind']);
        $this->assertArrayNotHasKey('Kind', $this->facts('notes.pages'));

        $file = get_file_storage()->create_file_from_string([
            'contextid' => \context_system::instance()->id, 'component' => 'local_submissioncheck', 'filearea' => 'test',
            'itemid' => 1, 'filepath' => '/', 'filename' => 'notes.txt',
        ], 'Three short words');
        $facts = array_column(file_facts::get($file), 'value', 'label');
        $this->assertSame('Text file', $facts['Kind']);
        $this->assertSame('3', $facts['Words']);
        $this->assertSame(file_facts::get($file), file_facts::get($file));
    }
}
