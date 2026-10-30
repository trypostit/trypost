<?php

declare(strict_types=1);

namespace App\Support\Analytics\Pdf;

/**
 * Draws the Insights metric charts as plain SVG for the PDF, in the style of the
 * charts on the page: dashed grid, rounded bars, 2px lines, muted axis labels.
 * No script runs in the PDF, so every chart is drawn here.
 */
class SvgChart
{
    private const int WIDTH = 520;

    private const int HEIGHT = 180;

    private const int LEFT = 44;

    private const int RIGHT = 8;

    private const int TOP = 10;

    private const int BOTTOM = 24;

    private const int GRID_LINES = 4;

    /**
     * @param  list<int|float|null>  $values  One value per bucket, oldest first.
     * @param  list<string>  $labels  The bucket labels shown under the axis.
     * @param  callable(int|float): string  $tick
     */
    public static function timeSeries(array $values, array $labels, string $kind, string $color, callable $tick): string
    {
        $plotWidth = self::WIDTH - self::LEFT - self::RIGHT;
        $plotHeight = self::HEIGHT - self::TOP - self::BOTTOM;
        $known = array_values(array_filter($values, fn (int|float|null $value): bool => filled($value)));
        [$min, $max] = self::domain($known, $kind);
        $lines = self::GRID_LINES;

        if ($kind === 'line') {
            $lines = (int) min(self::GRID_LINES, max(1, $max - $min));
            $max = $min + ceil(($max - $min) / $lines) * $lines;
        }
        $count = max(count($values), 1);
        $step = $plotWidth / $count;
        $y = fn (int|float $value): float => self::TOP + $plotHeight - (($value - $min) / max($max - $min, 1e-9)) * $plotHeight;
        $x = fn (int $index): float => self::LEFT + $step * ($index + 0.5);

        $parts = [];

        for ($line = 0; $line <= $lines; $line++) {
            $value = $min + ($max - $min) * $line / $lines;
            $lineY = round($y($value), 2);
            $parts[] = '<line x1="'.self::LEFT.'" x2="'.(self::WIDTH - self::RIGHT)."\" y1=\"{$lineY}\" y2=\"{$lineY}\" stroke=\"#eae8e5\" stroke-dasharray=\"3 3\"/>";
            $parts[] = '<text x="'.(self::LEFT - 6).'" y="'.($lineY + 3).'" text-anchor="end" font-size="9" fill="#5a5a59">'.e($tick($value)).'</text>';
        }

        if ($kind === 'bar') {
            $barWidth = max(min($step * 0.6, 16), 1.5);
            $zero = $y(0);

            foreach ($values as $index => $value) {
                if (blank($value) || (float) $value === 0.0) {
                    continue;
                }

                $top = round(min($y($value), $zero), 2);
                $height = round(abs($zero - $y($value)), 2);
                $left = round($x($index) - $barWidth / 2, 2);
                $radius = min(3, $barWidth / 2, $height);
                $parts[] = "<rect x=\"{$left}\" y=\"{$top}\" width=\"".round($barWidth, 2)."\" height=\"{$height}\" rx=\"{$radius}\" fill=\"{$color}\"/>";
            }
        } else {
            $segments = [];
            $current = [];

            foreach ($values as $index => $value) {
                if (blank($value)) {
                    $segments[] = $current;
                    $current = [];

                    continue;
                }

                $current[] = round($x($index), 2).','.round($y($value), 2);
            }

            $segments[] = $current;

            foreach ($segments as $segment) {
                if (count($segment) === 1) {
                    [$pointX, $pointY] = explode(',', $segment[0]);
                    $parts[] = "<circle cx=\"{$pointX}\" cy=\"{$pointY}\" r=\"2.5\" fill=\"{$color}\"/>";
                } elseif (count($segment) > 1) {
                    $parts[] = '<polyline points="'.implode(' ', $segment)."\" fill=\"none\" stroke=\"{$color}\" stroke-width=\"2\" stroke-linejoin=\"round\" stroke-linecap=\"round\"/>";
                }
            }
        }

        foreach (self::labelIndexes(count($labels)) as $index) {
            $anchor = $index === 0 ? 'start' : ($index === count($labels) - 1 ? 'end' : 'middle');
            $labelX = $anchor === 'start' ? self::LEFT : ($anchor === 'end' ? self::WIDTH - self::RIGHT : round($x($index), 2));
            $parts[] = "<text x=\"{$labelX}\" y=\"".(self::HEIGHT - 6)."\" text-anchor=\"{$anchor}\" font-size=\"9\" fill=\"#5a5a59\">".e($labels[$index]).'</text>';
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.self::WIDTH.' '.self::HEIGHT.'" width="100%" role="img">'
            .implode('', $parts)
            .'</svg>';
    }

    /**
     * Bars grow from zero, below it for a loss; a line of large, steady values
     * (followers) gets a range around its own values so its movement stays visible.
     *
     * @param  list<int|float>  $values
     * @return array{0: float, 1: float}
     */
    private static function domain(array $values, string $kind): array
    {
        if ($values === []) {
            return [0.0, 1.0];
        }

        $low = (float) min($values);
        $high = (float) max($values);

        if ($kind === 'bar' && $low < 0) {
            return [-self::nice(-$low), $high > 0 ? self::nice($high) : 0.0];
        }

        if ($kind === 'bar' || $low >= 0 && $low <= ($high - $low)) {
            return [0.0, self::nice(max($high, 1.0))];
        }

        $padding = max(($high - $low) * 0.1, 1.0);

        return [floor($low - $padding), ceil($high + $padding)];
    }

    private static function nice(float $value): float
    {
        $magnitude = 10 ** floor(log10($value));

        foreach ([1, 2, 2.5, 5, 10] as $factor) {
            if ($factor * $magnitude >= $value) {
                return $factor * $magnitude;
            }
        }

        return 10 * $magnitude;
    }

    /**
     * The first, middle and last buckets, so the axis never crowds.
     *
     * @return list<int>
     */
    private static function labelIndexes(int $count): array
    {
        if ($count === 0) {
            return [];
        }

        return array_values(array_unique([0, intdiv($count - 1, 2), $count - 1]));
    }
}
