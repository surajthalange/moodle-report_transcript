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
 * The plugin's settings as a typed value object.
 *
 * Read once from config by from_config(), or built directly in tests so that the
 * builder and presenter can be exercised against any combination without touching the
 * database. Every rule in the builder and presenter takes one of these rather than
 * calling get_config() itself.
 *
 * @package    report_transcript
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class settings {
    /** @var int Follow each course's own grade display type. */
    public const GRADEDISPLAY_COURSE = 0;

    /**
     * Constructor.
     *
     * @param string $documenttitle heading and PDF title
     * @param string $institutionname PDF header; empty means the site name
     * @param bool $includeinprogress list active, uncompleted, completion-tracked enrolments
     * @param bool $includeuntracked list active enrolments in courses without completion tracking
     * @param int[] $excludedcategories category ids whose courses (and subcategories) never appear
     * @param int $gradedisplay GRADEDISPLAY_COURSE or a GRADE_DISPLAY_TYPE_* constant
     * @param bool $showoutcome whether the outcome column exists at all
     * @param string $footertext PDF footer with {code}, {verifyurl} and {institution} placeholders
     */
    public function __construct(
        /** @var string Heading and PDF title */
        public readonly string $documenttitle,
        /** @var string PDF header; empty means the site name */
        public readonly string $institutionname,
        /** @var bool List active, uncompleted, completion-tracked enrolments */
        public readonly bool $includeinprogress,
        /** @var bool List active enrolments in courses without completion tracking */
        public readonly bool $includeuntracked,
        /** @var array Category ids whose courses (and subcategories) never appear */
        public readonly array $excludedcategories,
        /** @var int GRADEDISPLAY_COURSE or a GRADE_DISPLAY_TYPE_* constant */
        public readonly int $gradedisplay,
        /** @var bool Whether the outcome column exists at all */
        public readonly bool $showoutcome,
        /** @var string PDF footer with {code}, {verifyurl} and {institution} placeholders */
        public readonly string $footertext,
    ) {
    }

    /**
     * Build from the stored configuration.
     *
     * @return self
     */
    public static function from_config(): self {
        global $SITE;

        $config = get_config('report_transcript');

        $title = trim((string) ($config->documenttitle ?? ''));
        if ($title === '') {
            $title = get_string('defaultdocumenttitle', 'report_transcript');
        }

        $institution = trim((string) ($config->institutionname ?? ''));
        if ($institution === '') {
            // Explicit context: this runs before any page has set one.
            $institution = format_string($SITE->fullname, true, ['context' => \context_system::instance()]);
        }

        $excluded = array_filter(array_map('intval', explode(',', (string) ($config->excludedcategories ?? ''))));

        $footer = (string) ($config->footertext ?? '');
        if (trim($footer) === '') {
            $footer = get_string('settings:footertext_default', 'report_transcript');
        }

        return new self(
            $title,
            $institution,
            !empty($config->includeinprogress),
            !empty($config->includeuntracked),
            array_values($excluded),
            (int) ($config->gradedisplay ?? self::GRADEDISPLAY_COURSE),
            !isset($config->showoutcome) || !empty($config->showoutcome),
            $footer,
        );
    }

    /**
     * A settings object with every default, for tests.
     *
     * @param array $overrides constructor argument names to override
     * @return self
     */
    public static function defaults(array $overrides = []): self {
        $base = [
            'documenttitle' => 'Academic transcript',
            'institutionname' => 'Test institution',
            'includeinprogress' => true,
            'includeuntracked' => false,
            'excludedcategories' => [],
            'gradedisplay' => self::GRADEDISPLAY_COURSE,
            'showoutcome' => true,
            'footertext' => 'Verify at {verifyurl} with code {code}.',
        ];
        return new self(...array_merge($base, $overrides));
    }
}
