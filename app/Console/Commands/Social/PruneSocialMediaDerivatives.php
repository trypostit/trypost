<?php

declare(strict_types=1);

namespace App\Console\Commands\Social;

use App\Support\Social\SocialMediaDerivativeDirectory;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('social:prune-derivatives {--hours=1 : Delete derivatives older than this many hours}')]
#[Description('Delete hosted social-media image derivatives (aspect-ratio crops, TikTok resized photos) once no platform still needs to pull them')]
class PruneSocialMediaDerivatives extends Command
{
    private const int DEFAULT_MAX_AGE_HOURS = 1;

    public function handle(): int
    {
        $hours = max(0, (int) ($this->option('hours') ?? self::DEFAULT_MAX_AGE_HOURS));
        $threshold = now()->subHours($hours)->getTimestamp();
        $pruned = 0;

        foreach (SocialMediaDerivativeDirectory::ALL as $directory) {
            foreach (Storage::files($directory) as $path) {
                $modifiedAt = Storage::lastModified($path);

                if ($modifiedAt !== false && $modifiedAt < $threshold && Storage::delete($path)) {
                    $pruned++;
                }
            }
        }

        $this->info("Pruned {$pruned} social media derivative(s).");

        return self::SUCCESS;
    }
}
