<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Post\Status;
use App\Models\Post;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('posts:audit-legacy {--strict : Fail if posts cannot safely use the independent composer}')]
#[Description('Report legacy post target counts and blockers without changing data')]
class AuditLegacyPosts extends Command
{
    public function handle(): int
    {
        $active = Post::query()->whereIn('status', [Status::Draft, Status::Scheduled]);
        $multiTarget = (clone $active)->whereHas('postPlatforms', fn ($query) => $query->enabled(), '>', 1)->count();
        $zeroTargetDrafts = Post::query()->draft()
            ->whereDoesntHave('postPlatforms', fn ($query) => $query->enabled())->count();
        $zeroTargetScheduled = Post::query()->scheduled()
            ->whereDoesntHave('postPlatforms', fn ($query) => $query->enabled())->count();
        $unavailableScheduled = Post::query()->scheduled()
            ->whereHas('postPlatforms', fn ($query) => $query->enabled()->where(function ($target): void {
                $target->whereNull('social_account_id')
                    ->orWhereDoesntHave('socialAccount', fn ($account) => $account->where('is_active', true));
            }))->count();
        $settledAggregates = Post::query()
            ->whereIn('status', [Status::Published, Status::PartiallyPublished, Status::Failed])
            ->whereHas('postPlatforms', fn ($query) => $query->enabled(), '>', 1)->count();
        $inFlightAggregates = Post::query()->where('status', Status::Publishing)
            ->whereHas('postPlatforms', fn ($query) => $query->enabled(), '>', 1)->count();

        $this->table(['Category', 'Posts'], [
            ['Editable with multiple enabled targets', $multiTarget],
            ['Draft with no enabled target (recoverable)', $zeroTargetDrafts],
            ['Scheduled with no enabled target (blocker)', $zeroTargetScheduled],
            ['Scheduled with an unavailable target (blocker)', $unavailableScheduled],
            ['Settled aggregate (preserve history)', $settledAggregates],
            ['In-flight aggregate (leave running)', $inFlightAggregates],
        ]);

        if ($this->option('strict') && ($multiTarget > 0 || $zeroTargetScheduled > 0 || $unavailableScheduled > 0)) {
            $this->error('Legacy post audit found unresolved blockers.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
