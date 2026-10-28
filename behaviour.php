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
 * Question behaviour for questions that are graded manually using a rubric.
 *
 * @package    qbehaviour_rubricgraded
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use qbehaviour_rubricgraded\question_with_rubric;
use qbehaviour_rubricgraded\rubric;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../manualgraded/behaviour.php');

/**
 * Question behaviour for questions that are graded manually using a rubric.
 *
 * The attempt works exactly like manualgraded. When grading, the grader picks a level
 * for each rubric criterion (and optionally a remark), and the mark is calculated from
 * the rubric. The selections are stored as behaviour variables on the grading step:
 *
 *   -rubric  = 1, marks the step as a rubric grading step.
 *   -rc{n}   = the 1-based position of the level chosen for the n-th criterion.
 *   -rr{n}   = the remark for the n-th criterion.
 *
 * Because the selections are stored, regrading replays them against the rubric.
 *
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qbehaviour_rubricgraded extends qbehaviour_manualgraded {
    /** @var string behaviour variable marking a rubric grading step. */
    public const RUBRIC_VAR = 'rubric';

    /** @var question_definition&question_with_rubric the question being attempted. */
    protected $question;

    #[\Override]
    public function is_compatible_question(question_definition $question) {
        return parent::is_compatible_question($question) && $question instanceof question_with_rubric;
    }

    /**
     * Get the rubric used to grade this question.
     *
     * @return rubric
     */
    public function get_rubric(): rubric {
        return $this->question->get_rubric();
    }

    /**
     * Get the name of the behaviour variable holding the level chosen for a criterion.
     *
     * @param int $criterion 1-based criterion position.
     * @return string
     */
    public static function level_var(int $criterion): string {
        return 'rc' . $criterion;
    }

    /**
     * Get the name of the behaviour variable holding the remark for a criterion.
     *
     * @param int $criterion 1-based criterion position.
     * @return string
     */
    public static function remark_var(int $criterion): string {
        return 'rr' . $criterion;
    }

    #[\Override]
    public function get_expected_data() {
        if (!$this->qa->get_state()->is_finished()) {
            return [];
        }

        // Unlike the parent, there is no mark: it is calculated from the rubric.
        $vars = [
            'comment' => question_attempt::PARAM_RAW_FILES,
            'commentformat' => PARAM_INT,
            self::RUBRIC_VAR => PARAM_BOOL,
        ];
        for ($criterion = 1; $criterion <= $this->get_rubric()->count_criteria(); $criterion++) {
            $vars[self::level_var($criterion)] = PARAM_INT;
            $vars[self::remark_var($criterion)] = PARAM_TEXT;
        }
        return $vars;
    }

    #[\Override]
    public function process_action(question_attempt_pending_step $pendingstep) {
        if ($pendingstep->has_behaviour_var(self::RUBRIC_VAR)) {
            return $this->process_comment($pendingstep);
        }
        return parent::process_action($pendingstep);
    }

    /**
     * Get the level selections stored in a step.
     *
     * @param question_attempt_step $step a rubric grading step.
     * @return int[] 1-based criterion position => 1-based level position, for the criteria with a level.
     */
    public function get_selections(question_attempt_step $step): array {
        $selections = [];
        for ($criterion = 1; $criterion <= $this->get_rubric()->count_criteria(); $criterion++) {
            $level = (int) $step->get_behaviour_var(self::level_var($criterion));
            if ($level) {
                $selections[$criterion] = $level;
            }
        }
        return $selections;
    }

    /**
     * Get the remarks stored in a step.
     *
     * @param question_attempt_step $step a rubric grading step.
     * @return string[] 1-based criterion position => remark, for the criteria with a non-empty remark.
     */
    public function get_remarks(question_attempt_step $step): array {
        $remarks = [];
        for ($criterion = 1; $criterion <= $this->get_rubric()->count_criteria(); $criterion++) {
            $remark = (string) $step->get_behaviour_var(self::remark_var($criterion));
            if (trim($remark) !== '') {
                $remarks[$criterion] = $remark;
            }
        }
        return $remarks;
    }

    #[\Override]
    public function process_comment(question_attempt_pending_step $pendingstep) {
        if (!$pendingstep->has_behaviour_var(self::RUBRIC_VAR)) {
            // Not from the rubric grading form, for example question_attempt::manual_grade().
            return parent::process_comment($pendingstep);
        }

        if (!$this->qa->get_state()->is_finished()) {
            throw new coding_exception('Cannot manually grade a question before it is finshed.');
        }

        $rubric = $this->get_rubric();
        $selections = $this->get_selections($pendingstep);
        foreach ($selections as $criterion => $level) {
            if (!$rubric->is_valid_level($criterion, $level)) {
                throw new coding_exception('Invalid rubric level ' . $level . ' for criterion ' . $criterion .
                        ' when processing a manual grading action.', 'Question ' . $this->question->id .
                        ', slot ' . $this->qa->get_slot());
            }
        }

        if ($this->is_same_comment($pendingstep)) {
            return question_attempt::DISCARD;
        }

        // An incomplete rubric gives no mark, so the question still needs grading. This must not
        // throw, because the grading report saves a whole page of attempts in one transaction.
        $fraction = $rubric->calculate_fraction($selections);
        if ($fraction !== null) {
            $fraction = min($this->qa->get_max_fraction(), max($this->qa->get_min_fraction(), $fraction));
        }

        $pendingstep->set_fraction($fraction);
        $pendingstep->set_state($this->qa->get_state()->corresponding_commented_state($fraction));
        return question_attempt::KEEP;
    }

    #[\Override]
    protected function is_same_comment($pendingstep) {
        if (!$pendingstep->has_behaviour_var(self::RUBRIC_VAR)) {
            return parent::is_same_comment($pendingstep);
        }

        // The comment check is the same as in the parent.
        $previouscomment = $this->qa->get_last_behaviour_var('comment');
        $newcomment = $pendingstep->get_behaviour_var('comment');
        if (($previouscomment != $newcomment) && !(is_null($previouscomment) && html_is_blank($newcomment))) {
            return false;
        }
        if (!html_is_blank($newcomment)) {
            if ($this->qa->get_last_behaviour_var('commentformat') != $pendingstep->get_behaviour_var('commentformat')) {
                return false;
            }
        }

        // Compare with the whole of the last rubric grading step. Unselected levels are not
        // submitted, so looking back through steps variable by variable would be wrong.
        $laststep = $this->qa->get_last_step_with_behaviour_var(self::RUBRIC_VAR);
        $newselections = $this->get_selections($pendingstep);
        if (
            $this->get_selections($laststep) != $newselections ||
                $this->get_remarks($laststep) != $this->get_remarks($pendingstep)
        ) {
            return false;
        }

        // The rubric is the same, but check the mark too, in case it was later changed some other way.
        $previousfraction = $this->qa->get_fraction();
        $newfraction = $this->get_rubric()->calculate_fraction($newselections);
        if (is_null($previousfraction) || is_null($newfraction)) {
            return is_null($previousfraction) && is_null($newfraction);
        }
        return abs($newfraction - $previousfraction) < 0.0000001;
    }

    #[\Override]
    public function summarise_action(question_attempt_step $step) {
        if ($step->has_behaviour_var(self::RUBRIC_VAR)) {
            return $this->summarise_rubric_grading($step);
        }
        return parent::summarise_action($step);
    }

    /**
     * Summarise a rubric grading step, for the response history and privacy export.
     *
     * @param question_attempt_step $step a rubric grading step.
     * @return string
     */
    protected function summarise_rubric_grading(question_attempt_step $step): string {
        $criteria = $this->get_rubric()->get_criteria();
        $parts = [];
        foreach ($this->get_selections($step) as $criterion => $level) {
            if (!isset($criteria[$criterion]['levels'][$level])) {
                continue;
            }
            $parts[] = get_string('summarycriterion', 'qbehaviour_rubricgraded', [
                'criterion' => shorten_text($criteria[$criterion]['description'], 50),
                'level' => shorten_text($criteria[$criterion]['levels'][$level]['definition'], 50),
                'score' => format_float($criteria[$criterion]['levels'][$level]['score'], 2, true, true),
            ]);
        }

        $a = new stdClass();
        $a->rubric = implode('; ', $parts);
        $a->comment = '';
        if ($step->has_behaviour_var('comment')) {
            $a->comment = shorten_text(question_utils::to_plain_text(
                $step->get_behaviour_var('comment'),
                $step->get_behaviour_var('commentformat')
            ), 200);
        }

        $fraction = $step->get_fraction();
        if (is_null($fraction)) {
            return get_string('rubricincompletesummary', 'qbehaviour_rubricgraded', $a);
        }
        $a->mark = $this->qa->format_fraction_as_mark($fraction, 2);
        return get_string('rubricgradedsummary', 'qbehaviour_rubricgraded', $a);
    }
}
