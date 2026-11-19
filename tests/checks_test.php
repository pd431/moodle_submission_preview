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

use local_submissioncheck\local\check_registry;
use local_submissioncheck\local\inspected_file;

/**
 * The checks, on real and synthetic files.
 *
 * @package    local_submissioncheck
 * @category   test
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_submissioncheck\local\checks\placeholder
 * @covers     \local_submissioncheck\local\checks\opens
 * @covers     \local_submissioncheck\local\checks\extension
 * @covers     \local_submissioncheck\local\checks\encrypted
 * @covers     \local_submissioncheck\local\checks\linked_media
 * @covers     \local_submissioncheck\local\checks\empty_text
 * @covers     \local_submissioncheck\local\inspected_file
 */
#[\PHPUnit\Framework\Attributes\CoversClass(local\checks\placeholder::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(local\checks\opens::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(local\checks\extension::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(local\checks\encrypted::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(local\checks\linked_media::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(local\checks\empty_text::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(local\inspected_file::class)]
final class checks_test extends \advanced_testcase {
    /** @var string[] Temporary files to remove. */
    protected $temp = [];

    protected function tearDown(): void {
        foreach ($this->temp as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    /**
     * Run every check the way the result store does, returning finding codes and listed items.
     *
     * @param string $path
     * @param string $name
     * @return array [codes, items]
     */
    protected function run_checks(string $path, string $name): array {
        $file = new inspected_file($path, $name);
        $codes = [];
        $items = [];
        foreach (check_registry::get_checks() as $check) {
            $found = $check->applies($file) ? $check->run($file) : [];
            foreach ($found as $finding) {
                $codes[] = $finding->code;
                $items = array_merge($items, $finding->items);
            }
            if ($found && $check->stops_later_checks()) {
                break;
            }
        }
        return [$codes, $items];
    }

    /**
     * A temporary file with given content.
     *
     * @param string $content
     * @return string
     */
    protected function temp(string $content): string {
        $path = make_request_directory() . '/f';
        file_put_contents($path, $content);
        $this->temp[] = $path;
        return $path;
    }

    /**
     * A zip package with given entries.
     *
     * @param array $entries name => content
     * @return string
     */
    protected function package(array $entries): string {
        $path = make_request_directory() . '/p.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        $this->temp[] = $path;
        return $path;
    }

    /**
     * The fixture files and what each should report.
     *
     * @return array
     */
    public static function fixture_provider(): array {
        return [
            'clean essay' => ['essay.docx', [], []],
            'linked image in Word' => ['linked-image.docx', ['linkedmedia'], ['graph1.png']],
            'linked image and video in PowerPoint' => ['linked-media.pptx', ['linkedmedia'], ['graph1.png', 'interview.mp4']],
            'cut-short upload' => ['truncated.docx', ['cannotopen'], []],
            'Word lock file' => ['~$essay.docx', ['lockfile'], []],
            'Google Docs shortcut' => ['essay.gdoc', ['googleshortcut'], []],
            'web page named .docx' => ['renamed.docx', ['wrongextension'], []],
            'password protected' => ['protected.docx', ['passwordprotected'], []],
            'blank document' => ['empty.docx', ['notext'], []],
            'Pages document' => ['notes.pages', [], []],
            'clean PDF' => ['preview.pdf', [], []],
        ];
    }

    /**
     * Each fixture reports what a student needs to know, and nothing else.
     *
     * @dataProvider fixture_provider
     * @param string $fixture
     * @param string[] $codes
     * @param string[] $items
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('fixture_provider')]
    public function test_fixtures(string $fixture, array $codes, array $items): void {
        [$foundcodes, $founditems] = $this->run_checks(__DIR__ . '/fixtures/' . $fixture, $fixture);
        $this->assertSame($codes, $foundcodes);
        $this->assertSame($items, $founditems);
    }

    /**
     * Files judged by content, not by size alone.
     */
    public function test_small_files(): void {
        $this->assertSame(['zerobytes'], $this->run_checks($this->temp(''), 'essay.docx')[0]);
        $this->assertSame(
            ['windowsshortcut'],
            $this->run_checks($this->temp("L\x00\x00\x00\x01\x14\x02\x00" . str_repeat("\x00", 60)), 'essay.docx.lnk')[0]
        );
        $this->assertSame(['webshortcut'], $this->run_checks(
            $this->temp("[InternetShortcut]\nURL=https://example.com\n"),
            'essay.url'
        )[0]);
        // A short genuine text file is not flagged for being small.
        $this->assertSame([], $this->run_checks($this->temp('Word count 3.'), 'notes.txt')[0]);
        // A small file whose name starts like a lock file but is a real document is judged as a document.
        $this->assertSame([], $this->run_checks(__DIR__ . '/fixtures/essay.docx', '~$draft.docx')[0]);
    }

    /**
     * PDFs: complete, cut short, encrypted.
     */
    public function test_pdf(): void {
        $pdf = file_get_contents(__DIR__ . '/fixtures/preview.pdf');
        $this->assertSame([], $this->run_checks($this->temp($pdf), 'report.pdf')[0]);
        $this->assertSame(['cannotopen'], $this->run_checks(
            $this->temp(substr($pdf, 0, (int) (strlen($pdf) / 2))),
            'report.pdf'
        )[0]);
        $encrypted = str_replace('trailer', "trailer\n<< /Encrypt 9 0 R >>\n", $pdf);
        $this->assertSame(['passwordprotected'], $this->run_checks($this->temp($encrypted), 'report.pdf')[0]);
        $this->assertSame(['wrongextension'], $this->run_checks($this->temp($pdf), 'report.docx')[0]);
    }

    /**
     * Images: end markers.
     */
    public function test_images(): void {
        $png = "\x89PNG\r\n\x1A\n" . str_repeat("\x00", 20) . "\x00\x00\x00\x00IEND\xAE\x42\x60\x82";
        $this->assertSame([], $this->run_checks($this->temp($png), 'graph.png')[0]);
        $this->assertSame(['cannotopen'], $this->run_checks($this->temp(substr($png, 0, 20)), 'graph.png')[0]);
        $jpeg = "\xFF\xD8\xFF\xE0" . str_repeat("\x10", 100) . "\xFF\xD9";
        $this->assertSame([], $this->run_checks($this->temp($jpeg), 'photo.jpg')[0]);
        $this->assertSame(['cannotopen'], $this->run_checks($this->temp(substr($jpeg, 0, 50)), 'photo.jpg')[0]);
    }

    /**
     * OpenDocument: links outside the package, and empty text.
     */
    public function test_opendocument(): void {
        $content = '<?xml version="1.0"?><office:document-content xmlns:office="o" xmlns:text="t" xmlns:draw="d" ' .
            'xmlns:xlink="x"><office:body><office:text><text:p>Results</text:p>' .
            '<draw:frame><draw:image xlink:href="../Videos/clip.mp4"/></draw:frame>' .
            '<draw:frame><draw:image xlink:href="Pictures/embedded.png"/></draw:frame>' .
            '</office:text></office:body></office:document-content>';
        $path = $this->package(['mimetype' => 'application/vnd.oasis.opendocument.text', 'content.xml' => $content]);
        $this->assertSame([['linkedmedia'], ['clip.mp4']], $this->run_checks($path, 'essay.odt'));

        $blank = '<?xml version="1.0"?><office:document-content xmlns:office="o" xmlns:text="t"><office:body>' .
            '<office:text><text:p/></office:text></office:body></office:document-content>';
        $path = $this->package(['mimetype' => 'application/vnd.oasis.opendocument.text', 'content.xml' => $blank]);
        $this->assertSame(['notext'], $this->run_checks($path, 'essay.odt')[0]);
    }

    /**
     * Web links in Office files are not linked media; a missing main part is damage.
     */
    public function test_office_edge_cases(): void {
        $types = '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>';
        $doc = '<w:document xmlns:w="w"><w:body><w:p><w:r><w:t>Hello world</w:t></w:r></w:p></w:body></w:document>';
        $rels = '<?xml version="1.0"?><Relationships xmlns="r">' .
            '<Relationship Id="r1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/video" ' .
            'Target="https://www.youtube.com/watch?v=x" TargetMode="External"/>' .
            '<Relationship Id="r2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" ' .
            'Target="file:///C:/notes.txt" TargetMode="External"/></Relationships>';
        $path = $this->package(['[Content_Types].xml' => $types, 'word/document.xml' => $doc,
            'word/_rels/document.xml.rels' => $rels]);
        $this->assertSame([], $this->run_checks($path, 'essay.docx')[0]);

        $path = $this->package(['[Content_Types].xml' => $types, 'word/styles.xml' => '<w:styles/>']);
        $this->assertSame(['cannotopen'], $this->run_checks($path, 'essay.docx')[0]);
    }

    /**
     * Entity tricks in a package are not expanded.
     */
    public function test_xml_entities_not_expanded(): void {
        $types = '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>';
        $doc = '<w:document xmlns:w="w"><w:body><w:p><w:r><w:t>Hello</w:t></w:r></w:p></w:body></w:document>';
        $rels = '<?xml version="1.0"?><!DOCTYPE r [<!ENTITY x SYSTEM "file:///etc/passwd">]>' .
            '<Relationships xmlns="r"><Relationship Id="r1" Type="a/image" Target="&x;" TargetMode="External"/>' .
            '</Relationships>';
        $path = $this->package(['[Content_Types].xml' => $types, 'word/document.xml' => $doc,
            'word/_rels/document.xml.rels' => $rels]);
        [, $items] = $this->run_checks($path, 'essay.docx');
        foreach ($items as $item) {
            $this->assertStringNotContainsString('root:', $item);
        }
    }

    /**
     * Linked items carry the slide they are on, shown next to the name; the message is unchanged.
     */
    public function test_linked_media_slides(): void {
        global $CFG;
        $path = $CFG->dirroot . '/local/submissioncheck/tests/fixtures/linked-media.pptx';
        $findings = (new local\checks\linked_media())->run(new inspected_file($path, 'linked-media.pptx'));
        $this->assertCount(1, $findings);
        $this->assertSame(['graph1.png' => 1, 'interview.mp4' => 1], $findings[0]->params['slides']);
        $this->assertSame(['graph1.png (slide 1)', 'interview.mp4 (slide 1)'], $findings[0]->item_labels());
        $this->assertStringStartsWith('The following files have been linked', $findings[0]->get_message());

        $content = '<office:document-content xmlns:office="o" xmlns:draw="d" xmlns:xlink="x"><office:body>' .
            '<office:presentation><draw:page/><draw:page><draw:frame><draw:image xlink:href="../clip.mp4"/></draw:frame>' .
            '</draw:page></office:presentation></office:body></office:document-content>';
        $path = $this->package(['mimetype' => 'application/vnd.oasis.opendocument.presentation', 'content.xml' => $content]);
        $this->assertSame(
            [['name' => 'clip.mp4', 'slide' => 2]],
            local\checks\linked_media::locate(new inspected_file($path, 'talk.odp'))
        );

        $path = $CFG->dirroot . '/local/submissioncheck/tests/fixtures/linked-image.docx';
        $this->assertSame(
            [['name' => 'graph1.png', 'slide' => null]],
            local\checks\linked_media::locate(new inspected_file($path, 'linked-image.docx'))
        );
    }

    /**
     * Sign-in storage: the setting's hosts and their sub-sites match; known services get a plain name.
     */
    public function test_signin_hosts(): void {
        $this->resetAfterTest();
        $media = local\checks\linked_media::class;
        $this->assertTrue($media::is_signin_host('universityofexeteruk-my.sharepoint.com'));
        $this->assertTrue($media::is_signin_host('sharepoint.com'));
        $this->assertFalse($media::is_signin_host('notsharepoint.com'));
        $this->assertFalse($media::is_signin_host('www.youtube.com'));
        $this->assertSame('OneDrive', $media::service_name('example-my.sharepoint.com'));
        $this->assertSame('SharePoint', $media::service_name('example.sharepoint.com'));
        $this->assertSame('OneDrive', $media::service_name('1drv.ms'));
        $this->assertSame('Google Drive', $media::service_name('docs.google.com'));
        $this->assertSame('iCloud', $media::service_name('www.icloud.com'));
        $this->assertSame('files.example.org', $media::service_name('files.example.org'));

        set_config('signinhosts', "dropbox.com\nhttps://www.files.example.org/", 'local_submissioncheck');
        $this->assertFalse($media::is_signin_host('example-my.sharepoint.com'));
        $this->assertTrue($media::is_signin_host('www.dropbox.com'));
        $this->assertTrue($media::is_signin_host('files.example.org'));
    }

    /**
     * An online video on OneDrive is a warning named by its description; one on YouTube is not; a presentation's
     * linked video on OneDrive is a warning with its slide.
     */
    public function test_online_video_findings(): void {
        global $CFG;
        $this->resetAfterTest();
        $dir = $CFG->dirroot . '/local/submissioncheck/tests/fixtures/';
        $check = new local\checks\linked_media();

        $findings = $check->run(new inspected_file($dir . 'sharepoint-video.docx', 'sharepoint-video.docx'));
        $this->assertSame(['cloudmedia'], array_column(array_map(fn($f) => $f->to_array(), $findings), 'code'));
        $this->assertSame(
            ['untitled.mp4 is not included in this document. It is stored on OneDrive. A marker will not see it.'],
            $findings[0]->item_labels()
        );

        $this->assertSame([], $check->run(new inspected_file($dir . 'youtube-video.docx', 'youtube-video.docx')));
        $this->assertSame([], $check->run(new inspected_file($dir . 'essay.docx', 'essay.docx')));

        $findings = $check->run(new inspected_file($dir . 'sharepoint-video.pptx', 'sharepoint-video.pptx'));
        $this->assertCount(1, $findings);
        $this->assertSame('cloudmedia', $findings[0]->code);
        $this->assertStringContainsString(
            'interview.mp4 (slide 1) is not included in this document. It is stored on OneDrive.',
            implode(' ', $findings[0]->item_labels())
        );
    }

    /**
     * Content that matches no known kind under a name with a signature cannot be opened; plain text and other names
     * keep their own results; a real MP3 is sound.
     */
    public function test_unrecognised_content(): void {
        global $CFG;
        $dir = $CFG->dirroot . '/local/submissioncheck/tests/fixtures/';
        $this->assertSame([['cannotopen'], []], $this->run_checks($dir . 'damaged-start.docx', 'damaged-start.docx'));
        $this->assertSame([['cannotopen'], []], $this->run_checks($dir . 'noise.pdf', 'noise.pdf'));
        $this->assertSame(['wrongextension'], $this->run_checks($dir . 'renamed.docx', 'renamed.docx')[0]);
        $this->assertSame([[], []], $this->run_checks($dir . 'noise.pdf', 'noise.bin'));
        $this->assertSame([[], []], $this->run_checks($dir . 'clip.mp3', 'clip.mp3'));
        $this->assertSame(inspected_file::TYPE_MP3, (new inspected_file($dir . 'clip.mp3', 'clip.mp3'))->get_type());
        $this->assertSame([[], []], $this->run_checks($dir . 'essay.docx', 'essay.docx'));
        // What a real MP3 named .docx reports today (recorded, not changed in this build).
        $this->assertSame([[], []], $this->run_checks($dir . 'clip.mp3', 'clip.docx'));
    }

    /**
     * Text links to files on the student's computer or to sign-in storage are broken; public web and mail links are
     * not; each broken item has a short reason.
     */
    public function test_broken_links(): void {
        global $CFG;
        $this->resetAfterTest();
        $dir = $CFG->dirroot . '/local/submissioncheck/tests/fixtures/';
        $links = local\checks\linked_media::locate_links(new inspected_file($dir . 'broken-links.docx', 'broken-links.docx'));
        $this->assertSame(['Reading list', 'My notes'], array_column($links, 'name'));
        $this->assertSame(['SharePoint', null], array_column($links, 'host'));

        $this->assertSame([], local\checks\linked_media::locate_links(new inspected_file($dir . 'public-link.docx', 'p.docx')));
        $this->assertSame([], (new local\checks\linked_media())->run(new inspected_file($dir . 'public-link.docx', 'p.docx')));
        // An online video's poster link is not a text link.
        $video = new inspected_file($dir . 'sharepoint-video.docx', 'v.docx');
        $this->assertSame([], local\checks\linked_media::locate_links($video));

        $items = [];
        foreach ((new local\checks\linked_media())->run(new inspected_file($dir . 'broken-links.docx', 'b.docx')) as $finding) {
            $items = array_merge($items, $finding->broken_items());
        }
        $this->assertSame([
            ['Not in the file: on your computer', 'graph1.png'],
            ['Needs sign-in: SharePoint', 'Reading list'],
            ['Link to a file on your computer', 'My notes'],
        ], array_map(fn($i) => [$i['reason'], $i['name']], $items));
    }
}
