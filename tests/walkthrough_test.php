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

namespace qbehaviour_rubricgraded;

use question_display_options;
use question_state;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/../../../engine/lib.php');
require_once(__DIR__ . '/../../../engine/tests/helpers.php');

/**
 * Unit tests for the rubric graded behaviour.
 *
 * These use the essay with rubric question type, which is the question type this behaviour is for.
 * Its 'editor' test question has a rubric with two criteria: Argument (0, 1 or 2 points)
 * and Spelling (0 or 1 point).
 *
 * @package    qbehaviour_rubricgraded
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \qbehaviour_rubricgraded
 * @covers     \qbehaviour_rubricgraded_renderer
 */
final class walkthrough_test extends \qbehaviour_walkthrough_test_base {
    #[\Override]
    protected function setUp(): void {
        global $PAGE;
        parent::setUp();
        // Rendering the comment editor needs a page.
        $PAGE->set_url('/');
    }

    /**
     * Start an attempt at a rubric question, answer it, and finish it, so it needs grading.
     *
     * @param string $which which test question to use.
     * @return \qtype_essayrubric_question
     */
    protected function start_finished_attempt(string $which = 'editor'): \qtype_essayrubric_question {
        $question = \test_question_maker::make_question('essayrubric', $which);
        $this->start_attempt_at_question($question, 'deferredfeedback', 3);
        $this->process_submission(['answer' => 'This is my wonderful essay!', 'answerformat' => FORMAT_HTML]);
        $this->finish();
        return $question;
    }

    public function test_rubric_question_uses_this_behaviour(): void {
        $this->start_finished_attempt();

        $this->assertEquals('rubricgraded', $this->get_question_attempt()->get_behaviour_name());
        $this->check_current_state(question_state::$needsgrading);
        $this->check_current_mark(null);
    }

    public function test_grading_calculates_mark_from_rubric(): void {
        $this->start_finished_attempt();

        // Strong argument (2 points) and good spelling (1 point): 3 out of 3.
        $this->process_submission(['-rubric' => 1, '-rc1' => 3, '-rc2' => 2]);
        $this->check_current_state(question_state::$mangrright);
        $this->check_current_mark(3);

        // Adequate argument (1 point) and poor spelling (0 points): 1 out of 3.
        $this->process_submission(['-rubric' => 1, '-rc1' => 2, '-rc2' => 1]);
        $this->check_current_state(question_state::$mangrpartial);
        $this->check_current_mark(1);

        // Nothing: 0 out of 3.
        $this->process_submission(['-rubric' => 1, '-rc1' => 1, '-rc2' => 1]);
        $this->check_current_state(question_state::$mangrwrong);
        $this->check_current_mark(0);
    }

    public function test_posted_mark_is_ignored(): void {
        $this->start_finished_attempt();

        // The mark is not expected data, so a posted mark cannot override the rubric.
        $this->process_submission(['-rubric' => 1, '-rc1' => 2, '-rc2' => 1, '-mark' => 3, '-maxmark' => 3]);
        $this->check_current_mark(1);
    }

    public function test_incomplete_rubric_still_needs_grading(): void {
        $this->start_finished_attempt();

        $this->process_submission(['-rubric' => 1, '-rc1' => 3]);
        $this->check_current_state(question_state::$needsgrading);
        $this->check_current_mark(null);
        $this->assertEquals([1 => 3], $this->get_question_attempt()->get_behaviour()->get_selections(
            $this->get_question_attempt()->get_last_step()
        ));

        // Completing the rubric grades the question.
        $this->process_submission(['-rubric' => 1, '-rc1' => 3, '-rc2' => 1]);
        $this->check_current_state(question_state::$mangrpartial);
        $this->check_current_mark(2);
    }

    public function test_unchanged_grading_is_discarded(): void {
        $this->start_finished_attempt();

        $this->process_submission(['-rubric' => 1, '-rc1' => 3, '-rc2' => 2]);
        $numsteps = $this->get_step_count();

        $this->process_submission(['-rubric' => 1, '-rc1' => 3, '-rc2' => 2]);
        $this->check_step_count($numsteps);

        // A new remark with the same levels is a change.
        $this->process_submission(['-rubric' => 1, '-rc1' => 3, '-rc2' => 2, '-rr2' => 'Check your apostrophes.']);
        $this->check_step_count($numsteps + 1);
        $this->check_current_mark(3);

        // A different level with the same total is a change too.
        $this->process_submission(['-rubric' => 1, '-rc1' => 3, '-rc2' => 1, '-rr2' => 'Check your apostrophes.']);
        $this->check_step_count($numsteps + 2);
        $this->check_current_mark(2);
    }

    public function test_invalid_level_is_rejected(): void {
        $this->start_finished_attempt();

        $this->expectException(\coding_exception::class);
        $this->process_submission(['-rubric' => 1, '-rc1' => 3, '-rc2' => 3]);
    }

