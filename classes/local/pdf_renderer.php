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

use context_system;
use moodle_url;
use renderer_base;
use stdClass;

/**
 * Renders an issued transcript as PDF bytes.
 *
 * The body comes from pdf.mustache, which is table-only markup because TCPDF's HTML
 * support is narrow; it shares data with the page template but nothing else. Fonts:
 * FreeSerif for everything TCPDF's bundled fonts cover (Latin, Cyrillic, Greek, Arabic,
 * Devanagari), with runs of CJK text switched to one of the bundled CJK fonts so that a
 * name is never printed as boxes.
 *
 * @package    report_transcript
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pdf_renderer {
    /** @var string The base font. */
    public const BASE_FONT = 'freeserif';

    /**
     * Constructor.
     *
     * @param settings $settings
     * @param renderer_base $output for the template
     * @param bool $compress off in tests so that the metadata can be asserted on
     */
    public function __construct(
        private readonly settings $settings,
        private readonly renderer_base $output,
        private readonly bool $compress = true,
    ) {
    }

    /**
     * Render.
     *
     * @param transcript $transcript
     * @param stdClass $issue the issue record: code and timecreated
     * @param stdClass $learner the learner, for the timezone dates render in
     * @return string PDF bytes
     */
    public function render(transcript $transcript, stdClass $issue, stdClass $learner): string {
        $code = issuer::format_code($issue->code);
        $verifyurl = (new moodle_url('/report/transcript/verify.php', ['code' => $issue->code]))->out(false);
        $footer = strtr($this->settings->footertext, [
            '{code}' => $code,
            '{verifyurl}' => $verifyurl,
            '{institution}' => $this->settings->institutionname,
        ]);

        $pdf = new transcript_pdf('P', 'mm', 'A4', true, 'UTF-8');
        $pdf->SetCreator($this->settings->institutionname);
        $pdf->SetAuthor($this->settings->institutionname);
        $pdf->SetTitle($transcript->documenttitle . ' - ' . $transcript->fullname);
        $pdf->SetSubject(get_string('pdf:code', 'report_transcript') . ': ' . $code);
        $pdf->SetKeywords($issue->code);
        $pdf->SetCompression($this->compress);
        $pdf->set_chrome($this->settings->institutionname, $this->logo_bytes(), $footer, self::BASE_FONT);
        $pdf->SetMargins(18, 40, 18);
        $pdf->SetHeaderMargin(0);
        $pdf->SetFooterMargin(0);
        $pdf->SetAutoPageBreak(true, 28);
        $pdf->SetFont(self::BASE_FONT, '', 10);
        $pdf->AddPage();

        $html = $this->output->render_from_template('report_transcript/pdf', $this->context($transcript, $issue, $learner, $code, $verifyurl));
        $pdf->writeHTML($html, true, false, true, false, '');

        return $pdf->Output('', 'S');
    }

    /**
     * The template context.
     *
     * @param transcript $t
     * @param stdClass $issue
     * @param stdClass $learner
     * @param string $code formatted
     * @param string $verifyurl
     * @return array
     */
    private function context(transcript $t, stdClass $issue, stdClass $learner, string $code, string $verifyurl): array {
        $timezone = \core_date::get_user_timezone($learner);
        $format = get_string('strftimedate', 'langconfig');
        $dash = get_string('notavailable', 'report_transcript');

        $rows = [];
        foreach ($t->rows as $r) {
            $rows[] = [
                'coursename' => self::font_runs($r->coursename),
                'shortname' => self::font_runs($r->shortname),
                'started' => $r->timestarted ? userdate($r->timestarted, $format, $timezone) : $dash,
                'completed' => $r->timecompleted ? userdate($r->timecompleted, $format, $timezone) : $dash,
                'gradetext' => $r->grade->gradetext,
                'outcome' => outcome::label($r->grade->outcome),
            ];
        }

        return [
            'title' => self::font_runs($t->documenttitle),
            'fullname' => self::font_runs($t->fullname),
            'issuedon' => userdate((int) $issue->timecreated, $format, $timezone),
            'code' => $code,
            'verifyurl' => $verifyurl,
            'hasrows' => $rows !== [],
            'rows' => $rows,
            'showoutcome' => $this->settings->showoutcome,
            // TCPDF does not carry header widths down to body cells, so every cell states its own.
            'coursewidth' => $this->settings->showoutcome ? 40 : 52,
            'emptytext' => get_string('summaryempty', 'report_transcript'),
        ];
    }

    /**
     * The institution logo's bytes, if one is uploaded.
     *
     * @return string|null
     */
    private function logo_bytes(): ?string {
        $fs = get_file_storage();
        $files = $fs->get_area_files(context_system::instance()->id, 'report_transcript', 'institutionlogo', 0, 'itemid, filepath, filename', false);
        $file = reset($files);
        return $file ? $file->get_content() : null;
    }

    /**
     * Wrap CJK runs in a font that can print them. Everything else stays in the base font.
     *
     * Returns HTML: the input is escaped, then spans are added around CJK runs. Japanese
     * kana pick the Japanese font, Hangul the Korean one, and remaining Han characters the
     * Simplified Chinese one, which also covers Traditional forms well enough for a name.
     *
     * @param string $text plain text
     * @return string HTML
     */
    public static function font_runs(string $text): string {
        $escaped = s($text);
        $pattern = '/(\p{Hiragana}|\p{Katakana}|\p{Hangul}|\p{Han})+/u';
        return preg_replace_callback($pattern, function (array $m): string {
            $run = $m[0];
            if (preg_match('/[\p{Hiragana}\p{Katakana}]/u', $run)) {
                $font = 'kozminproregular';
            } else if (preg_match('/\p{Hangul}/u', $run)) {
                $font = 'hysmyeongjostdmedium';
            } else {
                $font = 'stsongstdlight';
            }
            return '<span style="font-family:' . $font . '">' . $run . '</span>';
        }, $escaped);
    }
}
