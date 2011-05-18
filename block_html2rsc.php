<?php

class block_html2rsc extends block_base {
    function init() {
        $this->title = 'HTML file to resource';
        $this->version = 2011050300;
    }

    // only one instance of this block is required
    function instance_allow_multiple() {
      return false;
    } //instance_allow_multiple
    
    // label and button values can be set in admin
    function has_config() {
      return false;
    } //has_config

    
    function get_content() {
        global $CFG, $COURSE;

        if($this->content !== NULL) {
            return $this->content;
        }

        if (empty($this->instance)) {
            return '';
        }

        $this->content = new object();
//        $options = new object();
//        $options->noclean = true;    // Don't clean Javascripts etc
        
        require_once('html2rsc_choosefilesform.php');
        $form = new html2rsc_choosefilesform();
        
        $this->content->text .='This converts an HTML file from the course files area and all the linked course-local HTML files to Moodle HTML resources.  These newly-created HTML resources will be added to the first section of the course.';
        
        $this->content->text .=$form->_form->toHtml(); 
//                '<form action="' . $CFG->wwwroot . '/blocks/html2rsc/choosefiles.php" method="post">'
//                . '<input type="hidden" value="'.$COURSE->id.'" name="courseid" />'
//                . '<input type="hidden" value="'.sesskey().'" name="sesskey" />'
//                . '<button id="searchform_button" type="submit">Begin...</button>'
//                . '</form>';

        $this->content->footer = '';

        return $this->content;
    }

}
