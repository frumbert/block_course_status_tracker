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
/* Course Status Tracker Block
 * The plugin shows the number and list of enrolled courses and completed courses.
 * It also shows the number of courses which are in progress and whose completion criteria is undefined but the manger.
 * @package blocks
 * @author: Azmat Ullah, Talha Noor
 */

global $CFG;
require_once($CFG->dirroot.'/blocks/course_status_tracker/lib.php');
require_once($CFG->dirroot."/lib/enrollib.php");
require_once($CFG->dirroot.'/group/lib.php');

/**
 * This class shows the content in block through calling lib.php function.
 */
class block_course_status_tracker extends block_base {

	public function init() {
		global $USER;
		$this->title = get_string("block_title", "block_course_status_tracker");
	}

	/**
	 * Where to add the block
	 *
	 * @return boolean
	 * */
	public function applicable_formats() {
		return array('all' => true);
	}

	/**
	 * Gets the contents of the block (course view)
	 *
	 * @return object An object with the contents
	 * */
	public function isguestuser($user = null) {
		return false;
	}

	// a shortened version of externallib's get_courses() routine
	private static function all_courses() {
		global $DB;
		$godmode = is_siteadmin();
		$courses = $DB->get_records('course');
		$coursesinfo = array();
		foreach ($courses as $course) {
			if ($course->id == 1) continue; // front page
			$context = context_course::instance($course->id, IGNORE_MISSING);
			$courseadmin = has_capability('moodle/course:update', $context);
			if ($godmode or $courseadmin or $course->visible or has_capability('moodle/course:viewhiddencourses', $context)) {
				$courseinfo = array();
				$courseinfo['id'] = $course->id;
				$courseinfo['shortname'] = $course->shortname;
				$courseinfo['displayname'] = $course->fullname;
				$courseinfo['categoryid'] = $course->category;
				$courseinfo['showgrades'] = $course->showgrades;
				$coursesinfo[] = $courseinfo;
			}
		}
		return $coursesinfo;
	}

	// returns a list of users for a practice, optionally within a role
	private static function all_users($institution, $department = null) {
		global $DB;
		$params = array("institution" => $institution);
		if (!is_null($department)) {
			$params["department"] = $department;
		}
		$rows = $DB->get_records('user', $params);
		// we don't need all that much
		$results = array();
		foreach ($rows as $row) {
			$user = new stdClass();
			$user->id = $row->id;
			$user->email = $row->email;
			$user->displayname = $row->firstname . ' ' . $row->lastname;
			$user->lastaccess = $row->lastaccess;
			$results[] = $user;
		}
		return $results;
	}

	// find out if a course is enrollable for a given role
	private static function is_enrollable($instancedata, $rolename, $courseid, $is_manager) {
		if (is_siteadmin()) return false; // do not auto-enrol the site admin
		$result = ($is_manager) ? false : true; // assumes all courses to be enrollable, except to practice managers
		$key = "enrol_" . md5($rolename . "_" . $courseid);
		if (isset($instancedata) && isset($instancedata[$key])) {
			$result = ($instancedata[$key] == 1);
		}
		return $result;
	}

	// thanks https://moodle.org/mod/forum/discuss.php?d=266496#p1152283 :)
	private static function enrol_into_course($courseid, $USER, $roleid, $enrolmethod = 'manual') {
		global $DB;
		$course = $DB->get_record('course', array('id' => $courseid), '*', MUST_EXIST);
		$context = context_course::instance($course->id);

		// ensure the groupmode is set properly on courses
		if ($course->groupmode !== 1 || $course->enablecompletion !== 1) {
			$record = clone $course;
			$record->groupmode = 1;
			$record->enablecompletion = 1;
			$record->timemodified = time();
			$record->cacherev = 0;
			$DB->update_record('course', $record);
		}

		// ensure a group exists for this course / practice
		if (!$DB->record_exists('groups', array('courseid' => $courseid, 'name' => $USER->institution))) {
			$group = new stdClass();
			$group->courseid = $courseid;
			$group->idnumber = '';
			$group->name = $USER->institution;
			$group->descriptionformat = 0;
			$group->picture = 0;
			$group->hidepicture = 0;
			$group->timecreated = time();
			$group->timemodified = time();
			$DB->insert_record('groups', $group);
		}

		// check that we have the proper enrolment instance on this course
		$enrol = enrol_get_plugin($enrolmethod);
		if ($enrol === null) {
			return false;
		}
		$instances = enrol_get_instances($course->id, true);
		$manualinstance = null;
		foreach ($instances as $instance) {
			if ($instance->name == $enrolmethod) {
				$manualinstance = $instance;
				break;
			}
		}
		if ($manualinstance !== null) {
			$instanceid = $enrol->add_default_instance($course);
			if ($instanceid === null) {
				$instanceid = $enrol->add_instance($course);
			}
			$instance = $DB->get_record('enrol', array('id' => $instanceid));
		}

		// make sure we reset role assignments before trying to enrol them again
		$record = $DB->get_record('role_assignments', array('contextid' => $context->id, "userid" => $USER->id));
		if ($record && $record->roleid <> $roleid) {
			$record->roleid = $roleid;
			$record->timemodified = time();
			$DB->update_record('role_assignments', $record);
		}

		// perform the enrolment and assign the role
		$enrol->enrol_user($instance, $USER->id, $roleid);

		// enrol this user into the group
		$group = $DB->get_record('groups', array('courseid' => $courseid, 'name' => $USER->institution), 'id', MUST_EXIST);
		if (!$DB->record_exists('groups_members', array('groupid'=>$group->id, 'userid'=>$USER->id))) {
			groups_add_member($group->id, $USER->id);
		}

		return true;
	}

