<?php

declare(strict_types=1);

namespace App\Console\Commands\Repurpose;

use App\Enums\Repurpose\Status;
use App\Jobs\Repurpose\PollRepurposeSource;
use App\Models\Repurpose;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class PollRepurposes extends Command
{
    protected $signature = 'repurpose:poll';

    protected $description = 'Poll due repurpose sources for videos published outside TryPost';

    public function handle(): int
    {
        $dispatched = 0;

        Repurpose::query()
            ->where('status', Status::Active)
            ->where(fn (Builder $query) => $query->whereNull('next_poll_at')->orWhere('next_poll_at', '<=', now()))
            ->with('sourceAccount')
            ->chunkById(100, function ($repurposes) use (&$dispatched): void {
                foreach ($repurposes as $repurpose) {
                    PollRepurposeSource::dispatch($repurpose);
                    $dispatched++;
                }
            });

        $this->info("Dispatched {$dispatched} repurpose poll(s).");

        return self::SUCCESS;
    }
}
