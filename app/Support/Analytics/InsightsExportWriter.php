<?php

declare(strict_types=1);

namespace App\Support\Analytics;

use App\Enums\Analytics\ExportFormat;

/**
 * Writes the sections built by BuildInsightsExport as CSV or Markdown.
 */
class InsightsExportWriter
{
    /**
     * @param  array{title: string, meta: list<array{0: string, 1: string}>, sections: list<array{title: string, headers: list<string>, rows: iterable<list<int|float|string|null>>}>}  $export
     * @param  resource  $stream
     */
    public static function write(ExportFormat $format, array $export, $stream): void
    {
        match ($format) {
            ExportFormat::Csv => self::csv($export, $stream),
            ExportFormat::Markdown => self::markdown($export, $stream),
        };
    }

    /**
     * @param  array{title: string, meta: list<array{0: string, 1: string}>, sections: list<array{title: string, headers: list<string>, rows: iterable<list<int|float|string|null>>}>}  $export
     * @param  resource  $stream
     */
    private static function csv(array $export, $stream): void
    {
        fwrite($stream, "\u{FEFF}");
        self::csvLine($stream, [data_get($export, 'title')]);

        foreach (data_get($export, 'meta') as $meta) {
            self::csvLine($stream, $meta);
        }

        foreach (data_get($export, 'sections') as $section) {
            self::csvLine($stream, []);
            self::csvLine($stream, [data_get($section, 'title')]);
            self::csvLine($stream, data_get($section, 'headers'));

            foreach (data_get($section, 'rows') as $row) {
                self::csvLine($stream, $row);
            }
        }
    }

    /**
     * @param  resource  $stream
     * @param  list<int|float|string|null>  $cells
     */
    private static function csvLine($stream, array $cells): void
    {
        fputcsv($stream, array_map(self::csvCell(...), $cells), escape: '');
    }

    /**
     * Neutralizes cells a spreadsheet would run as a formula.
     */
    private static function csvCell(int|float|string|null $value): string
    {
        $cell = (string) $value;

        return is_string($value) && preg_match('/^[=+\-@\t\r]/', $cell) === 1 ? "'{$cell}" : $cell;
    }

    /**
     * @param  array{title: string, meta: list<array{0: string, 1: string}>, sections: list<array{title: string, headers: list<string>, rows: iterable<list<int|float|string|null>>}>}  $export
     * @param  resource  $stream
     */
    private static function markdown(array $export, $stream): void
    {
        fwrite($stream, '# '.self::markdownCell(data_get($export, 'title'))."\n\n");

        foreach (data_get($export, 'meta') as [$label, $value]) {
            fwrite($stream, '- **'.self::markdownCell($label).':** '.self::markdownCell($value)."\n");
        }

        foreach (data_get($export, 'sections') as $section) {
            $headers = data_get($section, 'headers');
            fwrite($stream, "\n## ".self::markdownCell(data_get($section, 'title'))."\n\n");
            fwrite($stream, self::markdownRow($headers));
            fwrite($stream, self::markdownRow(array_fill(0, count($headers), '---')));

            foreach (data_get($section, 'rows') as $row) {
                fwrite($stream, self::markdownRow($row));
            }
        }
    }

    /** @param list<int|float|string|null> $cells */
    private static function markdownRow(array $cells): string
    {
        return '| '.implode(' | ', array_map(fn (int|float|string|null $cell): string => $cell === null ? '—' : self::markdownCell((string) $cell), $cells))." |\n";
    }

    private static function markdownCell(string $value): string
    {
        return str_replace(['\\', '|', "\r\n", "\n", "\r"], ['\\\\', '\\|', ' ', ' ', ' '], $value);
    }
}
