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

use local_submissioncheck\local\inspected_file;
use local_submissioncheck\local\package_text;

/**
 * The text of Office and OpenDocument packages.
 *
 * @package    local_submissioncheck
 * @category   test
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_submissioncheck\local\package_text
 */
#[\PHPUnit\Framework\Attributes\CoversClass(local\package_text::class)]
final class package_text_test extends \advanced_testcase {
    /** Content types part every Office package has. */
    public const TYPES = '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>';

    /**
     * Open a zip package with given entries for inspection.
     *
     * @param array $entries name => content
     * @param string $name
     * @return inspected_file
     */
    protected function package(array $entries, string $name): inspected_file {
        $path = make_request_directory() . '/' . $name;
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        foreach ($entries as $entry => $content) {
            $zip->addFromString($entry, $content);
        }
        $zip->close();
        return new inspected_file($path, $name);
    }

    /**
     * A Word document: one section; tabs and breaks kept; deleted text, field codes and fallback copies left out; a text
     * box is its own paragraph.
     */
    public function test_word(): void {
        $doc = '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" ' .
            'xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006"><w:body>' .
            '<w:p><w:r><w:t>Title</w:t></w:r></w:p>' .
            '<w:p><w:r><w:t>One</w:t><w:tab/><w:t>two</w:t><w:br/><w:t>three</w:t></w:r>' .
            '<w:del><w:r><w:delText>gone</w:delText></w:r></w:del><w:r><w:instrText>PAGE</w:instrText></w:r>' .
            '<w:r><mc:AlternateContent><mc:Choice><w:drawing><w:txbxContent><w:p><w:r><w:t>Box</w:t></w:r></w:p>' .
            '</w:txbxContent></w:drawing></mc:Choice><mc:Fallback><w:pict><w:txbxContent><w:p><w:r><w:t>Box</w:t>' .
            '</w:r></w:p></w:txbxContent></w:pict></mc:Fallback></mc:AlternateContent></w:r></w:p>' .
            '<w:p/></w:body></w:document>';
        $file = $this->package(['[Content_Types].xml' => self::TYPES, 'word/document.xml' => $doc], 'essay.docx');
        $this->assertSame(package_text::KIND_WORD, package_text::kind($file));
        $this->assertSame(
            [['slide' => null, 'paragraphs' => ['Title', "One two\nthree", 'Box']]],
            package_text::sections($file, 10)
        );
    }

    /**
     * A presentation: one section per slide, in slide order, up to the limit.
     */
    public function test_slides(): void {
        $slide = function (string $text): string {
            return '<p:sld xmlns:p="p" xmlns:a="a"><p:cSld><p:spTree><p:sp><p:txBody><a:p><a:r><a:t>' . $text .
                '</a:t></a:r></a:p></p:txBody></p:sp></p:spTree></p:cSld></p:sld>';
        };
        $entries = ['[Content_Types].xml' => self::TYPES];
        foreach ([10, 2, 1] as $n) {
            $entries["ppt/slides/slide$n.xml"] = $slide("Slide text $n");
        }
        $file = $this->package($entries, 'talk.pptx');
        $this->assertSame(package_text::KIND_SLIDES, package_text::kind($file));
        $this->assertSame([
            ['slide' => 1, 'paragraphs' => ['Slide text 1']],
            ['slide' => 2, 'paragraphs' => ['Slide text 2']],
        ], package_text::sections($file, 2));
    }

    /**
     * OpenDocument text has headings and spans; a presentation has pages.
     */
    public function test_opendocument(): void {
        $text = '<office:document-content xmlns:office="o" xmlns:text="t"><office:body><office:text>' .
            '<text:h>Heading</text:h><text:p>Some <text:span>styled</text:span><text:s/>text<text:line-break/>more</text:p>' .
            '</office:text></office:body></office:document-content>';
        $file = $this->package(['mimetype' => 'application/vnd.oasis.opendocument.text', 'content.xml' => $text], 'a.odt');
        $this->assertSame(
            [['slide' => null, 'paragraphs' => ['Heading', "Some styled text\nmore"]]],
            package_text::sections($file, 10)
        );

        $slides = '<office:document-content xmlns:office="o" xmlns:text="t" xmlns:draw="d"><office:body>' .
            '<office:presentation><draw:page><draw:frame><draw:text-box><text:p>First</text:p></draw:text-box></draw:frame>' .
            '</draw:page><draw:page/></office:presentation></office:body></office:document-content>';
        $file = $this->package(
            ['mimetype' => 'application/vnd.oasis.opendocument.presentation', 'content.xml' => $slides],
            'a.odp'
        );
        $this->assertSame(
            [['slide' => 1, 'paragraphs' => ['First']], ['slide' => 2, 'paragraphs' => []]],
            package_text::sections($file, 10)
        );
    }

    /**
     * Files that are not text packages, and damaged parts, give no text rather than an error.
     */
    public function test_not_a_text_package(): void {
        $file = $this->package(['[Content_Types].xml' => self::TYPES, 'xl/worksheets/sheet1.xml' => '<x/>'], 'a.xlsx');
        $this->assertNull(package_text::kind($file));
        $this->assertNull(package_text::sections($file, 10));

        $file = $this->package(['[Content_Types].xml' => self::TYPES, 'word/document.xml' => '<w:document'], 'a.docx');
        $this->assertNull(package_text::sections($file, 10));

        $file = $this->package(['[Content_Types].xml' => self::TYPES, 'word/document.xml' =>
            '<!DOCTYPE d [<!ENTITY e SYSTEM "file:///etc/passwd">]><d><p><t>&e;</t></p></d>'], 'b.docx');
        $this->assertSame([['slide' => null, 'paragraphs' => []]], package_text::sections($file, 10));
    }
}
