<?php

declare(strict_types=1);

use App\Actions\Post\AppendPostMedia;
use App\Actions\Post\Approval\ApprovePost;
use App\Actions\Post\CreatePosts;
use App\Dto\MediaItem;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Exceptions\Post\QueueBusyException;
use App\Jobs\SendNotification;
use App\Mail\PostApprovalRequested;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Post\AttachMediaFromUploadTool;
use App\Mcp\Tools\Post\AttachMediaFromUrlTool;
use App\Models\Media;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Queue::fake();
    Storage::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 08:00', 'UTC'));

    $this->owner = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    $this->workspace->members()->attach($this->owner->id, membershipPivot('admin'));
    $this->owner->update(['current_workspace_id' => $this->workspace->id]);
    $this->requester = workspaceMember($this->workspace, 'approval');
    $this->publisher = workspaceMember($this->workspace, 'member');
    $this->channel = SocialAccount::factory()->linkedin()->create(['workspace_id' => $this->workspace->id, 'timezone' => 'UTC']);

    $this->scheduled = CreatePosts::execute($this->workspace, $this->owner, [
        'status' => 'scheduled',
        'scheduled_at' => now()->addDays(2)->toIso8601String(),
        'content' => 'Approved and scheduled',
        'media' => [],
        'destinations' => [[
            'social_account_id' => $this->channel->id,
            'content_type' => ContentType::LinkedInPost->value,
            'meta' => [],
        ]],
    ])->sole();

    Http::fake([
        'example.com/photo.png' => Http::response(
            file_get_contents(__DIR__.'/../../fixtures/1x1.png'),
            200,
            ['Content-Type' => 'image/png'],
        ),
    ]);
});

/**
 * Attaches one image to the post through the given MCP attach tool.
 *
 * @param  class-string  $tool
 */
function attachMediaApprovalCall(object $test, User $user, string $tool): void
{
    $arguments = ['post_id' => $test->scheduled->id];

    if ($tool === AttachMediaFromUploadTool::class) {
        $token = (string) Str::uuid();
        Media::factory()->stored()->create([
            'mediable_type' => (new Workspace)->getMorphClass(),
            'mediable_id' => $test->workspace->id,
            'collection' => 'uploads',
            'upload_token' => $token,
        ]);
        $arguments['upload_token'] = $token;
    } else {
        $arguments['urls'] = [['url' => 'https://example.com/photo.png']];
    }

    TryPostServer::actingAs($user)->tool($tool, $arguments)->assertOk();
}

test('a requester attaching media to an approved post sends it back for approval', function (string $tool) {
    attachMediaApprovalCall($this, $this->requester, $tool);

    $post = $this->scheduled->fresh();

    expect($post->media)->toHaveCount(1)
        ->and($post->status)->toBe(PostStatus::PendingApproval)
        ->and($post->approval_requested_by)->toBe($this->requester->id);
    Queue::assertPushed(SendNotification::class, fn (SendNotification $job): bool => $job->mailable instanceof PostApprovalRequested
        && $job->user->is($this->owner));
})->with([
    'from url' => [AttachMediaFromUrlTool::class],
    'from upload' => [AttachMediaFromUploadTool::class],
]);

test('a direct publisher attaching media keeps the post scheduled', function (string $tool) {
    attachMediaApprovalCall($this, $this->publisher, $tool);

    $post = $this->scheduled->fresh();

    expect($post->media)->toHaveCount(1)
        ->and($post->status)->toBe(PostStatus::Scheduled)
        ->and($post->approval_requested_by)->toBeNull();
})->with([
    'from url' => [AttachMediaFromUrlTool::class],
    'from upload' => [AttachMediaFromUploadTool::class],
]);

test('a requester attaching media to a draft keeps it a draft', function () {
    $this->scheduled->update(['status' => PostStatus::Draft, 'schedule_mode' => null]);

    attachMediaApprovalCall($this, $this->requester, AttachMediaFromUploadTool::class);

    expect($this->scheduled->fresh()->status)->toBe(PostStatus::Draft);
});

/**
 * @return list<array<string, mixed>>
 */
function attachMediaApprovalItems(object $test): array
{
    $media = Media::factory()->stored()->create([
        'mediable_type' => (new Workspace)->getMorphClass(),
        'mediable_id' => $test->workspace->id,
        'collection' => 'uploads',
        'upload_token' => (string) Str::uuid(),
    ]);

    return [MediaItem::fromMedia($media)->toArray()];
}

test('media attached to a request that was just approved sends it back for approval', function () {
    $request = CreatePosts::execute($this->workspace, $this->requester, [
        'status' => 'scheduled',
        'scheduled_at' => now()->addDays(2)->toIso8601String(),
        'content' => 'Requested',
        'media' => [],
        'destinations' => [[
            'social_account_id' => $this->channel->id,
            'content_type' => ContentType::LinkedInPost->value,
            'meta' => [],
        ]],
    ])->sole();
    $stale = Post::query()->findOrFail($request->id);

    ApprovePost::execute($request, $this->owner);

    AppendPostMedia::execute($stale, attachMediaApprovalItems($this), $this->requester);

    $request->refresh();

    expect($request->media)->toHaveCount(1)
        ->and($request->status)->toBe(PostStatus::PendingApproval)
        ->and($request->approval_requested_by)->toBe($this->requester->id);
});

test('attaching media takes the approval lock and changes nothing while an approval holds it', function () {
    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('block')->andThrow(new LockTimeoutException);
    Cache::partialMock()->shouldReceive('lock')->with("post-approval:{$this->scheduled->id}", 30)->andReturn($lock);

    expect(fn () => AppendPostMedia::execute($this->scheduled, attachMediaApprovalItems($this), $this->requester))
        ->toThrow(QueueBusyException::class);

    expect($this->scheduled->fresh()->media)->toHaveCount(0)
        ->and($this->scheduled->fresh()->status)->toBe(PostStatus::Scheduled);
});
