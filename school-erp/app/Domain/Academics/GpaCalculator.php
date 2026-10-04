<?php

declare(strict_types=1);

namespace App\Domain\Academics;

use InvalidArgumentException;

/**
 * GPA from per-course grade points, integer arithmetic (hundredths) to avoid float drift.
 * methods: simple_average | credit_weighted | best_n.  Rounding: half-up to $decimals places.
 */
final class GpaCalculator
{
    /** @param list<array{points: int|float|string, credits?: int|float|string}> $courses */
    public function calculate(array $courses, string $method = 'simple_average', int $decimals = 2, ?int $bestN = null): float
    {
        if ($courses === []) {
            throw new InvalidArgumentException('Cannot compute GPA for zero courses.');
        }
        if ($decimals < 0 || $decimals > 4) {
            throw new InvalidArgumentException('decimals must be between 0 and 4.');
        }

        $pts = array_map(fn (array $c): int => $this->hundredths($c['points']), $courses);

        switch ($method) {
            case 'simple_average':
                $num = array_sum($pts);
                $den = count($pts) * 100;
                break;

            case 'best_n':
                if ($bestN === null || $bestN < 1) {
                    throw new InvalidArgumentException('best_n requires bestN >= 1.');
                }
                rsort($pts);
                $take = array_slice($pts, 0, $bestN);
                $num = array_sum($take);
                $den = count($take) * 100;
                break;

            case 'credit_weighted':
                $num = 0;
                $den = 0;
                foreach ($courses as $i => $c) {
                    $credit = $this->hundredths($c['credits'] ?? 1);
                    $num += $pts[$i] * $credit;
                    $den += $credit;
                }
                if ($den <= 0) {
                    throw new InvalidArgumentException('Total credits must be > 0.');
                }
                // num = (points*100) x (credits*100); den = credits*100 -> bring den to the same scale
                $den *= 100;
                break;

            default:
                throw new InvalidArgumentException("Unknown GPA method '{$method}'.");
        }

        // invariant: points = $num / $den (both integers)
        $scale = 10 ** $decimals;

        return $this->roundHalfUp($num * $scale, $den) / $scale;
    }

    private function hundredths(int|float|string $v): int
    {
        return (int) round(((float) $v) * 100);
    }

    private function roundHalfUp(int $numerator, int $denominator): int
    {
        return intdiv(2 * $numerator + $denominator, 2 * $denominator);
    }
}