    public function test_lockzeropoints(): void {
        // The 'minpoints' rubric scores between 2 and 5 points.
        $question = $this->start_finished_attempt('minpoints');

        // With lockzeropoints, the lowest levels give 2 out of 5 points.
        $this->process_submission(['-rubric' => 1, '-rc1' => 1, '-rc2' => 1]);
        $this->check_current_mark(3 * 2 / 5);

        // Without it, the lowest levels give a mark of 0 and one step up gives 1 out of 3.
        $question->rubric = new rubric(\qtype_essayrubric_test_helper::get_rubric_data('minpoints')['criteria'], false);
        $this->quba->regrade_question($this->slot, true);
        $this->check_current_mark(0);
        $this->process_submission(['-rubric' => 1, '-rc1' => 2, '-rc2' => 1]);
        $this->check_current_mark(1);
    }

    public function test_regrade_replays_rubric_with_new_scores(): void {
        $this->start_finished_attempt();
        $this->process_submission(['-rubric' => 1, '-rc1' => 2, '-rc2' => 2]);
        $this->check_current_mark(2);

        // A new version with the same shape, where adequate arguments are worth 2 points, not 1.
        $newversion = \test_question_maker::make_question('essayrubric', 'editor');
        $criteria = \qtype_essayrubric_test_helper::get_rubric_data('editor')['criteria'];
        $criteria[0]['levels'][1]['score'] = 2;
        $newversion->rubric = new rubric($criteria, true);
        $this->quba->regrade_question($this->slot, true, null, $newversion);

        // Now 3 out of the maximum of 3 points.
        $this->check_current_state(question_state::$mangrright);
        $this->check_current_mark(3);
    }

    public function test_manual_grade_without_rubric_uses_mark(): void {
        $this->start_finished_attempt();

        $this->manual_grade('Not bad', 1.5, FORMAT_HTML);
        $this->check_current_state(question_state::$mangrpartial);
        $this->check_current_mark(1.5);
    }

    public function test_history_summary(): void {
        $this->start_finished_attempt();
        $this->process_submission(['-rubric' => 1, '-rc1' => 3, '-rc2' => 1]);

        $summary = $this->get_question_attempt()->summarise_action($this->get_question_attempt()->get_last_step());
        $this->assertStringContainsString('Graded with rubric', $summary);
        $this->assertStringContainsString('Argument: Strong (2)', $summary);
        $this->assertStringContainsString('Spelling: Poor (0)', $summary);
    }

    public function test_grading_form(): void {
        $this->start_finished_attempt();
        $this->process_submission(['-rubric' => 1, '-rc1' => 3, '-rc2' => 1]);

        $this->displayoptions->manualcomment = question_display_options::EDITABLE;
        $this->render();
        $prefix = $this->quba->get_field_prefix($this->slot);

        // A radio for each level, with the chosen levels checked.
        $this->assertTag($this->get_tag_matcher('input', ['type' => 'radio', 'name' => $prefix . '-rc1',
                'value' => '3', 'checked' => 'checked']), $this->currentoutput);
        $this->assertTag($this->get_tag_matcher('input', ['type' => 'radio', 'name' => $prefix . '-rc2',
                'value' => '1', 'checked' => 'checked']), $this->currentoutput);
        $this->assertNotTag($this->get_tag_matcher('input', ['type' => 'radio', 'name' => $prefix . '-rc2',
                'value' => '2', 'checked' => 'checked']), $this->currentoutput);
        $this->check_output_contains_hidden_input('-rubric', 1);
        $this->assertTag($this->get_tag_matcher('textarea', ['name' => $prefix . '-rr1']), $this->currentoutput);
        $this->assertTag($this->get_tag_matcher('textarea', ['name' => $prefix . '-comment']), $this->currentoutput);

        // There is no mark to enter, the rubric calculates it.
        $this->assertNotTag($this->get_tag_matcher('input', ['name' => $prefix . '-mark']), $this->currentoutput);
        $this->assertStringContainsString(get_string(
            'calculatedmark',
            'qbehaviour_rubricgraded',
            ['mark' => '2.00', 'max' => '3.00']
        ), $this->currentoutput);
    }

    public function test_review_shows_chosen_levels_and_remarks(): void {
        $this->start_finished_attempt();
        $this->process_submission(['-rubric' => 1, '-rc1' => 3, '-rc2' => 1, '-rr1' => 'A persuasive story.']);

        $this->displayoptions->manualcomment = question_display_options::VISIBLE;
        $this->displayoptions->marks = question_display_options::MARK_AND_MAX;
        $this->render();

        $this->assertTag(['tag' => 'div', 'attributes' => ['class' => 'level selected', 'data-level' => '3'],
                'descendant' => ['tag' => 'span', 'content' => 'Strong']], $this->currentoutput);
        $this->assertStringContainsString('A persuasive story.', $this->currentoutput);
        $this->assertStringContainsString(get_string('pointsx', 'qbehaviour_rubricgraded', 2), $this->currentoutput);
    }

    public function test_review_hides_points_without_marks(): void {
        $this->start_finished_attempt();
        $this->process_submission(['-rubric' => 1, '-rc1' => 3, '-rc2' => 1]);

        $this->displayoptions->manualcomment = question_display_options::VISIBLE;
        $this->displayoptions->marks = question_display_options::HIDDEN;
        $this->render();

        $this->assertStringContainsString('Strong', $this->currentoutput);
        $this->assertStringNotContainsString(get_string('pointsx', 'qbehaviour_rubricgraded', 2), $this->currentoutput);
    }
}
