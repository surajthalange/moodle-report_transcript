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

/**
 * Settings. Core creates $settings as the page under Plugins > Reports > Transcript.
 *
 * @package    report_transcript
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings->add(new admin_setting_configtext(
        'report_transcript/documenttitle',
        get_string('settings:documenttitle', 'report_transcript'),
        get_string('settings:documenttitle_desc', 'report_transcript'),
        get_string('defaultdocumenttitle', 'report_transcript'),
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'report_transcript/institutionname',
        get_string('settings:institutionname', 'report_transcript'),
        get_string('settings:institutionname_desc', 'report_transcript'),
        '',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configstoredfile(
        'report_transcript/institutionlogo',
        get_string('settings:institutionlogo', 'report_transcript'),
        get_string('settings:institutionlogo_desc', 'report_transcript'),
        'institutionlogo',
        0,
        ['maxfiles' => 1, 'accepted_types' => ['.png', '.jpg', '.jpeg']]
    ));

    $settings->add(new admin_setting_configcheckbox(
        'report_transcript/includeinprogress',
        get_string('settings:includeinprogress', 'report_transcript'),
        get_string('settings:includeinprogress_desc', 'report_transcript'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'report_transcript/includeuntracked',
        get_string('settings:includeuntracked', 'report_transcript'),
        get_string('settings:includeuntracked_desc', 'report_transcript'),
        0
    ));

    // The category list is built lazily so that the settings tree can be constructed
    // without loading every category on every admin page.
    $settings->add(new admin_setting_configmultiselect(
        'report_transcript/excludedcategories',
        get_string('settings:excludedcategories', 'report_transcript'),
        get_string('settings:excludedcategories_desc', 'report_transcript'),
        [],
        core_course_category::make_categories_list()
    ));

    $settings->add(new admin_setting_configselect(
        'report_transcript/gradedisplay',
        get_string('settings:gradedisplay', 'report_transcript'),
        get_string('settings:gradedisplay_desc', 'report_transcript'),
        0,
        [
            0 => get_string('settings:gradedisplay:course', 'report_transcript'),
            GRADE_DISPLAY_TYPE_REAL => get_string('real', 'grades'),
            GRADE_DISPLAY_TYPE_PERCENTAGE => get_string('percentage', 'grades'),
            GRADE_DISPLAY_TYPE_LETTER => get_string('letter', 'grades'),
        ]
    ));

    $settings->add(new admin_setting_configcheckbox(
        'report_transcript/showoutcome',
        get_string('settings:showoutcome', 'report_transcript'),
        get_string('settings:showoutcome_desc', 'report_transcript'),
        1
    ));

    $settings->add(new admin_setting_configtextarea(
        'report_transcript/footertext',
        get_string('settings:footertext', 'report_transcript'),
        get_string('settings:footertext_desc', 'report_transcript'),
        get_string('settings:footertext_default', 'report_transcript'),
        PARAM_TEXT
    ));
}
