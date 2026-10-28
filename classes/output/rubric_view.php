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

namespace qbehaviour_rubricgraded\output;

use qbehaviour_rubricgraded;
use qbehaviour_rubricgraded\display_settings;
use qbehaviour_rubricgraded\rubric;

/**
 * A rubric, for display in one of three modes.
 *
 * - preview: the rubric before grading, with no selections.
 * - grade: the rubric as a form, for a grader to choose levels and add remarks.
 * - review: the rubric with the chosen levels (and remarks) highlighted.
 *
 * @package    qbehaviour_rubricgraded
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rubric_view implements \renderable, \templatable {
    /** @var string display the rubric before grading. */
    public const MODE_PREVIEW = 'preview';

    /** @var string display the rubric as a grading form. */
    public const MODE_GRADE = 'grade';

    /** @var string display the rubric with the levels chosen by the grader. */
    public const MODE_REVIEW = 'review';

    /**
     * Constructor.
     *
     * @param rubric $rubric the rubric to display.
     * @param string $mode one of the MODE_... constants.
     * @param display_settings $settings controls level order and whether remarks are enabled.
     * @param bool $showscores whether to show the points for each level.
     * @param bool $showremarks whether to show remarks (review mode) or remark inputs (grade mode).
     * @param \context $context the context to format text in.
     * @param int[] $selections 1-based criterion position => 1-based level position.
     * @param string[] $remarks 1-based criterion position => remark.
     * @param \question_attempt|null $qa the question attempt, required in grade mode for field names and marks.
     * @param int $markdp the number of decimal places to show marks to.
     */
    public function __construct(
        /** @var rubric the rubric to display. */
        protected rubric $rubric,
        /** @var string one of the MODE_... constants. */
        protected string $mode,
        /** @var display_settings controls level order and whether remarks are enabled. */
        protected display_settings $settings,
        /** @var bool whether to show the points for each level. */
        protected bool $showscores,
        /** @var bool whether to show remarks (review mode) or remark inputs (grade mode). */
        protected bool $showremarks,
        /** @var \context the context to format text in. */
        protected \context $context,
        /** @var int[] 1-based criterion position => 1-based level position. */
        protected array $selections = [],
        /** @var string[] 1-based criterion position => remark. */
        protected array $remarks = [],
        /** @var \question_attempt|null the question attempt, required in grade mode for field names and marks. */
        protected ?\question_attempt $qa = null,
        /** @var int the number of decimal places to show marks to. */
        protected int $markdp = 2,
    ) {
        if ($mode === self::MODE_GRADE && !$qa) {
            throw new \coding_exception('A question attempt is required to display a rubric for grading.');
        }
    }

    #[\Override]
    public function export_for_template(\renderer_base $output): array {
        $isgrade = $this->mode === self::MODE_GRADE;
        $uniqid = \html_writer::random_id('qbehaviour_rubricgraded');

        $criteria = [];
        foreach ($this->rubric->get_criteria() as $position => $criterion) {
            $levels = [];
            foreach ($criterion['levels'] as $levelposition => $level) {
                $selected = ($this->selections[$position] ?? 0) === $levelposition;
                $leveldata = [
                    'position' => $levelposition,
                    'definition' => $this->format_plain($level['definition']),
                    'score' => $level['score'],
                    'scoreformatted' => format_float($level['score'], 2, true, true),
                    'selected' => $selected,
                ];
                if ($isgrade) {
                    $leveldata['inputname'] = $this->qa->get_behaviour_field_name(
                        qbehaviour_rubricgraded::level_var($position)
                    );
                    $leveldata['inputid'] = $leveldata['inputname'] . '_' . $levelposition;
                }
                $levels[] = $leveldata;
            }
            if (!$this->settings->sortlevelsasc) {
                $levels = array_reverse($levels);
            }

            $remark = $this->remarks[$position] ?? '';
            $criteriondata = [
                'position' => $position,
                'descriptionid' => $uniqid . '_c' . $position,
                'description' => $this->format_plain($criterion['description']),
                'levels' => $levels,
            ];
            if ($isgrade) {
                $remarkname = $this->qa->get_behaviour_field_name(qbehaviour_rubricgraded::remark_var($position));
                if ($this->showremarks) {
                    $criteriondata['remarkeditable'] = true;
                    $criteriondata['remarkinputname'] = $remarkname;
                    $criteriondata['remarkinputid'] = $remarkname . '_id';
                    $criteriondata['remark'] = $remark;
                } else if ($remark !== '') {
                    // Remarks are switched off, but keep any made earlier.
                    $criteriondata['remarkhidden'] = true;
                    $criteriondata['remarkinputname'] = $remarkname;
                    $criteriondata['remark'] = $remark;
                }
            } else if ($this->showremarks && $remark !== '') {
                $criteriondata['remarkvisible'] = true;
                $criteriondata['remarkformatted'] = $this->format_plain($remark);
            }
            $criteria[] = $criteriondata;
        }

        $data = [
            'uniqid' => $uniqid,
            'mode' => $this->mode,
            'isgrade' => $isgrade,
            'ispreview' => $this->mode === self::MODE_PREVIEW,
            'isreview' => $this->mode === self::MODE_REVIEW,
            'showscores' => $this->showscores,
            'criteria' => $criteria,
        ];
        if ($isgrade) {
            $data += $this->export_grading_data();
        }
        return $data;
    }

    /**
     * Get the extra template data needed to show the calculated mark while grading.
     *
     * @return array
     */
    protected function export_grading_data(): array {
        $fraction = $this->rubric->calculate_fraction($this->selections);
        $maxmark = (float) $this->qa->get_max_mark();
        $data = [
            'rubricinputname' => $this->qa->get_behaviour_field_name(qbehaviour_rubricgraded::RUBRIC_VAR),
            'lockzeropoints' => (int) $this->rubric->is_lockzeropoints(),
            'minscore' => $this->rubric->get_min_score(),
            'maxscore' => $this->rubric->get_max_score(),
            'maxmark' => $maxmark,
            'markdp' => $this->markdp,
            'hasmark' => $maxmark > 0,
            'complete' => $fraction !== null,
            'totalscore' => format_float($this->rubric->calculate_score($this->selections), 2, true, true),
            'maxscoreformatted' => format_float($this->rubric->get_max_score(), 2, true, true),
            'maxmarkformatted' => $this->qa->format_max_mark($this->markdp),
            'decsep' => get_string('decsep', 'langconfig'),
        ];
        if ($fraction !== null) {
            $data['markformatted'] = $this->qa->format_fraction_as_mark($fraction, $this->markdp);
        }
        return $data;
    }

    /**
     * Format plain text (criterion descriptions, level definitions and remarks) for output.
     *
     * @param string $text
     * @return string HTML
     */
    protected function format_plain(string $text): string {
        return format_text($text, FORMAT_PLAIN, ['context' => $this->context, 'para' => false]);
    }
}
