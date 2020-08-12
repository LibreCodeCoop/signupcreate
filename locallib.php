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
 * signupcreate event handlers
 *
 * @package    local_signupcreate
 * @copyright  2011 Qontori Pte Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir.'/completionlib.php');
require_once($CFG->libdir.'/filelib.php');
require_once($CFG->libdir.'/datalib.php');
require_once($CFG->dirroot.'/course/format/lib.php');
require_once($CFG->dirroot.'/course/lib.php');
require_once($CFG->dirroot . '/user/editlib.php');
require_once($CFG->libdir . '/authlib.php');
require_once($CFG->dirroot . '/login/lib.php');
require_once($CFG->libdir . '/formslib.php');


define('COURSE_MAX_LOGS_PER_PAGE', 1000);       // Records.
define('COURSE_MAX_RECENT_PERIOD', 172800);     // Two days, in seconds.

/**
 * Event handler for signupcreate course plugin.
 */
class course_signupcreate_handler {

    /**
     * signupcreate a course and either return a $course object
     *
     * Please note this functions does not verify any access control,
     * the calling code is responsible for all validation (usually it is the form definition).
     *
     * @param array $editoroptions course description editor options
     * @param object $data  - all the data needed for an entry in the 'course' table
     * @return object new course instance
     */
    function user_created($data, $editoroptions = NULL) {
        global $DB, $CFG;

        $authplugin = signup_is_enabled();
        $mform_signup = $authplugin->signup_form();
        $user = $mform_signup->get_submitted_data();

        if($user->profile_field_PERFIL == "Quero dar aulas"){
            $data = new StdClass;
            $data->category = "2";
            $data->fullname = $user->firstname . " " . $user->lastname;
            $data->shortname = time();
            $data->timecreated = time();
            $data->timemodified = $data->timecreated;
            $data->visible = "1";
            $data->visibleold = $data->visible;
            $data->sortorder = 0; // place at beginning of any category
        }
        

        //check the categoryid - must be given for all new courses
        $category = $DB->get_record('course_categories', array('id'=>$data->category), '*', MUST_EXIST);

        // Check if the idnumber already exists.
        if (!empty($data->idnumber)) {
            if ($DB->record_exists('course', array('idnumber' => $data->idnumber))) {
                throw new moodle_exception('courseidnumbertaken', '', '', $data->idnumber);
            }
        }

        if ($errorcode = course_validate_dates((array)$data)) {
            throw new moodle_exception($errorcode);
        } 


        if ($editoroptions) {
            // summary text is updated later, we need context to store the files first
            $data->summary = '';
            $data->summary_format = FORMAT_HTML;
        }
        

        $newcourseid = $DB->insert_record('course', $data);
        $context = context_course::instance($newcourseid, MUST_EXIST);

        if ($editoroptions) {
            // Save the files used in the summary editor and store
            $data = file_postupdate_standard_editor($data, 'summary', $editoroptions, $context, 'course', 'summary', 0);
            $DB->set_field('course', 'summary', $data->summary, array('id'=>$newcourseid));
            $DB->set_field('course', 'summaryformat', $data->summary_format, array('id'=>$newcourseid));
        }
        if ($overviewfilesoptions = course_overviewfiles_options($newcourseid)) {
            // Save the course overviewfiles
            $data = file_postupdate_standard_filemanager($data, 'overviewfiles', $overviewfilesoptions, $context, 'course', 'overviewfiles', 0);
        }

        // update course format options
        course_get_format($newcourseid)->update_course_format_options($data);

        $course = course_get_format($newcourseid)->get_course();

        fix_course_sortorder();
        // purge appropriate caches in case fix_course_sortorder() did not change anything
        cache_helper::purge_by_event('changesincourse');

        // Trigger a course created event.
        $event = \core\event\course_created::create(array(
            'objectid' => $course->id,
            'context' => context_course::instance($course->id),
            'other' => array('shortname' => $course->shortname,
                'fullname' => $course->fullname)
        ));

        $event->trigger();

        // Setup the blocks
        blocks_add_default_course_blocks($course);

        // Create default section and initial sections if specified (unless they've already been created earlier).
        // We do not want to call course_create_sections_if_missing() because to avoid creating course cache.
        $numsections = isset($data->numsections) ? $data->numsections : 0;
        $existingsections = $DB->get_fieldset_sql('SELECT section from {course_sections} WHERE course = ?', [$newcourseid]);
        $newsections = array_diff(range(0, $numsections), $existingsections);
        foreach ($newsections as $sectionnum) {
            course_create_section($newcourseid, $sectionnum, true);
        }


        // set up enrolments
        //enrol_course_updated(true, $course, $data);

        // Update course tags.
        if (isset($data->tags)) {
            core_tag_tag::set_item_tags('core', 'course', $course->id, context_course::instance($course->id), $data->tags);
        }

/*         // Save custom fields if there are any of them in the form.
        $handler = core_course\customfield\course_handler::create();
        // Make sure to set the handler's parent context first.
        $coursecatcontext = context_coursecat::instance($category->id);
        $handler->set_parent_context($coursecatcontext);
        // Save the custom field data.
        $data->id = $course->id;
        $handler->instance_form_save($data, true); */

        return $course;
    }


}
