<?php

declare(strict_types=1);

namespace App\Jobs\Media;

use App\Actions\Media\DeleteOrphanedMediaFiles;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeleteMediaFiles implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /**
     * @param  list<string>  $paths
     */
    public function __construct(public array $paths) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    public function handle(): void
    {
        DeleteOrphanedMediaFiles::execute($this->paths);
    }
}