	// find out the properly formatted grade for this course (effectively /grade/report/overview/index.php for one course)
	private static function get_user_grade($course, $userid) {
		global $CFG, $USER;
		$context = context_course::instance($course->id);
		$systemcontext = context_system::instance();
		$personalcontext = context_user::instance($userid);
		require_once($CFG->dirroot . '/grade/lib.php');
		require_once($CFG->dirroot . '/grade/report/overview/lib.php'); // in turn includes others
		$can_access = false;
		if (has_capability('moodle/grade:viewall', $systemcontext)) {
			// Ok - can view all course grades.
			$can_access = true;

		} else if (has_capability('moodle/grade:viewall', $context)) {
			// Ok - can view any grades in context.
			$can_access = true;

		} else if ($userid == $USER->id and ((has_capability('moodle/grade:view', $context) and $course->showgrades) || $courseid == SITEID)) {
			// Ok - can view own course grades.
			$can_access = true;

		} else if (has_capability('moodle/grade:viewall', $personalcontext) and $course->showgrades) {
			// Ok - can view grades of this user - parent most probably.
			$can_access = true;
		} else if (has_capability('moodle/user:viewuseractivitiesreport', $personalcontext) and $course->showgrades) {
			// Ok - can view grades of this user - parent most probably.
			$can_access = true;
		}

		if (!$can_access) {
			return "Hidden";
		}

		$gpr = new grade_plugin_return(array('type'=>'report', 'plugin'=>'overview', 'courseid'=>$course->id, 'userid'=>$userid));
		grade_regrade_final_grades_if_required($course);
		$canviewhidden = has_capability('moodle/grade:viewhidden', $context);
		$course_item = grade_item::fetch_course_item($course->id);
		$course_grade = new grade_grade(array('itemid'=>$course_item->id, 'userid'=>$userid));
		$course_grade->grade_item =& $course_item;
		$finalgrade = $course_grade->finalgrade;
		if (!is_null($finalgrade)) {
			$course_item->grademin = $course_grade->get_grade_min();
			$course_item->grademax = $course_grade->get_grade_max();
		}
		return grade_format_gradevalue($finalgrade, $course_item, true);
	}

	private static function get_certificate($course, $userid) {
		global $DB, $OUTPUT, $USER;

		// get the certificate instance id (cmid)
		$activities = get_coursemodules_in_course('customcert', $course->id);
		foreach ($activities as $activity) {
			$cmid = $activity->instance;
			break;
		}

		if (!isset($cmid)) return "-";

		// get the certificate definition record
		$customcert = $DB->get_record('customcert', array('id' => $cmid), '*', MUST_EXIST);

		// the latest certificate
		$issue = $DB->get_records('customcert_issues', array('userid' => $userid, 'customcertid' => $customcert->id), 'timecreated DESC', '*', 0, 1);
		if (!$issue) return "-";
		$issue = array_pop($issue);

		// can't pick up another persons' certificate, so just report the code for it
		if ($USER->id !== $userid) {
			return $issue->code;
		}

		// make a button for it
		$linkname = get_string('getcustomcert', 'customcert');
		$link = new moodle_url('/mod/customcert/my_certificates.php', array('userid' => $userid, 'downloadcert' => true, 'certificateid' => $customcert->id));
		// $link = new moodle_url('/mod/customcert/view.php', array('id' => $cmid, 'action' => 'download'));
		$downloadbutton = new single_button($link, $linkname);
		$downloadbutton->add_action(new popup_action('click', $link, 'customcertpopup', array('height' => 600, 'width' => 800)));
		$downloadbutton = html_writer::tag('div', $OUTPUT->render($downloadbutton), array('style' => 'text-align:center'));

		return $downloadbutton;

	}

