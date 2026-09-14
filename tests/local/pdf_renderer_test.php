<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace report_transcript\local;

/**
 * Tests for pdf_renderer.
 *
 * Text inside a PDF drawn with a Unicode TrueType font is stored as glyph ids, so the
 * assertions look at what is stored as plain text: the header, the document metadata
 * (the code goes into Subject and Keywords for exactly this reason), and the page count.
 *
 * Metadata stays in doc-comments rather than PHP attributes: attributes arrived in
 * PHPUnit 10 and Moodle 4.5, this plugin's floor, ships PHPUnit ^9.6.34.
 *
 * @package    report_transcript
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \report_transcript\local\pdf_renderer
 * @covers     \report_transcript\local\transcript_pdf
 */
final class pdf_renderer_test extends \advanced_testcase {
    /**
     * Render a transcript with the given rows to uncompressed PDF bytes.
     *
     * @param transcript_row[] $rows
     * @param string $fullname
     * @return array{0: string, 1: \stdClass} the bytes and the issue record used
     */
    private function render(array $rows, string $fullname = 'Priya Nair'): array {
        global $PAGE;

        $user = $this->getDataGenerator()->create_user();
        $transcript = new transcript((int) $user->id, $fullname, $user->username, $rows, 1700000000, 'Academic transcript');
        $issue = (object) ['id' => 1, 'code' => 'ABCDEFGHJKLMNPQRSTUVWXYZ', 'timecreated' => 1700000000];

        $renderer = new pdf_renderer(settings::defaults(), $PAGE->get_renderer('core'), false);
        return [$renderer->render($transcript, $issue, $user), $issue];
    }

    /**
     * A row for the tests.
     *
     * @param string $name
     * @param string $outcome
     * @param string $gradetext
     * @return transcript_row
     */
    private function row(string $name, string $outcome = outcome::PASS, string $gradetext = '75.00'): transcript_row {
        return new transcript_row(
            1,
            $name,
            'SHORT',
            grade_presenter::STATUS_COMPLETED,
            1690000000,
            1700000000,
            new grade_presentation($gradetext, $outcome, false, 75.0, 50.0, GRADE_DISPLAY_TYPE_REAL),
        );
    }

    /**
     * The output is a PDF carrying the code in its metadata.
     */
    public function test_renders_a_pdf_with_the_code_in_metadata(): void {
        $this->resetAfterTest();

        [$bytes, $issue] = $this->render([$this->row('Ethics')]);

        $this->assertStringStartsWith('%PDF-', $bytes);
        // TCPDF stores Info strings as UTF-16BE, each opening with a byte-order mark.
        $utf16 = fn(string $s): string => mb_convert_encoding($s, 'UTF-16BE', 'UTF-8');
        $this->assertStringContainsString('/Keywords (' . "\xFE\xFF" . $utf16($issue->code) . ')', $bytes);
        $this->assertStringContainsString($utf16('ABCD EFGH JKLM NPQR STUV WXYZ'), $bytes, 'The formatted code is in the Subject');
        $this->assertStringContainsString($utf16('Academic transcript - Priya Nair'), $bytes, 'The title names the learner');
    }

    /**
     * Enough rows to overflow one page produce a second, and the footer numbers them.
     */
    public function test_long_transcripts_paginate(): void {
        $this->resetAfterTest();

        $rows = [];
        for ($i = 1; $i <= 45; $i++) {
            $rows[] = $this->row("Course number {$i} with a reasonably long title to fill the line");
        }
        [$bytes] = $this->render($rows);

        $this->assertMatchesRegularExpression('/\/Type \/Pages[^>]*\/Count (\d+)/', $bytes);
        preg_match('/\/Type \/Pages[^>]*\/Count (\d+)/', $bytes, $m);
        $this->assertGreaterThanOrEqual(2, (int) $m[1]);
    }

    /**
     * An empty transcript still renders, with the empty-state sentence.
     */
    public function test_empty_transcript_renders(): void {
        $this->resetAfterTest();

        [$bytes] = $this->render([]);

        $this->assertStringStartsWith('%PDF-', $bytes);
        preg_match('/\/Type \/Pages[^>]*\/Count (\d+)/', $bytes, $m);
        $this->assertSame(1, (int) $m[1]);
    }

    /**
     * Names in every script the definition of done lists render without an error. The
     * fonts are switched per run; this proves the switch does not break rendering and that
     * each CJK run picks its font.
     */
    public function test_font_runs(): void {
        $this->resetAfterTest();

        $this->assertSame('Priya Nair', pdf_renderer::font_runs('Priya Nair'));
        $this->assertSame('प्रिया नायर', pdf_renderer::font_runs('प्रिया नायर'), 'Devanagari stays in the base font');
        $this->assertSame('سارة أحمد', pdf_renderer::font_runs('سارة أحمد'), 'Arabic stays in the base font');
        $this->assertSame(
            '<span style="font-family:stsongstdlight">敏</span> <span style="font-family:stsongstdlight">趙</span>',
            pdf_renderer::font_runs('敏 趙')
        );
        $this->assertSame(
            '<span style="font-family:stsongstdlight">拓海</span> <span style="font-family:stsongstdlight">渡辺</span>',
            pdf_renderer::font_runs('拓海 渡辺'),
            'A Japanese name written only in kanji cannot be told from Chinese; the Han font still prints it'
        );
        $this->assertSame(
            '<span style="font-family:kozminproregular">たくみ</span>',
            pdf_renderer::font_runs('たくみ')
        );
        $this->assertSame(
            '<span style="font-family:hysmyeongjostdmedium">민준</span>',
            pdf_renderer::font_runs('민준')
        );
        $this->assertSame('&lt;b&gt;', pdf_renderer::font_runs('<b>'), 'Input is escaped');

        // And the whole thing renders with every script at once.
        [$bytes] = $this->render([
            $this->row('प्रिया नायर'), $this->row('سارة أحمد'), $this->row('敏 趙'), $this->row('たくみ'), $this->row('민준'),
        ], 'Priya नायर 趙 たくみ 민준');
        $this->assertStringStartsWith('%PDF-', $bytes);
    }
}
