<?php
global $CFG;

require_once($CFG->dirroot.'/lib/formslib.php');
class html2rsc_choosefilesform extends moodleform {
    
    function definition() {
        $this->setup_elements($this->_form);
    }
    
    function setup_elements(MoodleQuickForm $mform) {
        global $COURSE, $CFG;//, $RESOURCE_WINDOW_OPTIONS;
        $mform->setAttributes(array('id'=>'frm_html2rsc', 'action'=>"$CFG->wwwroot/blocks/html2rsc/processform.php", 'method'=>'post'));
        $mform->addElement('hidden', 'courseid', $COURSE->id);
        $mform->addElement('choosecoursefile', 'reference', get_string('location'), null, array('maxlength' => 255, 'size' => 18));
        $mform->addElement('submit', 'submit', 'Submit');
    }
}