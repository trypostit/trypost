<?php

declare(strict_types=1);

namespace App\Console\Commands\Scripts;

use App\Actions\Media\AuditMedia;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('media:audit {--json : Print the report as JSON}')]
#[Description('Report media rows and storage files that are out of step (read-only)')]
class AuditMediaCommand extends Command
{
    public function handle(): int
    {
        $report = AuditMedia::execute();
        $clean = collect($report)->every(fn (array $findings): bool => $findings === []);

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $clean ? self::SUCCESS : self::FAILURE;
        }

        $this->table(
            ['Check', 'Findings'],
            collect($report)->map(fn (array $findings, string $check): array => [$check, count($findings)])->values()->all(),
        );

        foreach ($report as $check => $findings) {
            foreach ($findings as $finding) {
                $this->line("{$check}: ".collect($finding)->map(fn (string $value, string $key): string => "{$key}={$value}")->implode(' '));
            }
        }

        return $clean ? self::SUCCESS : self::FAILURE;
    }
}
