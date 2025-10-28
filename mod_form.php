<?php

require_once($CFG->dirroot.'/course/moodleform_mod.php');

class mod_flashcards_mod_form extends moodleform_mod {
    public function definition() {
        $mform = $this->_form;

        $mform->addElement('text', 'name', get_string('flashcardsname', 'mod_flashcards'), ['size' => '64']);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');

        $this->standard_intro_elements();

        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }
}

