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
 * Unit tests for the rubric class.
 *
 * @package    qbehaviour_rubricgraded
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \qbehaviour_rubricgraded\rubric
 */
final class rubric_test extends \basic_testcase {
    /**
     * Make a rubric with two criteria, scoring between 1 and 5 points in total.
     *
     * @param bool $lockzeropoints
     * @return rubric
     */
    protected function make_rubric(bool $lockzeropoints = true): rubric {
        return new rubric([
            ['description' => 'Argument', 'levels' => [
                ['definition' => 'Weak', 'score' => 1],
                ['definition' => 'Adequate', 'score' => 2],
                ['definition' => 'Strong', 'score' => 3],
            ]],
            (object) ['description' => 'Spelling', 'levels' => [
                (object) ['definition' => 'Poor', 'score' => '0'],
                (object) ['definition' => 'Good', 'score' => '2.00000'],
            ]],
        ], $lockzeropoints);
    }

    public function test_criteria_are_indexed_by_position(): void {
        $criteria = $this->make_rubric()->get_criteria();

        $this->assertSame([1, 2], array_keys($criteria));
        $this->assertSame('Spelling', $criteria[2]['description']);
        $this->assertSame([1, 2], array_keys($criteria[2]['levels']));
        $this->assertSame(['definition' => 'Good', 'score' => 2.0], $criteria[2]['levels'][2]);
    }

    public function test_min_max_score(): void {
        $rubric = $this->make_rubric();
        $this->assertEquals(1, $rubric->get_min_score());
        $this->assertEquals(5, $rubric->get_max_score());
    }

    public function test_is_valid_level(): void {
        $rubric = $this->make_rubric();
        $this->assertTrue($rubric->is_valid_level(1, 3));
        $this->assertFalse($rubric->is_valid_level(1, 4));
        $this->assertFalse($rubric->is_valid_level(2, 0));
        $this->assertFalse($rubric->is_valid_level(3, 1));
    }

    /**
     * Data provider for {@see test_calculate_fraction()}.
     *
     * @return array
     */
    public static function calculate_fraction_provider(): array {
        return [
            'best, lock zero' => [true, [1 => 3, 2 => 2], 1.0],
            'worst, lock zero' => [true, [1 => 1, 2 => 1], 0.2],
            'middle, lock zero' => [true, [1 => 2, 2 => 2], 0.8],
            'best, scaled' => [false, [1 => 3, 2 => 2], 1.0],
            'worst, scaled' => [false, [1 => 1, 2 => 1], 0.0],
            'middle, scaled' => [false, [1 => 2, 2 => 2], 0.75],
            'incomplete' => [true, [1 => 3], null],
            'invalid level' => [true, [1 => 3, 2 => 3], null],
            'nothing' => [true, [], null],
        ];
    }

    /**
     * Test the fraction calculation, which follows the core rubric grading method.
     *
     * @dataProvider calculate_fraction_provider
     * @param bool $lockzeropoints
     * @param array $selections
     * @param float|null $expected
     */
    public function test_calculate_fraction(bool $lockzeropoints, array $selections, ?float $expected): void {
        $fraction = $this->make_rubric($lockzeropoints)->calculate_fraction($selections);
        if ($expected === null) {
            $this->assertNull($fraction);
        } else {
            $this->assertEqualsWithDelta($expected, $fraction, 0.0000001);
        }
    }

    public function test_calculate_fraction_for_degenerate_rubrics(): void {
        $allzero = new rubric([['description' => 'A', 'levels' => [
            ['definition' => 'x', 'score' => 0],
            ['definition' => 'y', 'score' => 0],
        ]]], true);
        $this->assertNull($allzero->calculate_fraction([1 => 1]));

        $novariation = new rubric([['description' => 'A', 'levels' => [
            ['definition' => 'x', 'score' => 2],
            ['definition' => 'y', 'score' => 2],
        ]]], false);
        $this->assertNull($novariation->calculate_fraction([1 => 1]));

        $this->assertNull((new rubric([]))->calculate_fraction([]));
    }

    public function test_same_shape_as(): void {
        $rubric = $this->make_rubric();

        $samescores = $this->make_rubric(false);
        $this->assertTrue($rubric->same_shape_as($samescores));

        $differenttext = new rubric([
            ['description' => 'Other', 'levels' => [
                ['definition' => 'a', 'score' => 0], ['definition' => 'b', 'score' => 5], ['definition' => 'c', 'score' => 9],
            ]],
            ['description' => 'Other', 'levels' => [['definition' => 'a', 'score' => 0], ['definition' => 'b', 'score' => 1]]],
        ]);
        $this->assertTrue($rubric->same_shape_as($differenttext));

        $fewerlevels = new rubric([
            ['description' => 'Argument', 'levels' => [['definition' => 'a', 'score' => 0], ['definition' => 'b', 'score' => 1]]],
            ['description' => 'Spelling', 'levels' => [['definition' => 'a', 'score' => 0], ['definition' => 'b', 'score' => 1]]],
        ]);
        $this->assertFalse($rubric->same_shape_as($fewerlevels));

        $fewercriteria = new rubric([
            ['description' => 'Argument', 'levels' => [
                ['definition' => 'a', 'score' => 0], ['definition' => 'b', 'score' => 1], ['definition' => 'c', 'score' => 2],
            ]],
        ]);
        $this->assertFalse($rubric->same_shape_as($fewercriteria));
    }
}
