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
 * Defines the renderer for the rubric graded behaviour.
 *
 * @package    qbehaviour_rubricgraded
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use qbehaviour_rubricgraded\output\rubric_view;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../manualgraded/renderer.php');

/**
 * Renderer for outputting parts of a question belonging to the rubric graded behaviour.
 *
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qbehaviour_rubricgraded_renderer extends qbehaviour_manualgraded_renderer {
    #[\Override]
    public function manual_comment_fields(question_attempt $qa, question_display_options $options) {
        /** @var qbehaviour_rubricgraded $behaviour */
        $behaviour = $qa->get_behaviour();
        $settings = $qa->get_question()->get_rubric_display_settings($options->context);

        // After a failed submission, redisplay what was submitted, otherwise the last grading.
        if ($qa->get_submitted_var($qa->get_behaviour_field_name(qbehaviour_rubricgraded::RUBRIC_VAR), PARAM_BOOL)) {
            $step = new question_attempt_step($qa->get_submitted_data());
        } else {
            $step = $qa->get_last_step_with_behaviour_var(qbehaviour_rubricgraded::RUBRIC_VAR);
        }

        $rubric = new rubric_view(
            $behaviour->get_rubric(),
            rubric_view::MODE_GRADE,
            $settings,
            $settings->showscoreteacher,
            $settings->enableremarks,
            $options->context,
            $behaviour->get_selections($step),
            $behaviour->get_remarks($step),
            $qa,
            $options->markdp
        );

        return $this->render_from_template('qbehaviour_rubricgraded/rubric', $rubric->export_for_template($this)) .
                html_writer::tag('fieldset', html_writer::tag(
                    'div',
                    $this->comment_field($qa, $options),
                    ['class' => 'fcontainer clearfix']
                ), ['class' => 'hidden']);
    }

    /**
     * The manual comment editor.
     *
     * This is the comment part of {@see qbehaviour_renderer::manual_comment_fields()}.
     * The mark part is left out, because the mark is calculated from the rubric.
     *
     * @param question_attempt $qa a question attempt.
     * @param question_display_options $options controls what should and should not be displayed.
     * @return string HTML fragment.
     */
    protected function comment_field(question_attempt $qa, question_display_options $options): string {
        global $CFG;

        require_once($CFG->dirroot . '/lib/filelib.php');
        require_once($CFG->dirroot . '/repository/lib.php');

        $inputname = $qa->get_behaviour_field_name('comment');
        $id = $inputname . '_id';
        [$commenttext, $commentformat, $commentstep] = $qa->get_current_manual_comment();

        $editor = editors_get_preferred_editor($commentformat);
        $strformats = format_text_menu();
        $formats = $editor->get_supported_formats();
        foreach ($formats as $fid) {
            $formats[$fid] = $strformats[$fid];
        }

        $draftitemareainputname = $qa->get_behaviour_field_name('comment:itemid');
        $draftitemid = optional_param($draftitemareainputname, false, PARAM_INT);

        if (!$draftitemid && $commentstep === null) {
            $commenttext = '';
            $draftitemid = file_get_unused_draft_itemid();
        } else if (!$draftitemid) {
            [$draftitemid, $commenttext] = $commentstep->prepare_response_files_draft_itemid_with_text(
                'bf_comment',
                $options->context->id,
                $commenttext
            );
        }

        $editor->set_text($commenttext);
        $editor->use_editor(
            $id,
            question_utils::get_editor_options($options->context),
            question_utils::get_filepicker_options($options->context, $draftitemid)
        );

        $commenteditor = html_writer::tag('div', html_writer::tag(
            'textarea',
            s($commenttext),
            ['id' => $id, 'name' => $inputname, 'rows' => 3, 'cols' => 60]
        ));
        $commenteditor .= html_writer::empty_tag(
            'input',
            ['type' => 'hidden', 'name' => $draftitemareainputname, 'value' => $draftitemid]
        );

        $editorformat = '';
        if (count($formats) == 1) {
            reset($formats);
            $editorformat .= html_writer::empty_tag('input', ['type' => 'hidden',
                    'name' => $inputname . 'format', 'value' => key($formats)]);
        } else {
            $editorformat = html_writer::start_tag('div', ['class' => 'fitem']);
            $editorformat .= html_writer::start_tag('div', ['class' => 'fitemtitle']);
            $editorformat .= html_writer::tag('label', get_string('format'), ['for' => 'menu' . $inputname . 'format']);
            $editorformat .= html_writer::end_tag('div');
            $editorformat .= html_writer::start_tag('div', ['class' => 'felement fhtmleditor']);
            $editorformat .= html_writer::select($formats, $inputname . 'format', $commentformat, '');
            $editorformat .= html_writer::end_tag('div');
            $editorformat .= html_writer::end_tag('div');
        }

        $comment = html_writer::tag(
            'div',
            html_writer::tag(
                'div',
                html_writer::tag(
                    'label',
                    get_string('comment', 'question'),
                    ['for' => $id]
                ),
                ['class' => 'fitemtitle']
            ) .
                html_writer::tag('div', $commenteditor, ['class' => 'felement fhtmleditor', 'data-fieldtype' => "editor"]),
            ['class' => 'fitem']
        );
        return $comment . $editorformat;
    }

    #[\Override]
    public function manual_comment_view(question_attempt $qa, question_display_options $options) {
        return $this->graded_rubric($qa, $options) . parent::manual_comment_view($qa, $options);
    }

    /**
     * Display the rubric with the levels chosen by the grader, if the question has been graded with it.
     *
     * @param question_attempt $qa a question attempt.
     * @param question_display_options $options controls what should and should not be displayed.
     * @return string HTML fragment.
     */
    public function graded_rubric(question_attempt $qa, question_display_options $options): string {
        $step = $qa->get_last_step_with_behaviour_var(qbehaviour_rubricgraded::RUBRIC_VAR);
        if (!$step->has_behaviour_var(qbehaviour_rubricgraded::RUBRIC_VAR)) {
            return '';
        }

        /** @var qbehaviour_rubricgraded $behaviour */
        $behaviour = $qa->get_behaviour();
        $settings = $qa->get_question()->get_rubric_display_settings($options->context);

        // Graders get a link to change the grade. Otherwise, this is someone being graded.
        if ($options->manualcommentlink) {
            $showscores = $settings->showscoreteacher;
            $showremarks = $settings->enableremarks;
        } else {
            $showscores = $settings->showscorestudent && $options->marks >= question_display_options::MARK_AND_MAX;
            $showremarks = $settings->enableremarks && $settings->showremarksstudent;
        }

        $rubric = new rubric_view(
            $behaviour->get_rubric(),
            rubric_view::MODE_REVIEW,
            $settings,
            $showscores,
            $showremarks,
            $options->context,
            $behaviour->get_selections($step),
            $behaviour->get_remarks($step)
        );
        return $this->render_from_template('qbehaviour_rubricgraded/rubric', $rubric->export_for_template($this));
    }
}
