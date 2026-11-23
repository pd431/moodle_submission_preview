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
use local_submissioncheck\local\preview_copy;

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
 * @covers     \local_submissioncheck\local\preview_copy
 */
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
        $this->assertSame(inspected_file::TYPE_MP3, inspected_file::type_from_head("\xFF\xF3\x90\x00"));
        // A UTF-16 text marker, or a Layer I header, is not an MP3.
        $this->assertSame(inspected_file::TYPE_UNKNOWN, inspected_file::type_from_head("\xFF\xFEPK\x03\x04"));
        $this->assertSame(inspected_file::TYPE_UNKNOWN, inspected_file::type_from_head("\xFF\xFF\x90\x00"));
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

    /**
     * A linked picture in a Word document becomes a marker at its place, with its size in pixels; a document without
     * linked items is sent as it is.
     */
    public function test_preview_copy(): void {
        $path = preview_copy::prepare($this->fixture('linked-image.docx'));
        $this->assertNotNull($path);
        $zip = new \ZipArchive();
        $zip->open($path);
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        unlink($path);
        $this->assertStringContainsString('[[submissioncheck-missing|graph1.png|320|180]]', $xml);
        $this->assertStringNotContainsString('r:link=', $xml);
        $this->assertStringContainsString('Respondents rated timely feedback highest.', $xml);

        $this->assertNull(preview_copy::prepare($this->fixture('essay.docx')));
    }

    /**
     * A presentation's linked items are drawn as missing under their slide.
     */
    public function test_outline_marks_missing_items(): void {
        $html = html_preview::render($this->fixture('linked-media.pptx'));
        $slide = strpos($html, '<h2>Slide 1</h2>');
        $box = strpos($html, 'interview.mp4 is not included in this document. A marker will not see it.');
        $this->assertNotFalse($box);
        $this->assertGreaterThan($slide, $box);
        $this->assertStringContainsString('graph1.png is not included in this document. A marker will not see it.', $html);
        $this->assertStringContainsString('data-region="local-submissioncheck-missing"', $html);
    }

    /**
     * A drawing group becomes the pictures in it; text in its text box joins the text; a group with neither becomes a
     * "not shown" marker at its size. A plain document is sent as it is.
     */
    public function test_preview_copy_groups(): void {
        $path = preview_copy::prepare($this->fixture('grouped-pictures.docx'));
        $this->assertNotNull($path);
        $zip = new \ZipArchive();
        $zip->open($path);
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        unlink($path);
        $this->assertStringNotContainsString('<wpg:wgp>', $xml);
        $this->assertSame(1, substr_count($xml, '<a:blip r:embed="rId100"/>'));
        $this->assertStringContainsString('<wp:extent cx="2857500" cy="1428750"/>', $xml);
        $this->assertStringContainsString('<w:br/><w:t xml:space="preserve">Caption inside the group</w:t>', $xml);
        $this->assertStringContainsString('[[submissioncheck-notshown|100|100]]', $xml);
        $doc = new \DOMDocument();
        $this->assertTrue($doc->loadXML($xml));
    }

    /**
     * Alternative content without a drawing is left alone; nested blocks are unwrapped from the inside.
     */
    public function test_preview_copy_alternate_content(): void {
        $kept = '<w:r><mc:AlternateContent><mc:Choice Requires="w14"><w:t>new</w:t></mc:Choice><mc:Fallback><w:t>old</w:t>' .
            '</mc:Fallback></mc:AlternateContent></w:r>';
        $shape = '<w:r><mc:AlternateContent><mc:Choice Requires="wps"><w:drawing><wp:inline><wp:extent cx="952500" cy="476250"/>' .
            '<a:graphic><a:graphicData uri="http://schemas.microsoft.com/office/word/2010/wordprocessingShape"><wps:wsp>' .
            '<wps:txbx><w:txbxContent><w:p><w:r><w:t>Boxed words</w:t></w:r></w:p></w:txbxContent></wps:txbx></wps:wsp>' .
            '</a:graphicData></a:graphic></wp:inline></w:drawing></mc:Choice><mc:Fallback><w:pict/></mc:Fallback>' .
            '</mc:AlternateContent></w:r>';
        $out = preview_copy::flatten('<w:body><w:p>' . $kept . $shape . '</w:p></w:body>');
        $nested = preview_copy::flatten('<w:body><w:p><w:r><mc:AlternateContent><mc:Choice Requires="wps">' . $kept .
            '<w:drawing><wp:inline><wp:extent cx="9525" cy="9525"/><a:graphic><a:graphicData uri="x"/></a:graphic></wp:inline>' .
            '</w:drawing></mc:Choice><mc:Fallback/></mc:AlternateContent></w:r></w:p></w:body>');
        $this->assertStringContainsString($kept . '<w:t xml:space="preserve">[[submissioncheck-notshown|1|1]]</w:t>', $nested);
        $this->assertStringContainsString($kept, $out);
        $this->assertStringContainsString('<w:br/><w:t xml:space="preserve">Boxed words</w:t>', $out);
        $this->assertStringNotContainsString('<w:pict/>', $out);
        $this->assertSame(
            '<w:body><w:p>No drawings</w:p></w:body>',
            preview_copy::flatten('<w:body><w:p>No drawings</w:p></w:body>')
        );
    }

    /**
     * An online video on sign-in storage becomes a marker at its place with its name, service and size; one on
     * YouTube is left as it is.
     */
    public function test_preview_copy_online_video(): void {
        $path = preview_copy::prepare($this->fixture('sharepoint-video.docx'));
        $this->assertNotNull($path);
        $zip = new \ZipArchive();
        $zip->open($path);
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        unlink($path);
        $this->assertStringContainsString('[[submissioncheck-cloud|untitled.mp4|OneDrive|400|225]]', $xml);
        $this->assertStringNotContainsString('webVideoPr', $xml);
        $this->assertNull(preview_copy::prepare($this->fixture('youtube-video.docx')));
    }

    /**
     * Open a prepared copy and return its parts.
     *
     * @param string $path
     * @param string[] $names
     * @return array name => content
     */
    protected function parts(string $path, array $names): array {
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path, \ZipArchive::CHECKCONS));
        $out = [];
        foreach ($names as $name) {
            $out[$name] = $zip->getFromName($name);
        }
        $zip->close();
        unlink($path);
        return $out;
    }

    /**
     * The converter's copy of a Word document has an embedded placeholder picture in place of the linked one, with the
     * missing-item sentence as alternative text, and no link left to the replaced item.
     */
    public function test_converter_copy_word(): void {
        $path = preview_copy::prepare_for_converter($this->fixture('linked-image.docx'));
        $this->assertNotNull($path);
        $parts = $this->parts($path, ['word/document.xml', 'word/_rels/document.xml.rels',
            'word/media/' . preview_copy::PLACEHOLDER_NAME, '[Content_Types].xml']);
        $this->assertSame(1, substr_count($parts['word/document.xml'], 'r:embed="' . preview_copy::PLACEHOLDER_ID . '"'));
        $this->assertStringContainsString(
            'descr="graph1.png is not included in this document. A marker will not see it."',
            $parts['word/document.xml']
        );
        $this->assertStringNotContainsString('r:link=', $parts['word/document.xml']);
        $this->assertStringContainsString('Id="' . preview_copy::PLACEHOLDER_ID . '"', $parts['word/_rels/document.xml.rels']);
        $this->assertStringNotContainsString('graph1.png', $parts['word/_rels/document.xml.rels']);
        $this->assertNotEmpty($parts['word/media/' . preview_copy::PLACEHOLDER_NAME]);
        $this->assertStringContainsString('Extension="png"', $parts['[Content_Types].xml']);
        $this->assertNull(preview_copy::prepare_for_converter($this->fixture('essay.docx')));
    }

    /**
     * The converter's copy of a presentation has the linked picture replaced at its place and size.
     */
    public function test_converter_copy_slides(): void {
        $path = preview_copy::prepare_for_converter($this->fixture('linked-media.pptx'));
        $this->assertNotNull($path);
        $parts = $this->parts($path, ['ppt/slides/slide1.xml', 'ppt/slides/_rels/slide1.xml.rels',
            'ppt/media/' . preview_copy::PLACEHOLDER_NAME]);
        $slide = $parts['ppt/slides/slide1.xml'];
        $this->assertSame(1, substr_count($slide, 'r:embed="' . preview_copy::PLACEHOLDER_ID . '"'));
        $this->assertStringContainsString('<a:ext cx="3048000" cy="1714500"/>', $slide);
        $this->assertStringNotContainsString('r:link="rId2"', $slide);
        $this->assertStringNotContainsString('Id="rId2"', $parts['ppt/slides/_rels/slide1.xml.rels']);
        $this->assertNotEmpty($parts['ppt/media/' . preview_copy::PLACEHOLDER_NAME]);
        $doc = new \DOMDocument();
        $this->assertTrue($doc->loadXML($slide));
    }

    /**
     * Converting hands the stand-in the copy, keeps the student's file unchanged, and deletes the temporary copy after
     * success and after failure; when the copy cannot be made, the file itself is handed over.
     */
    public function test_convert_hands_over_the_copy(): void {
        testing::set('converter_down', false);
        testing::set('test_converter', true);
        $file = $this->fixture('linked-image.docx');
        $hash = $file->get_contenthash();
        preview::convert($file);
        $handed = preview::stored($file, preview::AREA_HANDED);
        $this->assertNotNull($handed);
        $this->assertNotSame($hash, $handed->get_contenthash());
        $this->assertSame($hash, get_file_storage()->get_file_by_id($file->get_id())->get_contenthash());
        $this->assertNull(preview::stored($file, preview::AREA_COPY));

        testing::set('converter_fails', true);
        try {
            preview::convert($file);
            $this->fail('The stand-in converter should have failed.');
        } catch (\coding_exception $e) {
            $this->assertNull(preview::stored($file, preview::AREA_COPY));
        }
        testing::set('converter_fails', false);

        testing::set('copy_fails', true);
        preview::convert($file);
        $this->assertDebuggingCalled();
        $this->assertSame($hash, preview::stored($file, preview::AREA_HANDED)->get_contenthash());

        $clean = $this->fixture('essay.docx');
        testing::set('copy_fails', false);
        preview::convert($clean);
        $this->assertSame($clean->get_contenthash(), preview::stored($clean, preview::AREA_HANDED)->get_contenthash());
    }
}
