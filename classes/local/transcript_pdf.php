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

use pdf;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/pdflib.php');

/**
 * The PDF document class: Moodle's TCPDF wrapper with the transcript's header and footer.
 *
 * TCPDF draws headers and footers by calling Header() and Footer() on every page, so the
 * repeated parts live here and the body is written once by pdf_renderer.
 *
 * @package    report_transcript
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class transcript_pdf extends pdf {
    /** @var string Institution name for the header. */
    private string $institution = '';

    /** @var string|null Raw image bytes for the header logo. */
    private ?string $logo = null;

    /** @var string Footer text, already with placeholders replaced. */
    private string $footertext = '';

    /** @var string The base font, chosen for the scripts the document contains. */
    private string $basefont = 'freeserif';

    /**
     * Set what the header and footer print.
     *
     * @param string $institution
     * @param string|null $logo image bytes, or null for none
     * @param string $footertext
     * @param string $basefont
     */
    public function set_chrome(string $institution, ?string $logo, string $footertext, string $basefont): void {
        $this->institution = $institution;
        $this->logo = $logo;
        $this->footertext = $footertext;
        $this->basefont = $basefont;
    }

    /**
     * Page header: logo on the left, institution name beside it, a rule beneath.
     */
    public function Header() { // phpcs:ignore moodle.NamingConventions.ValidFunctionName.LowercaseMethod
        $margin = $this->original_lMargin;
        $top = 10;
        $textleft = $margin;

        if ($this->logo !== null) {
            // Height-constrained; width follows the image's ratio.
            $this->Image('@' . $this->logo, $margin, $top, 0, 18, '', '', 'T', false, 300, '', false, false, 0);
            $textleft = $margin + $this->getImageRBX() - $margin + 4;
            $textleft = max($textleft, $this->getImageRBX() + 4);
        }

        $this->SetFont($this->basefont, 'B', 13);
        $this->SetXY($textleft, $top + 3);
        $this->Cell(0, 8, $this->institution, 0, 1, 'L');

        $this->SetLineWidth(0.3);
        $this->Line($margin, $top + 22, $this->getPageWidth() - $this->original_rMargin, $top + 22);
    }

    /**
     * Page footer: the footer text, then "Page x of y".
     */
    public function Footer() { // phpcs:ignore moodle.NamingConventions.ValidFunctionName.LowercaseMethod
        $this->SetY(-22);
        $this->SetFont($this->basefont, '', 8);
        $this->SetTextColor(90, 90, 90);
        $this->MultiCell(0, 4, $this->footertext, 0, 'L', false, 1);
        $this->SetY(-10);
        $page = get_string('pdf:page', 'report_transcript', (object) [
            'page' => $this->getAliasNumPage(),
            'total' => $this->getAliasNbPages(),
        ]);
        $this->Cell(0, 5, $page, 0, 0, 'R');
        $this->SetTextColor(0, 0, 0);
    }
}
