<?php

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once($CFG->libdir . '/formslib.php');

class block_course_status_tracker_form extends moodleform {

	function definition() {

		$courses = $this->_customdata['courses'];
		$roles = $this->_customdata['roles'];
		$rolename = $this->_customdata['rolename'];
		$enrollment_count = $this->_customdata['enrollment_count']; // will be zero on first display
		$mform = & $this->_form;

		// $mform->addElement('header', 'general', get_string('form', 'block_course_status_tracker'));
		// $mform->addHelpButton('general', 'form', 'block_course_status_tracker');
		 $mform->addElement('html', html_writer::tag('p', get_string('form_text', 'block_course_status_tracker', $rolename)));

		// if ($enrollment_count > 0) { // once we have saved the enrolment data, we can auto-hide this section
		// 	$mform->setExpanded("general", false);
		// }

		$head = array("<i class='fa fa-angle-down'></i>Role \\ <span class='pull-right'>Course <i class='fa fa-angle-right'></i></span>");
		foreach ($courses as $course) {
			$head[] = $course["shortname"];
		}
		$mform->addElement('html', html_writer::start_tag('table', array('class' => 'mform-table course-enrolment-management')));
		$mform->addElement('html', html_writer::start_tag('thead'));
		$mform->addElement('html', html_writer::start_tag('tr'));
		foreach ($head as $cell) {
			$mform->addElement('html', html_writer::tag("th", $cell));
		}
		$mform->addElement('html', html_writer::end_tag('tr'));
		$mform->addElement('html', html_writer::end_tag('thead'));
		$mform->addElement('html', html_writer::start_tag('tbody'));
		for ($i = 0; $i < count($roles); $i++) {
		// foreach($roles as $role) {
			$role = $roles[$i];
			$mform->addElement('html', html_writer::start_tag('tr'));
			$mform->addElement('html', html_writer::start_tag('td'));
			$mform->addElement('html', $role);
			// $this->add_checkbox_controller($i + 1); // doesn't seem to like zeroth item
			$mform->addElement('html', html_writer::end_tag('td'));
			foreach ($courses as $course) {
				$mform->addElement('html', html_writer::start_tag('td'));
				$key = "enrol_" . md5($role . "_" . $course["id"]); // in case role names change order
				$mform->addElement('advcheckbox', $key, null, null, array('group' => $i + 1), array(0,1));
				$mform->setDefault($key, 1); // not necessarily the actual state
				$mform->addElement('html', html_writer::end_tag('td'));
			}
			$mform->addElement('html', html_writer::end_tag('tr'));
		}
		$mform->addElement('html', html_writer::end_tag('tbody'));
		$mform->addElement('html', html_writer::end_tag('table'));

		// Buttons
		$this->add_action_buttons(false, get_string('submit'));
	}

}