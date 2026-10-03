<?php

declare(strict_types=1);

namespace App\Enums\Analytics;

enum ExportFormat: string
{
    case Csv = 'csv';
    case Markdown = 'md';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function contentType(): string
    {
        return match ($this) {
            self::Csv => 'text/csv; charset=UTF-8',
            self::Markdown => 'text/markdown; charset=UTF-8',
        };
    }
}