	// the routine that populates the content of the block
	public function get_content() {
		global $CFG, $OUTPUT, $USER, $DB, $SESSION, $PAGE;

		require_once($CFG->dirroot."/auth/mpm2moodle/lib.php");

		if ($this->content !== null) {
		   return $this->content;
		}

		$html = "";
		$my_practice = $USER->institution ?: "Administrators";
		$my_role = $USER->department;
		$manager_role = get_config('auth/mpm2moodle', 'managerRoleName');
		$enrol_managers_as_teachers = (get_config('auth/mpm2moodle', 'managerEnrolAsTeacher') == true);
		$json = get_config("block_course_status_tracker", $my_practice);
		if (isset($json)) {
			$enrolmentdata = json_decode($json, true);
		}
		$roles = array_map('trim', explode(',', get_config('auth/mpm2moodle', 'roleNames')));
		$is_manager = (strtolower($manager_role) == strtolower($my_role));
		$i_am_a_manager = ($is_manager || is_siteadmin());

		$teachers = explode(",", get_config("auth/mpm2moodle","teachers") ?: "");
		$i_am_a_teacher = in_array($USER->idnumber, $teachers);

		if (isset($SESSION->mpm2moodle) && $is_manager) { // but NOT is_siteadmin() !
			$userdata = $SESSION->mpm2moodle; // a copy of the decryped user data in memory
			if (isset($userdata) && !empty($userdata)) {
				$switchRoleTo = ($i_am_a_teacher) ? "student" : "teacher";
				$button_text = get_string(($i_am_a_teacher) ? "switch_role_student" : "switch_role_teacher", "block_course_status_tracker");
				$role_switch_text = get_string(($i_am_a_teacher) ? "current_role_teacher" : "current_role_student", "block_course_status_tracker") . " &middot; ";
				$link_class = ($i_am_a_teacher) ? "btn-info" : "btn-warning";

				$html .= html_writer::start_tag("div", array("class" => "role-switch clearfix"));
				$html .= html_writer::start_tag("div", array("class" => "pull-right"));
				$html .= html_writer::tag("span", $role_switch_text, array("class" => "role_switch_text"));
				$html .= html_writer::link(auth_plugin_mpm2moodle_lib::build_url($userdata,$switchRoleTo), $button_text, array("class" => "btn " . $link_class));
				$html .= html_writer::end_tag("div");
				$html .= html_writer::end_tag("div");
			}
		}

		$courses = self::all_courses();

		$this->content = new stdClass;
		$this->content->text = "";

		if ($CFG->enablecompletion) {

			// go through all the courses that I can see
			foreach ($courses as $course) {
				// is this course on the list of courses for my role (implies this course is on the list of courses for my practice)
				if (self::is_enrollable($enrolmentdata, $my_role, $course["id"], $i_am_a_manager)) {
					// managers can get enrolled as teachers
					if (($i_am_a_manager && $enrol_managers_as_teachers) || $i_am_a_teacher) {
						$roleid = $DB->get_field('role', 'id', array('shortname' => 'teacher')); // teacher = 4, editingteacher = 3, coursecreator = 2, manager = 1
					} else {
						$roleid = $DB->get_field('role', 'id', array('shortname' => 'student')); // 5
					}
					// ensure the user is enrolled into the course in the correct role, and ensure they are a member of their own group in that course (created on the fly where required)
					self::enrol_into_course($course["id"], $USER, $roleid);
				}
			}

			$table = new html_table();
			$table->data = array();
			$enrollment_count = 0;

			// if I am the role that manages other people
			if ($i_am_a_manager) {
				$table->head = array('Role', 'Course', 'User', 'Status', 'Enrolled', 'Completed', 'Certificate');
				// go through the roles
				foreach ($roles as $role) {
					$needs_header = true;
					$cell = new html_table_cell($role);
					$cell->colspan = count($table->head);
					$cell->attributes['class'] = 'role-name-header';
					$header = new html_table_row(array($cell));
					// list the users in those roles
					$members = self::all_users($my_practice, $role);
					foreach ($members as $member) {
						$member_courses = enrol_get_users_courses($member->id, false, 'id, fullname, showgrades');
						$enrollment_count = count($member_courses);
						// go through the enrolments for that user
						foreach ($member_courses as $course) {
							// if at least one user is enrolled for this role, render the header for it once
							if ($needs_header) {
								$table->data[] = $header;
								$needs_header = false;
							}
							// list the user statuses in this course
							$cells = [];
							$cells[] = ''; // first cell is empty
							$cells[] = html_writer::link(new moodle_url("/course/view.php", array("id" => $course->id)), $course->fullname);
							$cells[] = $member->displayname;
							// $cells[] = html_writer::link(new moodle_url("/user/view.php", array("id" => $member->id, "course" => $course->id)), $member->displayname);

							$record = $DB->get_record_sql('SELECT CASE WHEN timestarted = 0 THEN timeenrolled ELSE timestarted END commenced FROM {course_completions} WHERE course = ? and userid = ?', array($course->id, $member->id));
							$commencementDate = "-";
							$status = "Not started";
							if ($record) {
								$commencementDate = userdate($record->commenced, '%a, %e %b %Y %I:%M %p');
								$status = "Incomplete";
							}

							$completion = $DB->get_record_sql('SELECT gradefinal, timecompleted FROM {course_completion_crit_compl} WHERE course = ? and userid = ?', array($course->id, $member->id));
							if ($completion) {
								$cells[] = "Completed";
								$cells[] = $commencementDate;
								$cells[] = userdate($completion->timecompleted, '%a, %e %b %Y %I:%M %p');
								// $cells[] = self::get_user_grade($course, $member->id);
								$cells[] = self::get_certificate($course, $member->id);
							} else {
								$cells[] = $status;
								$cells[] = $commencementDate;
								$cells[] = "-";
								$cells[] = "-";
								// $cells[] = "-";
							}
							$table->data[] = $cells;
						}
					}
				}
			} else {
				$table->head = array('Course', 'Status', 'Enrolled', 'Completed', 'Grade', 'Certificate');
				$member_courses = enrol_get_users_courses($USER->id, false, 'id, fullname,showgrades');
				// go through the courses that I am enrolled in
				foreach ($member_courses as $course) {
					// list the statuses in this course
					$cells = [];
					$cells[] = html_writer::link(new moodle_url("/course/view.php", array("id" => $course->id)), $course->fullname);
					$record = $DB->get_record_sql('SELECT CASE WHEN timestarted = 0 THEN timeenrolled ELSE timestarted END commenced FROM {course_completions} WHERE course = ? and userid = ?', array($course->id, $USER->id));
					$commencementDate = "-";
					$status = "Not started";
					if ($record) {
						$commencementDate = userdate($record->commenced, '%a, %e %b %Y');
						$status = "Incomplete";
					}
					$completion = $DB->get_record_sql('SELECT gradefinal, timecompleted FROM {course_completion_crit_compl} WHERE course = ? and userid = ?', array($course->id, $USER->id));
					if ($completion) {
						$cells[] = "Completed";
						// date format: http://php.net/manual/en/function.strftime.php
						$cells[] = $commencementDate;
						$cells[] = userdate($completion->timecompleted, '%a, %e %b %Y %I:%M %p');
						$cells[] = self::get_user_grade($course, $USER->id); // $completion->gradefinal;
						$cells[] = self::get_certificate($course, $USER->id);
					} else {
						$cells[] = $status;
						$cells[] = $commencementDate;
						$cells[] = "-";
						$cells[] = "-";
						$cells[] = "-";
					}
					$table->data[] = $cells;
				}
			}

			$html .= html_writer::table($table);

			// if I am the role that manages other people
			if ($i_am_a_manager) {

				require_once('manager_form.php');
				$mform = new block_course_status_tracker_form(null, array("courses" => $courses, "roles" => $roles, "rolename" => $manager_role, "enrollment_count" => $enrollment_count));

				if ($mform->get_data()) {
					// Params from form post
					$formdata = $mform->get_data();
					$enrolment_matrix = [];
					foreach ($roles as $role) {
						foreach ($courses as $course) {
							$key = "enrol_" . md5($role . "_" . $course["id"]);
							$value = $formdata->$key;
							$enrolment_matrix[$key] = $value;
						}
					}
					set_config($my_practice, json_encode($enrolment_matrix, JSON_NUMERIC_CHECK), "block_course_status_tracker");
				} else {
					$instancedata = json_decode(get_config("block_course_status_tracker",$my_practice), true);
					$mform->set_data($instancedata);
				}

				// course enrolment manager
				$html .= $OUTPUT->box_start();
				$heading = get_string("course_enrolment_matrix", "block_course_status_tracker");
				$html .= print_collapsible_region_start('', 'id_enrolments', '<h4 class="clearfix" style="display:inline-block">' . $heading . '</h4>', 'block_course_status_tracker_matrix', ($enrollment_count === 0), true);

				$form_html = $mform->render(); // moodle produces invalid html, so ...
				$form_html = str_replace(" />", ">", $form_html);
				$form_html = str_replace('<fieldset class="hidden"><div>', '', $form_html);
				$form_html = str_replace('</div></fieldset>', '', $form_html);
				$html .= $form_html;

				$html .= print_collapsible_region_end(true);
				$html .= $OUTPUT->box_end();

				// user management, not for site admins who won't be a member of the cohort
				if (!is_siteadmin()) {
					$cohort = $DB->get_record('cohort', array('name'=>$my_practice), '*', MUST_EXIST);
					$cohort_context = context::instance_by_id($cohort->contextid, MUST_EXIST);
					$activeuserselector = new user_active_selector('activeselect', array('cohortid'=>$cohort->id, 'accesscontext'=>$cohort_context, 'exclude' => array($USER->id)));
					$inactiveuserselector = new user_inactive_selector('inactiveselect', array('cohortid'=>$cohort->id, 'accesscontext'=>$cohort_context));

					// set users as suspended
					if (optional_param('deactivate', false, PARAM_BOOL) && confirm_sesskey()) {
					    $records = $activeuserselector->get_selected_users();
					    if (!empty($records)) {
					        foreach ($records as $record) {
					        	$record->suspended = 1;
					        	$DB->update_record("user", $record);
					        }
					        $activeuserselector->invalidate_selected_users();
					        $inactiveuserselector->invalidate_selected_users();
					    }
					}

					// set users as active
					if (optional_param('activate', false, PARAM_BOOL) && confirm_sesskey()) {
					    $records = $inactiveuserselector->get_selected_users();
					    if (!empty($records)) {
					        foreach ($records as $record) {
					        	$record->suspended = 0;
					        	$DB->update_record("user", $record);
					        }
					        $activeuserselector->invalidate_selected_users();
					        $inactiveuserselector->invalidate_selected_users();
					    }
					}


					// yeah, this is a bit ugly, but we arent using a $mform instance so it works differently
					$SESSKEY = sesskey();
					$stringActiveUsers = get_string("activeusers");
					$stringInactiveUsers = get_string("inactive") . ' ' . get_string("users"); // really?
					$stringUserManagement = get_string("user_management_text", "block_course_status_tracker", $manager_role);
					$heading = get_string("user_management", "block_course_status_tracker");

					$html .= $OUTPUT->box_start();
					$html .= print_collapsible_region_start('', 'id_members', '<h4 class="clearfix" style="display:inline-block">' . $heading . '</h4>', 'block_course_status_tracker_members', true, true);
					$html .= <<<MYFORM
						<p>$stringUserManagement</p>
						<form id="assignform" method="post" action="$PAGE->url" class="mform"><div>
						  <input type="hidden" name="sesskey" value="$SESSKEY" />

						  <table summary="" class="generaltable generalbox boxaligncenter" cellspacing="0">
						    <tr>
						      <td id="existingcell">
						          <p><label for="removeselect">$stringActiveUsers</label></p>
MYFORM;
					$html .= $activeuserselector->display(true);
					$html .= <<<MYFORM
						      </td>
						      <td id="buttonscell">
						          <div id="addcontrols">
						              <input name="deactivate" id="deactivate" type="submit" value="&gt;&nbsp;Deactivate" /><br />
						          </div>

						          <div id="removecontrols">
						              <input name="activate" id="activate" type="submit" value="Activate&nbsp;&lt;" />
						          </div>
						      </td>
						      <td id="potentialcell">
						          <p><label for="addselect">$stringInactiveUsers</label></p>
MYFORM;
					$html .= $inactiveuserselector->display(true);
					$html .= <<<MYFORM
						      </td>
						    </tr>
						  </table>
						</div></form>
MYFORM;
					$html .= print_collapsible_region_end(true);
					$html .= $OUTPUT->box_end();
				}

			}

			$this->content->text = $html;
	   } else {
			$this->content->text .= get_string('coursecompletion_setting', 'block_course_status_tracker');
		}
		return $this->content;
	}

}
