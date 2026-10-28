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
 * Interface that a question definition must implement to be graded with the rubricgraded behaviour.
 *
 * @package    qbehaviour_rubricgraded
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface question_with_rubric {
    /**
     * Get the rubric used to grade this question.
     *
     * @return rubric
     */
    public function get_rubric(): rubric;

    /**
     * Get the options controlling how the rubric is displayed in a given context.
     *
     * @param \context|null $context the context the question is being displayed in,
     *      normally {@see \question_display_options::$context}.
     * @return display_settings
     */
    public function get_rubric_display_settings(?\context $context): display_settings;
}
