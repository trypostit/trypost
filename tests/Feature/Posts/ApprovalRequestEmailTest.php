<?php

declare(strict_types=1);

use App\Actions\Post\UpdatePost;
use App\Enums\Notification\Type;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Jobs\SendNotification;
use App\Mail\PostApprovalRequested;
use App\Models\NotificationPreference;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\PostingSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 08:00', 'UTC'));

    $this->owner = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    $this->workspace->members()->attach($this->owner->id, membershipPivot('admin'));
    $this->owner->update(['current_workspace_id' => $this->workspace->id]);
    $this->publisher = workspaceMember($this->workspace, 'member');
    $this->requester = workspaceMember($this->workspace, 'approval');
    $this->otherRequester = workspaceMember($this->workspace, 'approval');

    $schedule = PostingSchedule::empty()->withTime(1, '09:00')->withTime(3, '09:00');
    $this->linkedin = SocialAccount::factory()->linkedin()->create(['workspace_id' => $this->workspace->id, 'timezone' => 'UTC', 'posting_schedule' => $schedule]);
    $this->x = SocialAccount::factory()->x()->create(['workspace_id' => $this->workspace->id, 'timezone' => 'UTC', 'posting_schedule' => $schedule]);
});

/**
 * @param  list<SocialAccount>  $channels
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function approvalEmailPayload(array $channels, array $overrides = []): array
{
    return [
        'status' => 'scheduled',
        'queue' => 'next',
        'content' => 'Launch post',
        'media' => [],
        'destinations' => array_map(fn (SocialAccount $channel): array => [
            'social_account_id' => $channel->id,
            'content_type' => $channel->platform === Platform::X ? ContentType::XPost->value : ContentType::LinkedInPost->value,
            'meta' => [],
        ], $channels),
        ...$overrides,
    ];
}

/**
 * @return list<string>
 */
function approvalEmailRecipients(): array
{
    return Queue::pushed(SendNotification::class)
        ->filter(fn (SendNotification $job): bool => $job->mailable instanceof PostApprovalRequested)
        ->map(fn (SendNotification $job): string => $job->user->id)
        ->sort()
        ->values()
        ->all();
}

test('a multi-channel request sends one email per approver listing every channel', function () {
    $this->actingAs($this->requester)
        ->post(route('app.posts.store'), approvalEmailPayload([$this->linkedin, $this->x]))
        ->assertSessionHasNoErrors();

    $expected = collect([$this->owner->id, $this->publisher->id])->sort()->values()->all();

    expect(approvalEmailRecipients())->toBe($expected);

    Queue::assertPushed(SendNotification::class, fn (SendNotification $job): bool => $job->type === Type::Collaboration
        && $job->mailable instanceof PostApprovalRequested
        && count($job->mailable->postIds) === 2
        && $job->mailable->requester->is($this->requester));
});

test('a draft or a direct publisher sends no approval email', function () {
    $this->actingAs($this->requester)
        ->post(route('app.posts.store'), approvalEmailPayload([$this->linkedin], ['status' => 'draft', 'queue' => null]))
        ->assertSessionHasNoErrors();

    $this->actingAs($this->publisher)
        ->post(route('app.posts.store'), approvalEmailPayload([$this->linkedin]))
        ->assertSessionHasNoErrors();

    expect(approvalEmailRecipients())->toBe([]);
});

test('editing a scheduled post into pending emails approvers once', function () {
    $this->actingAs($this->publisher)
        ->post(route('app.posts.store'), approvalEmailPayload([$this->linkedin]))
        ->assertSessionHasNoErrors();
    $post = Post::query()->findOrFail(session('created_post_ids')[0]);

    UpdatePost::execute($this->workspace, $post, ['status' => 'scheduled', 'queue' => 'next', 'content' => 'Edit one'], $this->requester);
    UpdatePost::execute($this->workspace, $post->refresh(), ['status' => 'scheduled', 'queue' => 'next', 'content' => 'Edit two'], $this->requester);

    $expected = collect([$this->owner->id, $this->publisher->id])->sort()->values()->all();

    expect(approvalEmailRecipients())->toBe($expected);
});

test('the collaboration preference silences the email', function () {
    Mail::fake();
    NotificationPreference::factory()->create(['user_id' => $this->owner->id, 'collaboration' => false]);
    $post = Post::factory()->pendingApproval()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->requester->id]);

    (new SendNotification($this->owner->fresh(), Type::Collaboration, new PostApprovalRequested([$post->id], $this->requester, $this->owner)))->handle();
    Mail::assertNothingQueued();

    (new SendNotification($this->publisher->fresh(), Type::Collaboration, new PostApprovalRequested([$post->id], $this->requester, $this->publisher)))->handle();
    Mail::assertQueued(PostApprovalRequested::class);
});

test('the request email is not sent once none of its posts is still pending', function (string $outcome) {
    Mail::fake();
    $post = Post::factory()->pendingApproval()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->requester->id]);

    match ($outcome) {
        'approved' => $post->update(['status' => PostStatus::Scheduled]),
        'rejected' => $post->update(['status' => PostStatus::Draft]),
        'deleted' => $post->delete(),
    };

    (new SendNotification($this->owner->fresh(), Type::Collaboration, new PostApprovalRequested([$post->id], $this->requester, $this->owner)))->handle();

    Mail::assertNothingQueued();
})->with(['approved', 'rejected', 'deleted']);

test('the request email lists only the posts still pending', function () {
    Mail::fake();
    $pending = Post::factory()->pendingApproval()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->requester->id]);
    $approved = Post::factory()->scheduled()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->requester->id]);

    (new SendNotification($this->owner->fresh(), Type::Collaboration, new PostApprovalRequested([$pending->id, $approved->id], $this->requester, $this->owner)))->handle();

    Mail::assertQueued(PostApprovalRequested::class, fn (PostApprovalRequested $mail): bool => $mail->postIds === [$pending->id]);
});
