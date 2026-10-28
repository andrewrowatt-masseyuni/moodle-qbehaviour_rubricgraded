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
 * Options controlling how a rubric is displayed to graders and to those being graded.
 *
 * These mirror the display options of the core rubric grading method. The defaults
 * match the core rubric defaults, and apply wherever no other settings are configured
 * (for example, question preview).
 *
 * @package    qbehaviour_rubricgraded
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class display_settings {
    /** @var string[] the names of the settings, in display order. */
    public const FIELDS = [
        'alwaysshowdefinition',
        'showscorestudent',
        'enableremarks',
        'showremarksstudent',
        'showscoreteacher',
        'sortlevelsasc',
    ];

    /**
     * Constructor.
     *
     * @param bool $alwaysshowdefinition show the rubric before grading, not just after.
     * @param bool $showscorestudent show the points for each level to those being graded.
     * @param bool $enableremarks allow graders to add a remark for each criterion.
     * @param bool $showremarksstudent show remarks to those being graded.
     * @param bool $showscoreteacher show the points for each level to graders.
     * @param bool $sortlevelsasc list levels in ascending order of score (otherwise descending).
     */
    public function __construct(
        /** @var bool show the rubric before grading, not just after. */
        public readonly bool $alwaysshowdefinition = true,
        /** @var bool show the points for each level to those being graded. */
        public readonly bool $showscorestudent = true,
        /** @var bool allow graders to add a remark for each criterion. */
        public readonly bool $enableremarks = true,
        /** @var bool show remarks to those being graded. */
        public readonly bool $showremarksstudent = true,
        /** @var bool show the points for each level to graders. */
        public readonly bool $showscoreteacher = true,
        /** @var bool list levels in ascending order of score (otherwise descending). */
        public readonly bool $sortlevelsasc = true,
    ) {
    }

    /**
     * Make an instance from a record with some or all of the fields in {@see self::FIELDS}.
     * Missing fields take their default value.
     *
     * @param \stdClass|array $record
     * @return self
     */
    public static function from_record(\stdClass|array $record): self {
        $record = (array) $record;
        $args = [];
        foreach (self::FIELDS as $field) {
            if (isset($record[$field])) {
                $args[$field] = (bool) $record[$field];
            }
        }
        return new self(...$args);
    }

    /**
     * Get the settings as an array of field => 0 or 1, suitable for a database record.
     *
     * @return int[]
     */
    public function to_array(): array {
        $data = [];
        foreach (self::FIELDS as $field) {
            $data[$field] = (int) $this->$field;
        }
        return $data;
    }
}
