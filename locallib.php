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

////////// Course libs
require_once($CFG->libdir.'/completionlib.php');
require_once($CFG->libdir.'/filelib.php');
require_once($CFG->libdir.'/datalib.php');
require_once($CFG->dirroot.'/course/format/lib.php');
require_once($CFG->dirroot.'/course/lib.php');
require_once($CFG->dirroot . '/user/editlib.php');
require_once($CFG->libdir . '/authlib.php');
require_once($CFG->dirroot . '/login/lib.php');
require_once($CFG->libdir . '/formslib.php');


//////////// Enrol libs
require_once($CFG->dirroot.'/enrol/locallib.php');
require_once($CFG->dirroot.'/group/lib.php');
require_once($CFG->dirroot.'/enrol/manual/locallib.php');
require_once($CFG->dirroot.'/cohort/lib.php');
require_once($CFG->dirroot . '/enrol/manual/classes/enrol_users_form.php');



define('COURSE_MAX_LOGS_PER_PAGE', 1000);       // Records.
define('COURSE_MAX_RECENT_PERIOD', 172800);     // Two days, in seconds.

/**
 * Event handler for signupcreate course plugin.
 */
class course_signupcreate_handler {

    /**
     * Create a course and enrol user with teacher role
     *
     * @param array $editoroptions course description editor options
     * @param object $data  - all the data needed for an entry in the 'course' table
     * @return object new course instance
     */
    function user_created($data, $editoroptions = NULL) {
        global $DB, $CFG, $PAGE;

        $authplugin = signup_is_enabled();
        $mform_signup = $authplugin->signup_form();
        $user = $mform_signup->get_submitted_data();

        if($user->profile_field_PERFIL == "Quero dar aulas"){
            $data = new StdClass;
            $data->category = "16"; // Generic
            $data->fullname = $user->firstname . " " . $user->lastname;
            $data->shortname = time();
            $data->timecreated = time();
            $data->timemodified = $data->timecreated;
            $data->visible = "0"; // start invisible
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


        $newcourseid = $DB->insert_record('course', $data);
        $context = context_course::instance($newcourseid, MUST_EXIST);


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
        
        
        $course = $DB->get_record('course', array('id'=>$newcourseid), '*', MUST_EXIST);

        //////////////// Add manual enrolment method to the course created    

        $plugin = enrol_get_plugin("manual");
        if (!$plugin) {
            throw new moodle_exception('invaliddata', 'error');
        }

        $fields = array();
        $fields["status"] = "0";
        $fields["roleid"] = "5"; // student
        $fields["enrolperiod"] = 0;
        $fields["expirynotify"] = "0";
        $fields["expirythreshold"] = 0;
        $fields["id"] = 0;
        $fields["courseid"] = $newcourseid;
        $fields["type"] = "manual";
        $fields["returnurl"] = "";
        $fields["submitbutton"] = "";

        $plugin->add_instance($course, $fields);
        

        //////////// Enrol user in his course as teacher //////////////

        $PAGE->set_url(new moodle_url('/enrol/ajax.php', array('id'=>$newcourseid, 'action'=>"enrol")));
        $manager = new course_enrolment_manager($PAGE, $course);    

        $user_data = $DB->get_record('user', array('username' => $user->username), '*', MUST_EXIST);                

        $selfinstance = $DB->get_record('enrol', array('courseid' => $course->id, 'enrol' => 'manual'));
            
        $roleid = 3; // teacher
        
        $recovergrades = optional_param('recovergrades', 0, PARAM_INT);
        $timeend = optional_param_array('timeend', [], PARAM_INT);
    
        $timestart = intval(substr(time(), 0, 8) . '00') - 1;        
        $timeend = 0;
        
        $mform = new enrol_manual_enrol_users_form(null, (object)["context" => $context]);
        $userenroldata = [
                'startdate' => $timestart,
                'timeend' => $timeend,
        ];
        $mform->set_data($userenroldata);
        $validationerrors = $mform->validation($userenroldata, null);
        if (!empty($validationerrors)) {
            throw new enrol_ajax_exception('invalidenrolduration');
        }
        
        $instances = $manager->get_enrolment_instances();
        $plugins = $manager->get_enrolment_plugins(true); // Do not allow actions on disabled plugins.
        if (!array_key_exists($selfinstance->id, $instances)) {
            throw new enrol_ajax_exception('invalidenrolinstance');
        }
        $instance = $instances[$selfinstance->id];
        if (!isset($plugins[$instance->enrol])) {
            throw new enrol_ajax_exception('enrolnotpermitted');
        }
        $plugin = $plugins[$instance->enrol];
        if ($plugin->allow_enrol($instance)) {
            
            $plugin->enrol_user($instance, $user_data->id, $roleid, $timestart, $timeend, null, $recovergrades);

        } else {
            throw new enrol_ajax_exception('enrolnotpermitted');
        }
    
    }

}
