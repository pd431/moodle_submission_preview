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

use local_submissioncheck\local\html_preview;
use local_submissioncheck\local\inspected_file;
use local_submissioncheck\local\preview;
use local_submissioncheck\local\testing;

/**
 * Which preview a file gets, and the previews made on the server.
 *
 * @package    local_submissioncheck
 * @category   test
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_submissioncheck\local\preview
 * @covers     \local_submissioncheck\local\html_preview
 * @covers     \local_submissioncheck\local\inspected_file
 */
#[\PHPUnit\Framework\Attributes\CoversClass(local\preview::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(local\html_preview::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(local\inspected_file::class)]
final class preview_test extends \advanced_testcase {
    /** @var int Item id of the next stored file. */
    protected $itemid = 0;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        testing::set('converter_down', true);
    }

    /**
     * Store a file.
     *
     * @param string $name
     * @param string $content
     * @return \stored_file
     */
    protected function store(string $name, string $content): \stored_file {
        return get_file_storage()->create_file_from_string([
            'contextid' => \context_system::instance()->id, 'component' => 'local_submissioncheck', 'filearea' => 'test',
            'itemid' => ++$this->itemid, 'filepath' => '/', 'filename' => $name,
        ], $content);
    }

    /**
     * Store a fixture file.
     *
     * @param string $name
     * @return \stored_file
     */
    protected function fixture(string $name): \stored_file {
        global $CFG;
        return $this->store($name, file_get_contents($CFG->dirroot . '/local/submissioncheck/tests/fixtures/' . $name));
    }

    /**
     * File types from their first bytes.
     */
    public function test_media_types_from_head(): void {
        $this->assertSame(inspected_file::TYPE_WEBP, inspected_file::type_from_head("RIFF\x10\x00\x00\x00WEBPVP8 "));
        $this->assertSame(inspected_file::TYPE_WAV, inspected_file::type_from_head("RIFF\x10\x00\x00\x00WAVEfmt "));
        $this->assertSame(inspected_file::TYPE_MP4, inspected_file::type_from_head("\x00\x00\x00\x20ftypisom"));
        $this->assertSame(inspected_file::TYPE_WEBM, inspected_file::type_from_head("\x1A\x45\xDF\xA3\x9F"));
        $this->assertSame(inspected_file::TYPE_OGG, inspected_file::type_from_head('OggS' . "\x00\x02"));
        $this->assertSame(inspected_file::TYPE_MP3, inspected_file::type_from_head("ID3\x04\x00\x00"));
        $this->assertSame(inspected_file::TYPE_MP3, inspected_file::type_from_head("\xFF\xFB\x90\x00"));
        $this->assertSame(inspected_file::TYPE_UNKNOWN, inspected_file::type_from_head('ID3 notes for the essay'));
        $this->assertSame(inspected_file::TYPE_ZIP, inspected_file::type_from_head("PK\x03\x04rest"));
    }

    /**
     * Without a converter, each kind of file gets the plugin's own preview, or says it has none.
     */
    public function test_status_without_converter(): void {
        $cases = [
            'essay.docx' => [preview::READY, preview::KIND_DOCX, false],
            'marks.xlsx' => [preview::READY, preview::KIND_HTML, false],
            'linked-media.pptx' => [preview::READY, preview::KIND_HTML, true],
            'essay.rtf' => [preview::UNAVAILABLE, null, false],
            'notes.pages' => [preview::UNSUPPORTED, null, false],
            'preview.pdf' => [preview::READY, preview::KIND_PDF, false],
        ];
        foreach ($cases as $name => [$status, $kind, $textonly]) {
            $this->assertSame(
                ['status' => $status, 'kind' => $kind, 'textonly' => $textonly],
                preview::get_status($this->fixture($name)),
                $name
            );
        }
        $this->assertSame(preview::KIND_HTML, preview::get_status($this->store('notes.txt', 'Some notes'))['kind']);
        $this->assertSame(preview::KIND_HTML, preview::get_status($this->store('page.html', '<p>Hi</p>'))['kind']);
        $this->assertSame(preview::KIND_VIDEO, preview::get_status($this->store('clip.mp4', "\x00\x00\x00\x20ftypisom"))['kind']);
        $this->assertSame(preview::KIND_AUDIO, preview::get_status($this->store('talk.mp3', "ID3\x04\x00\x00"))['kind']);
        $this->assertSame(
            preview::KIND_IMAGE,
            preview::get_status($this->store('photo.webp', "RIFF\x10\x00\x00\x00WEBPVP8 "))['kind']
        );
        // A Word name on something that is not a package is not drawn as Word.
        $this->assertSame(preview::UNAVAILABLE, preview::get_status($this->store('fake.docx', '<html>'))['status']);
        // A video name on something else is not played.
        $this->assertSame(preview::UNSUPPORTED, preview::get_status($this->store('clip.mp4', 'text'))['status']);
    }

    /**
     * The site's converter still wins when it has a preview.
     */
    public function test_converter_first(): void {
        testing::set('converter_down', false);
        testing::set('test_converter', true);
        $this->assertSame(
            ['status' => preview::READY, 'kind' => preview::KIND_PDF, 'textonly' => false],
            preview::get_status($this->fixture('essay.docx'))
        );
    }

    /**
     * Files over the size limit say so.
     */
    public function test_too_large(): void {
        $big = $this->store('big.csv', str_repeat("a,b\n", (int) (html_preview::MAX_SHEET_BYTES / 4) + 1));
        $this->assertSame(preview::TOO_LARGE, preview::get_status($big)['status']);
        $this->assertNull(html_preview::render($big));
    }

    /**
     * A spreadsheet shows the values it stored, never recalculating (some functions fetch web addresses), and its links
     * keep their text only.
     */
    public function test_sheet(): void {
        global $CFG;
        $path = make_request_directory() . '/marks.xlsx';
        copy($CFG->dirroot . '/local/submissioncheck/tests/fixtures/marks.xlsx', $path);
        $zip = new \ZipArchive();
        $zip->open($path);
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->addFromString('xl/worksheets/sheet1.xml', str_replace('<v>133</v>', '<v>999</v>', $sheet));
        $zip->close();
        $html = html_preview::render($this->store('marks.xlsx', file_get_contents($path)));
        $this->assertStringContainsString('Total', $html);
        $this->assertStringContainsString('999', $html);
        $this->assertStringNotContainsString('133', $html);

        $html = html_preview::render($this->store(
            'links.csv',
            "Name,Link\nAna,=WEBSERVICE(\"http://example.com/\")\n<b>bold</b>,x\n"
        ));
        $this->assertStringContainsString('WEBSERVICE', $html);
        $this->assertStringContainsString('&lt;b&gt;bold&lt;/b&gt;', $html);
        $this->assertDoesNotMatchRegularExpression('/href\s*=\s*["\'](?!#)/i', $html);
    }

    /**
     * Text files are shown as escaped text; presentations as their text, slide by slide.
     */
    public function test_text_and_outline(): void {
        $html = html_preview::render($this->store('notes.txt', "Line <script>alert(1)</script>\n"));
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);

        $html = html_preview::render($this->store('latin.txt', "caf\xE9"));
        $this->assertStringContainsString('café', $html);

        $html = html_preview::render($this->fixture('linked-media.pptx'));
        $this->assertStringContainsString('<h2>Slide 1</h2>', $html);
        $this->assertStringContainsString('Interview findings', $html);

        $this->assertSame('<p>Hi</p>', html_preview::render($this->store('page.html', '<p>Hi</p>')));
        $this->assertNull(html_preview::render($this->store('damaged.pptx', 'not a package')));
    }
}
