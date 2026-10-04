<?php

declare(strict_types=1);

use App\Domain\Academics\GpaCalculator;

/**
 * GOLDEN: credit-weighted GPA is not the simple average.
 * (3.7 x 3 + 3.0 x 4 + 4.0 x 3) / 10 = 3.51, but (3.7 + 3.0 + 4.0) / 3 = 3.57.
 */
function sampleCourses(): array
{
    return [
        ['points' => 3.7, 'credits' => 3],
        ['points' => 3.0, 'credits' => 4],
        ['points' => 4.0, 'credits' => 3],
    ];
}

it('GOLDEN: credit_weighted = 3.51', function () {
    expect((new GpaCalculator())->calculate(sampleCourses(), 'credit_weighted'))->toBe(3.51);
});

it('GOLDEN: simple_average = 3.57 (different from credit_weighted)', function () {
    $calc = new GpaCalculator();
    expect($calc->calculate(sampleCourses(), 'simple_average'))->toBe(3.57);
    expect($calc->calculate(sampleCourses(), 'simple_average'))
        ->not->toBe($calc->calculate(sampleCourses(), 'credit_weighted'));
});

it('best_n picks the highest N courses', function () {
    expect((new GpaCalculator())->calculate(sampleCourses(), 'best_n', 2, 2))->toBe(3.85);
});

it('honours the decimals setting', function () {
    expect((new GpaCalculator())->calculate(sampleCourses(), 'simple_average', 1))->toBe(3.6);   // 3.57 -> 3.6
    expect((new GpaCalculator())->calculate(sampleCourses(), 'credit_weighted', 1))->toBe(3.5);  // 3.51 -> 3.5
});

it('defaults missing credits to 1', function () {
    $courses = [['points' => 4.0], ['points' => 3.0]];
    expect((new GpaCalculator())->calculate($courses, 'credit_weighted'))->toBe(3.5);
});

it('rejects an unknown method and an empty course list', function () {
    expect(fn () => (new GpaCalculator())->calculate(sampleCourses(), 'magic'))->toThrow(InvalidArgumentException::class);
    expect(fn () => (new GpaCalculator())->calculate([], 'simple_average'))->toThrow(InvalidArgumentException::class);
});

it('rounds half up using exact integer maths', function () {
    $courses = [['points' => 3.0, 'credits' => 1], ['points' => 3.5, 'credits' => 1], ['points' => 3.5, 'credits' => 1], ['points' => 3.5, 'credits' => 1]];
    // 13.5 / 4 = 3.375 -> 3.38
    expect((new GpaCalculator())->calculate($courses, 'simple_average'))->toBe(3.38);
});
