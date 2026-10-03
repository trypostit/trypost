<?php

declare(strict_types=1);

use App\Actions\Post\UpdatePost;
use App\Enums\Post\QueuePosition;
use App\Enums\Post\ScheduleMode;
use App\Enums\PostPlatform\ContentType;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00:00', 'UTC'));

    $this->owner = User::factory()->create(['timezone' => 'UTC']);
    $this->workspace = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    $this->workspace->members()->attach($this->owner->id, membershipPivot('admin'));
    $this->owner->update(['current_workspace_id' => $this->workspace->id]);
    $this->requester = workspaceMember($this->workspace, 'approval', ['timezone' => 'UTC']);
    $this->otherRequester = workspaceMember($this->workspace, 'approval', ['timezone' => 'UTC']);
    $this->channel = SocialAccount::factory()->linkedin()->create(['workspace_id' => $this->workspace->id, 'timezone' => 'UTC']);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function approvalsTabPost(SocialAccount $channel, User $author, array $attributes = []): Post
{
    $post = Post::factory()->pendingApproval()->create(array_merge([
        'workspace_id' => $channel->workspace_id,
        'user_id' => $author->id,
    ], $attributes));

    PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $channel->id,
        'platform' => $channel->platform,
    ]);

    return $post;
}

test('approvers count and list every pending post, timed first then unscheduled', function () {
    $later = approvalsTabPost($this->channel, $this->requester, ['scheduled_at' => now()->addDays(2)]);
    $sooner = approvalsTabPost($this->channel, $this->otherRequester, ['scheduled_at' => now()->addDay()]);
    $queued = approvalsTabPost($this->channel, $this->requester, [
        'scheduled_at' => null,
        'schedule_mode' => ScheduleMode::Queue,
        'approval_queue_position' => QueuePosition::Next,
    ]);

    $this->actingAs($this->owner)
        ->get(route('app.posts.index', ['tab' => 'approvals']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tab', 'approvals')
            ->where('counts.approvals', 3)
            ->where('counts.queue', 0)
            ->where('counts.drafts', 0)
            ->has('posts.data', 3)
            ->where('posts.data.0.id', $sooner->id)
            ->where('posts.data.1.id', $later->id)
            ->where('posts.data.2.id', $queued->id)
            ->where('posts.data.2.approval_queue_position', 'next')
            ->where('posts.data.2.user.name', $this->requester->name));
});

test('requesters only count and see their own pending posts', function () {
    $own = approvalsTabPost($this->channel, $this->requester);
    approvalsTabPost($this->channel, $this->otherRequester);

    $this->actingAs($this->requester)
        ->get(route('app.posts.index', ['tab' => 'approvals']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('counts.approvals', 1)
            ->has('posts.data', 1)
            ->where('posts.data.0.id', $own->id));
});

test('the approvals count follows the channel scope', function () {
    $other = SocialAccount::factory()->x()->create(['workspace_id' => $this->workspace->id]);
    approvalsTabPost($this->channel, $this->requester);
    approvalsTabPost($other, $this->requester);

    $this->actingAs($this->owner)
        ->get(route('app.channels.publish', $this->channel))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('counts.approvals', 1));
});

test('pending posts never show in the queue or drafts tabs', function () {
    approvalsTabPost($this->channel, $this->requester);

    $this->actingAs($this->owner)
        ->get(route('app.posts.index', ['tab' => 'drafts']))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('posts.data', 0));

    $this->get(route('app.posts.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('tab', 'queue')->has('posts.data', 0));
});

test('a notes link to a pending post opens the approvals tab', function () {
    $post = approvalsTabPost($this->channel, $this->requester);

    $this->actingAs($this->owner)
        ->get(route('app.posts.index', ['notes' => $post->id]))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('tab', 'approvals'));
});

test('a requester sees in approvals the scheduled post they sent back for approval', function () {
    $scheduled = Post::factory()->scheduled()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->owner->id]);
    PostPlatform::factory()->create([
        'post_id' => $scheduled->id,
        'social_account_id' => $this->channel->id,
        'platform' => $this->channel->platform,
        'content_type' => ContentType::LinkedInPost,
    ]);

    UpdatePost::execute($this->workspace, $scheduled, ['status' => 'scheduled', 'content' => 'Typo fixed'], $this->requester);

    $this->actingAs($this->requester)
        ->get(route('app.posts.index', ['tab' => 'approvals']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('counts.approvals', 1)
            ->has('posts.data', 1)
            ->where('posts.data.0.id', $scheduled->id));

    $this->actingAs($this->otherRequester)
        ->get(route('app.posts.index', ['tab' => 'approvals']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('counts.approvals', 0)
            ->has('posts.data', 0));
});

test('a requester does not see another member\'s request when it was edited by someone else', function () {
    approvalsTabPost($this->channel, $this->requester, ['approval_requested_by' => $this->otherRequester->id]);

    $this->actingAs($this->requester)
        ->get(route('app.posts.index', ['tab' => 'approvals']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('counts.approvals', 0)
            ->has('posts.data', 0));
});

test('a requester deep link to someone else\'s pending post reveals nothing', function () {
    $foreign = approvalsTabPost($this->channel, $this->otherRequester);

    $this->actingAs($this->requester)
        ->get(route('app.posts.index', ['notes' => $foreign->id]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tab', 'approvals')
            ->where('counts.approvals', 0)
            ->has('posts.data', 0));
});
