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
 * Shows the rubric score and mark while a grader chooses levels.
 *
 * This is only a preview. The mark is calculated again on the server when the grade is saved.
 * A page may contain several rubrics, for example the quiz manual grading report.
 *
 * @module     qbehaviour_rubricgraded/grading
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {getString} from 'core/str';

const SELECTORS = {
    RUBRIC: '[data-region="qbehaviour_rubricgraded-rubric"][data-mode="grade"]',
    CRITERION: '[data-criterion]',
    LEVEL: '[data-level]',
    LEVELINPUT: 'input[type="radio"]',
    SCORE: '[data-region="score"]',
    MARK: '[data-region="mark"]',
};

let initialised = false;

/**
 * Format a number the way format_float($value, 2, true, true) does in PHP.
 *
 * @param {Number} value
 * @param {String} decsep the decimal separator.
 * @returns {String}
 */
const formatScore = (value, decsep) => String(Math.round(value * 100) / 100).replace('.', decsep);

/**
 * Calculate the fraction, using the same formulae as \qbehaviour_rubricgraded\rubric::calculate_fraction().
 *
 * @param {HTMLElement} rubric the rubric element.
 * @param {Number} score the total score.
 * @returns {Number|null} the fraction, or null if the rubric cannot produce one.
 */
const calculateFraction = (rubric, score) => {
    const minScore = parseFloat(rubric.dataset.minscore);
    const maxScore = parseFloat(rubric.dataset.maxscore);
    let fraction;
    if (rubric.dataset.lockzeropoints === '1') {
        if (maxScore <= 0) {
            return null;
        }
        fraction = score / maxScore;
    } else {
        if (maxScore <= minScore) {
            return null;
        }
        fraction = (score - minScore) / (maxScore - minScore);
    }
    return Math.min(1, Math.max(0, fraction));
};

/**
 * Update the highlighted levels, score and mark of a rubric.
 *
 * @param {HTMLElement} rubric the rubric element.
 */
const update = async(rubric) => {
    let score = 0;
    let complete = true;
    rubric.querySelectorAll(SELECTORS.CRITERION).forEach((criterion) => {
        const checked = criterion.querySelector(`${SELECTORS.LEVELINPUT}:checked`);
        criterion.querySelectorAll(SELECTORS.LEVEL).forEach((level) => {
            level.classList.toggle('selected', !!checked && level.contains(checked));
        });
        if (checked) {
            score += parseFloat(checked.dataset.score);
        } else {
            complete = false;
        }
    });

    const decsep = rubric.dataset.decsep;
    const scoreRegion = rubric.querySelector(SELECTORS.SCORE);
    if (scoreRegion) {
        scoreRegion.textContent = await getString('rubricscore', 'qbehaviour_rubricgraded', {
            score: formatScore(score, decsep),
            max: formatScore(parseFloat(rubric.dataset.maxscore), decsep),
        });
    }

    const markRegion = rubric.querySelector(SELECTORS.MARK);
    if (markRegion) {
        const fraction = complete ? calculateFraction(rubric, score) : null;
        if (fraction === null) {
            markRegion.textContent = await getString('rubricincomplete', 'qbehaviour_rubricgraded');
        } else {
            const mark = fraction * parseFloat(rubric.dataset.maxmark);
            markRegion.textContent = await getString('calculatedmark', 'qbehaviour_rubricgraded', {
                mark: mark.toFixed(parseInt(rubric.dataset.markdp)).replace('.', decsep),
                max: rubric.dataset.maxmarkformatted,
            });
        }
    }
};

/**
 * Listen for level changes in any rubric grading form on the page.
 */
export const init = () => {
    if (initialised) {
        return;
    }
    initialised = true;

    document.addEventListener('change', (e) => {
        if (!e.target.matches(SELECTORS.LEVELINPUT)) {
            return;
        }
        const rubric = e.target.closest(SELECTORS.RUBRIC);
        if (rubric) {
            update(rubric);
        }
    });
};
