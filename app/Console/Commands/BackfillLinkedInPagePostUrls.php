<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\PostPlatform\Status;
use App\Enums\SocialAccount\Platform;
use App\Models\PostPlatform;
use Illuminate\Console\Command;

/**
 * One-time repair for LinkedIn Page posts published before the fix for
 * https://github.com/trypostit/trypost/issues/271 — LinkedInPagePublisher::postUrl()
 * built "company/{username}/posts/" without appending the post ID, so those rows
 * had that broken, unusable URL persisted to platform_url.
 */
class BackfillLinkedInPagePostUrls extends Command
{
    protected $signature = 'social:backfill-linkedin-page-post-urls {--dry-run : Preview affected rows without writing changes}';

    protected $description = 'Recompute platform_url for LinkedIn Page posts published with the pre-fix company/{username}/posts/ URL (issue #271)';

    public function handle(): void
    {
        $dryRun = (bool) $this->option('dry-run');
        $count = 0;

        PostPlatform::query()
            ->where('platform', Platform::LinkedInPage)
            ->where('status', Status::Published)
            ->whereNotNull('platform_post_id')
            ->where('platform_url', 'like', 'https://www.linkedin.com/company/%/posts/')
            ->each(function (PostPlatform $postPlatform) use ($dryRun, &$count) {
                $correctedUrl = "https://www.linkedin.com/feed/update/{$postPlatform->platform_post_id}";

                $this->line("{$postPlatform->id}: {$postPlatform->platform_url} -> {$correctedUrl}");

                if (! $dryRun) {
                    $postPlatform->update(['platform_url' => $correctedUrl]);
                }

                $count++;
            });

        $verb = $dryRun ? 'Would fix' : 'Fixed';
        $this->info("{$verb} {$count} LinkedIn Page post URL(s).");
    }
}
