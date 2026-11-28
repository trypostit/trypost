<?php

declare(strict_types=1);

use App\Actions\Post\ListFailedPosts;
use App\Actions\User\Profile\BuildUserPosts;
use App\Enums\Post\PublishStatus;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Social\ErrorCategory;
use App\Models\Post;
use App\Models\SocialAccount;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia;

test('a post the network rejected in review is listed and counted with the failed ones, and cannot be retried', function () {
    $owner = workspaceOwner();
    $channel = SocialAccount::factory()->create(['workspace_id' => $owner->current_workspace_id, 'platform' => Platform::TikTok]);
    $failed = Post::factory()->forAccount($channel)->failed()->create(['user_id' => $owner->id, 'error_context' => ['category' => ErrorCategory::Unknown->value]]);
    $rejected = Post::factory()->forAccount($channel)->failed()->create(['user_id' => $owner->id, 'error_context' => ['category' => ErrorCategory::Unknown->value]]);
    Post::query()->whereKey($rejected->id)->toBase()->update(['publish_status' => PublishStatus::Rejected->value]);

    expect(ListFailedPosts::execute()->pluck('id')->all())->toEqualCanonicalizing([$failed->id, $rejected->id])
        ->and(ListFailedPosts::categoryCounts())->toBe([ErrorCategory::Unknown->value => 2]);

    $this->actingAs(admin())
        ->get(route('admin.posts.failed.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('posts.data', 2)
            ->where('posts.data', fn (Collection $rows): bool => $rows->firstWhere('id', $rejected->id)['retryable'] === false),
        );
});

test('failures are listed by when they failed, so editing an older one does not move it to the top', function () {
    $owner = workspaceOwner();
    $channel = SocialAccount::factory()->create(['workspace_id' => $owner->current_workspace_id, 'platform' => Platform::X]);
    $older = channelPostAt($channel, now()->subHours(2), 'failed', ['user_id' => $owner->id]);
    $newer = channelPostAt($channel, now()->subHour(), 'failed', ['user_id' => $owner->id]);
    Post::query()->whereKey($older->id)->toBase()->update(['updated_at' => now()->addMinute()]);

    $recent = BuildUserPosts::execute($owner)['recent_failures'];

    expect(ListFailedPosts::execute()->pluck('id')->all())->toBe([$newer->id, $older->id])
        ->and(collect($recent)->pluck('id')->all())->toBe([$newer->id, $older->id])
        ->and($recent[1]['failed_at'])->toBe($older->publication_updated_at->toIso8601String());
});
