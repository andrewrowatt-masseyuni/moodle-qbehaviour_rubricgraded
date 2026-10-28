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

/**
 * An immutable rubric: a list of criteria, each with a list of scored levels.
 *
 * Criteria and levels are identified by their 1-based position, never by a database id.
 * Positions are what get stored in question attempt step data, so they must stay stable
 * for a given question version (and across versions with the same shape, for regrading).
 *
 * @package    qbehaviour_rubricgraded
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class rubric {
    /** @var array[] criteria, indexed from 1, each ['description' => string, 'levels' => array indexed from 1]. */
    private array $criteria = [];

    /**
     * Constructor.
     *
     * @param array $criteria list of criteria in order. Each is an array or object with a 'description'
     *      and a list of 'levels', each level having a 'definition' and a 'score'.
     * @param bool $lockzeropoints if true, the fraction is the total score divided by the maximum score.
     *      If false, the minimum possible score maps to a fraction of 0.
     */
    public function __construct(
        array $criteria,
        /** @var bool whether the minimum score counts as zero when calculating the fraction. */
        private readonly bool $lockzeropoints = true,
    ) {
        $position = 1;
        foreach ($criteria as $criterion) {
            $criterion = (array) $criterion;
            $levels = [];
            $levelposition = 1;
            foreach ($criterion['levels'] as $level) {
                $level = (array) $level;
                $levels[$levelposition++] = [
                    'definition' => (string) $level['definition'],
                    'score' => (float) $level['score'],
                ];
            }
            $this->criteria[$position++] = [
                'description' => (string) $criterion['description'],
                'levels' => $levels,
            ];
        }
    }

    /**
     * Get the criteria.
     *
     * @return array[] indexed by 1-based position, each ['description' => string, 'levels' => array],
     *      levels indexed by 1-based position, each ['definition' => string, 'score' => float].
     */
    public function get_criteria(): array {
        return $this->criteria;
    }

    /**
     * Get the number of criteria.
     *
     * @return int
     */
    public function count_criteria(): int {
        return count($this->criteria);
    }

    /**
     * Whether the grade calculation treats the minimum score as zero.
     *
     * @return bool
     */
    public function is_lockzeropoints(): bool {
        return $this->lockzeropoints;
    }

    /**
     * Whether a level position is valid for a criterion.
     *
     * @param int $criterion 1-based criterion position.
     * @param int $level 1-based level position.
     * @return bool
     */
    public function is_valid_level(int $criterion, int $level): bool {
        return isset($this->criteria[$criterion]['levels'][$level]);
    }

    /**
     * Get the minimum achievable total score: the sum of each criterion's lowest level score.
     *
     * @return float
     */
    public function get_min_score(): float {
        $total = 0.0;
        foreach ($this->criteria as $criterion) {
            $total += min(array_column($criterion['levels'], 'score'));
        }
        return $total;
    }

    /**
     * Get the maximum achievable total score: the sum of each criterion's highest level score.
     *
     * @return float
     */
    public function get_max_score(): float {
        $total = 0.0;
        foreach ($this->criteria as $criterion) {
            $total += max(array_column($criterion['levels'], 'score'));
        }
        return $total;
    }

    /**
     * Get the total score for a set of selections.
     *
     * @param int[] $selections criterion position => level position. Criteria may be missing.
     * @return float
     */
    public function calculate_score(array $selections): float {
        $total = 0.0;
        foreach ($selections as $criterion => $level) {
            if ($this->is_valid_level($criterion, $level)) {
                $total += $this->criteria[$criterion]['levels'][$level]['score'];
            }
        }
        return $total;
    }

    /**
     * Whether a set of selections has a valid level for every criterion.
     *
     * @param int[] $selections criterion position => level position.
     * @return bool
     */
    public function is_complete(array $selections): bool {
        foreach (array_keys($this->criteria) as $criterion) {
            if (!isset($selections[$criterion]) || !$this->is_valid_level($criterion, $selections[$criterion])) {
                return false;
            }
        }
        return true;
    }

    /**
     * Calculate the fraction (0 to 1) for a set of selections.
     *
     * Uses the same formulae as the core rubric grading method (gradingform_rubric).
     *
     * @param int[] $selections criterion position => level position.
     * @return float|null the fraction, or null if the selections are incomplete or the rubric
     *      cannot produce a grade (which question editing validation prevents).
     */
    public function calculate_fraction(array $selections): ?float {
        if (!$this->count_criteria() || !$this->is_complete($selections)) {
            return null;
        }

        $score = $this->calculate_score($selections);
        $minscore = $this->get_min_score();
        $maxscore = $this->get_max_score();

        if ($this->lockzeropoints) {
            if ($maxscore <= 0) {
                return null;
            }
            $fraction = $score / $maxscore;
        } else {
            if ($maxscore <= $minscore) {
                return null;
            }
            $fraction = ($score - $minscore) / ($maxscore - $minscore);
        }

        return min(1.0, max(0.0, $fraction));
    }

    /**
     * Whether another rubric has the same number of criteria and levels per criterion.
     *
     * Stored selections are positions, so they can be replayed against another rubric
     * of the same shape (for example when regrading to a new question version).
     *
     * @param rubric $other
     * @return bool
     */
    public function same_shape_as(rubric $other): bool {
        $othercriteria = $other->get_criteria();
        if (count($othercriteria) !== count($this->criteria)) {
            return false;
        }
        foreach ($this->criteria as $position => $criterion) {
            if (count($criterion['levels']) !== count($othercriteria[$position]['levels'])) {
                return false;
            }
        }
        return true;
    }
}
