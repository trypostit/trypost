<?php

declare(strict_types=1);

use App\Actions\Post\CreatePosts;
use App\Enums\Post\Status;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Post\ApprovePostTool;
use App\Mcp\Tools\Post\AttachMediaFromUploadTool;
use App\Mcp\Tools\Post\AttachMediaFromUrlTool;
use App\Mcp\Tools\Post\CreatePostsTool;
use App\Mcp\Tools\Post\DeletePostTool;
use App\Mcp\Tools\Post\GetPostMetricsTool;
use App\Mcp\Tools\Post\GetPostTool;
use App\Mcp\Tools\Post\ListPostsTool;
use App\Mcp\Tools\Post\PreviewPostTool;
use App\Mcp\Tools\Post\PublishPostTool;
use App\Mcp\Tools\Post\RejectPostTool;
use App\Mcp\Tools\Post\UpdatePostTool;
use App\Models\Media;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

function approvalsParityPending(Workspace $workspace, User $requester, SocialAccount $account, string $content = 'Needs a review'): Post
{
    return CreatePosts::execute($workspace, $requester, [
        'status' => 'scheduled',
        'scheduled_at' => now()->addDays(2)->toIso8601String(),
        'content' => $content,
        'media' => [],
        'destinations' => [[
            'social_account_id' => $account->id,
            'content_type' => ContentType::LinkedInPost->value,
            'meta' => [],
        ]],
    ])->sole();
}

function approvalsParitySnapshot(Post $post): array
{
    $post = $post->fresh();

    return [
        'status' => $post->status,
        'scheduled' => $post->scheduled_at !== null,
        'approved_by' => $post->approved_by === null ? null : 'approver',
    ];
}

beforeEach(function () {
    Queue::fake();
    ['user' => $this->owner, 'workspace' => $this->workspace, 'token' => $this->ownerToken] = parityContext();
    $this->member = workspaceMember($this->workspace, 'approval');
    $this->memberToken = passportToken($this->member, $this->workspace);
    $this->publisher = workspaceMember($this->workspace, 'member');
    $this->account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
        'timezone' => 'UTC',
    ]);
    $this->destination = ['social_account_id' => $this->account->id, 'content_type' => 'linkedin_post'];
});

test('a member who needs approval ends pending when creating through mcp while the api is closed to non-admin members', function () {
    $payload = [
        'content' => 'Needs a review',
        'status' => 'scheduled',
        'scheduled_at' => now()->addDay()->toIso8601String(),
        ...$this->destination,
    ];

    $this->withHeaders(parityApi($this->memberToken))
        ->postJson(route('api.posts.store'), $payload)
        ->assertForbidden()
        ->assertJsonPath('message', 'Insufficient workspace permissions.');

    TryPostServer::actingAs($this->member)->tool(CreatePostsTool::class, [
        'content' => 'Needs a review',
        'status' => 'scheduled',
        'scheduled_at' => now()->addDay()->toIso8601String(),
        'destinations' => [$this->destination],
    ])->assertOk();

    $statuses = Post::query()->where('workspace_id', $this->workspace->id)->pluck('status')->unique()->all();

    expect($statuses)->toBe([Status::PendingApproval]);
});

test('a member who needs approval ends pending when scheduling a draft through mcp while the api is closed to them', function () {
    $draft = Post::factory()->forAccount($this->account, ContentType::LinkedInPost)->draft()->create(['user_id' => $this->member->id, 'content' => 'Draft to schedule']);
    $at = now()->addDays(2)->toIso8601String();

    $this->withHeaders(parityApi($this->memberToken))
        ->putJson(route('api.posts.update', $draft), ['status' => 'scheduled', 'scheduled_at' => $at])
        ->assertForbidden();
    TryPostServer::actingAs($this->member)
        ->tool(PublishPostTool::class, ['post_id' => $draft->id, 'scheduled_at' => $at])
        ->assertOk();

    expect($draft->fresh()->status)->toBe(Status::PendingApproval);
});

test('an approver approves with the requested time through web, api and mcp', function () {
    $web = approvalsParityPending($this->workspace, $this->member, $this->account);
    $api = approvalsParityPending($this->workspace, $this->member, $this->account);
    $mcp = approvalsParityPending($this->workspace, $this->member, $this->account);

    $this->actingAs($this->owner)->put(route('app.posts.approve', $web))->assertRedirect();
    $this->withHeaders(parityApi($this->ownerToken))->postJson(route('api.posts.approve', $api))->assertOk();
    TryPostServer::actingAs($this->owner)->tool(ApprovePostTool::class, ['post_id' => $mcp->id])->assertOk();

    expect(approvalsParitySnapshot($api))->toBe(approvalsParitySnapshot($web))
        ->and(approvalsParitySnapshot($mcp))->toBe(approvalsParitySnapshot($web))
        ->and($web->fresh()->status)->toBe(Status::Scheduled);
});

test('an approver approves with a new time through web, api and mcp', function () {
    $web = approvalsParityPending($this->workspace, $this->member, $this->account);
    $api = approvalsParityPending($this->workspace, $this->member, $this->account);
    $mcp = approvalsParityPending($this->workspace, $this->member, $this->account);
    $at = now()->addDays(5)->startOfHour()->toIso8601String();

    $this->actingAs($this->owner)->put(route('app.posts.approve', $web), ['scheduled_at' => $at])->assertRedirect();
    $this->withHeaders(parityApi($this->ownerToken))->postJson(route('api.posts.approve', $api), ['scheduled_at' => $at])->assertOk();
    TryPostServer::actingAs($this->owner)->tool(ApprovePostTool::class, ['post_id' => $mcp->id, 'scheduled_at' => $at])->assertOk();

    expect($web->fresh()->scheduled_at->toIso8601String())->toBe($api->fresh()->scheduled_at->toIso8601String())
        ->and($mcp->fresh()->scheduled_at->toIso8601String())->toBe($web->fresh()->scheduled_at->toIso8601String())
        ->and($web->fresh()->scheduled_at->toIso8601String())->toBe(now()->addDays(5)->startOfHour()->toIso8601String());
});

test('an approver rejects through web, api and mcp and the post goes back to a draft', function () {
    $web = approvalsParityPending($this->workspace, $this->member, $this->account);
    $api = approvalsParityPending($this->workspace, $this->member, $this->account);
    $mcp = approvalsParityPending($this->workspace, $this->member, $this->account);

    $this->actingAs($this->owner)->put(route('app.posts.reject', $web))->assertRedirect();
    $this->withHeaders(parityApi($this->ownerToken))->postJson(route('api.posts.reject', $api))->assertOk();
    TryPostServer::actingAs($this->owner)->tool(RejectPostTool::class, ['post_id' => $mcp->id])->assertOk();

    expect($web->fresh()->status)->toBe(Status::Draft)
        ->and($api->fresh()->status)->toBe(Status::Draft)
        ->and($mcp->fresh()->status)->toBe(Status::Draft);
});

test('a member who needs approval cannot approve or reject through web, api or mcp', function () {
    $post = approvalsParityPending($this->workspace, $this->member, $this->account);

    $this->actingAs($this->member)->put(route('app.posts.approve', $post))->assertForbidden();
    $this->actingAs($this->member)->put(route('app.posts.reject', $post))->assertForbidden();
    $this->withHeaders(parityApi($this->memberToken))->postJson(route('api.posts.approve', $post))->assertForbidden();
    $this->withHeaders(parityApi($this->memberToken))->postJson(route('api.posts.reject', $post))->assertForbidden();
    TryPostServer::actingAs($this->member)->tool(ApprovePostTool::class, ['post_id' => $post->id])->assertHasErrors();
    TryPostServer::actingAs($this->member)->tool(RejectPostTool::class, ['post_id' => $post->id])->assertHasErrors();

    expect($post->fresh()->status)->toBe(Status::PendingApproval);
});

test('a member who publishes directly can approve through mcp but the api refuses non-admin members', function () {
    $api = approvalsParityPending($this->workspace, $this->member, $this->account);
    $mcp = approvalsParityPending($this->workspace, $this->member, $this->account);
    $token = passportToken($this->publisher, $this->workspace);

    $this->withHeaders(parityApi($token))->postJson(route('api.posts.approve', $api))->assertForbidden();
    TryPostServer::actingAs($this->publisher)->tool(ApprovePostTool::class, ['post_id' => $mcp->id])->assertOk();

    expect($mcp->fresh()->status)->toBe(Status::Scheduled)
        ->and($api->fresh()->status)->toBe(Status::PendingApproval);
});

test('a member who publishes directly can reject through the web', function () {
    $post = approvalsParityPending($this->workspace, $this->member, $this->account);

    $this->actingAs($this->publisher)->put(route('app.posts.reject', $post))->assertRedirect();

    expect($post->fresh()->status)->toBe(Status::Draft);
});

test('a requester editing an approved scheduled post sends it back for approval through mcp', function () {
    $post = approvalsParityPending($this->workspace, $this->member, $this->account);
    TryPostServer::actingAs($this->owner)->tool(ApprovePostTool::class, ['post_id' => $post->id])->assertOk();
    expect($post->fresh()->status)->toBe(Status::Scheduled);

    TryPostServer::actingAs($this->member)
        ->tool(UpdatePostTool::class, ['post_id' => $post->id, 'content' => 'Edited after approval', 'status' => 'scheduled'])
        ->assertOk();

    expect($post->fresh()->status)->toBe(Status::PendingApproval);
});

test('a requester queueing or publishing now through mcp ends pending', function () {
    $this->account->update(['posting_schedule' => collect(range(0, 6))
        ->map(fn (int $day) => ['day' => $day, 'enabled' => true, 'times' => ['09:00', '15:00']])
        ->all()]);
    $queued = [
        'content' => 'Queued request',
        'status' => 'scheduled',
        'queue' => 'next',
        'destinations' => [$this->destination],
    ];
    TryPostServer::actingAs($this->member)->tool(CreatePostsTool::class, $queued)->assertOk();

    $draft = Post::factory()->forAccount($this->account, ContentType::LinkedInPost)->draft()->create(['user_id' => $this->member->id, 'content' => 'Publish me']);
    TryPostServer::actingAs($this->member)->tool(PublishPostTool::class, ['post_id' => $draft->id])->assertOk();

    expect($draft->fresh()->status)->toBe(Status::PendingApproval)
        ->and(Post::query()->where('content', 'Queued request')->sole()->status)->toBe(Status::PendingApproval);
});

test('a post that is no longer pending cannot be approved through api or mcp', function () {
    $post = Post::factory()->draft()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->member->id]);

    $this->withHeaders(parityApi($this->ownerToken))->postJson(route('api.posts.approve', $post))->assertUnprocessable();
    TryPostServer::actingAs($this->owner)->tool(ApprovePostTool::class, ['post_id' => $post->id])->assertHasErrors();
});

test('a requester sees only their own pending requests through the mcp list and get, like the web approvals scope', function () {
    $other = workspaceMember($this->workspace, 'approval');
    $foreign = approvalsParityPending($this->workspace, $other, $this->account, 'Somebody else request');
    $own = approvalsParityPending($this->workspace, $this->member, $this->account, 'My request');
    $otherDraft = Post::factory()->draft()->create(['workspace_id' => $this->workspace->id, 'user_id' => $other->id, 'content' => 'Somebody else draft']);

    TryPostServer::actingAs($this->member)
        ->tool(ListPostsTool::class, ['status' => 'pending_approval'])
        ->assertOk()
        ->assertSee($own->id)
        ->assertDontSee($foreign->id);

    TryPostServer::actingAs($this->member)
        ->tool(ListPostsTool::class)
        ->assertOk()
        ->assertSee($own->id)
        ->assertSee($otherDraft->id)
        ->assertDontSee($foreign->id);

    TryPostServer::actingAs($this->member)->tool(GetPostTool::class, ['post_id' => $own->id])->assertOk();
    TryPostServer::actingAs($this->member)->tool(GetPostTool::class, ['post_id' => $foreign->id])->assertHasErrors(['Post not found.']);
    TryPostServer::actingAs($this->member)->tool(PreviewPostTool::class, ['post_id' => $own->id])->assertOk();
    TryPostServer::actingAs($this->member)->tool(PreviewPostTool::class, ['post_id' => $foreign->id])->assertHasErrors(['Post not found.']);
    TryPostServer::actingAs($this->member)->tool(GetPostMetricsTool::class, ['post_id' => $own->id])->assertOk();
    TryPostServer::actingAs($this->member)->tool(GetPostMetricsTool::class, ['post_id' => $foreign->id])->assertHasErrors(['Post not found.']);

    expect(Post::query()->visiblePendingApprovalsFor($this->member)->pluck('id')->all())
        ->toContain($own->id)
        ->not->toContain($foreign->id);
});

test('an approver still sees every pending request through mcp and the api', function () {
    $other = workspaceMember($this->workspace, 'approval');
    $foreign = approvalsParityPending($this->workspace, $other, $this->account, 'Somebody else request');
    $own = approvalsParityPending($this->workspace, $this->member, $this->account, 'My request');

    TryPostServer::actingAs($this->owner)
        ->tool(ListPostsTool::class, ['status' => 'pending_approval'])
        ->assertOk()
        ->assertSee($foreign->id)
        ->assertSee($own->id);
    TryPostServer::actingAs($this->owner)->tool(GetPostTool::class, ['post_id' => $foreign->id])->assertOk();

    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->ownerToken))->getJson(route('api.posts.index'))
        ->assertOk()
        ->assertJsonFragment(['id' => $foreign->id])
        ->assertJsonFragment(['id' => $own->id]);
    $this->withHeaders(parityApi($this->ownerToken))->getJson(route('api.posts.show', $foreign))->assertOk();
});

test('attaching media to an approved scheduled post sends it back for approval through mcp while the api is closed to the member', function () {
    Storage::fake();
    $post = approvalsParityPending($this->workspace, $this->member, $this->account);
    $token = (string) Str::uuid();
    Media::factory()->stored()->create([
        'mediable_type' => (new Workspace)->getMorphClass(),
        'mediable_id' => $this->workspace->id,
        'collection' => 'uploads',
        'upload_token' => $token,
    ]);

    $this->withHeaders(parityApi($this->memberToken))
        ->postJson(route('api.posts.attach-media-from-upload', $post), ['upload_token' => $token])
        ->assertForbidden();
    TryPostServer::actingAs($this->owner)->tool(ApprovePostTool::class, ['post_id' => $post->id])->assertOk();
    expect($post->fresh()->status)->toBe(Status::Scheduled);
    TryPostServer::actingAs($this->member)
        ->tool(AttachMediaFromUploadTool::class, ['post_id' => $post->id, 'upload_token' => $token])
        ->assertOk();

    expect($post->fresh()->status)->toBe(Status::PendingApproval);
});

test('approving with an invalid time gives the same validation message on api and mcp', function () {
    $api = approvalsParityPending($this->workspace, $this->member, $this->account);
    $mcp = approvalsParityPending($this->workspace, $this->member, $this->account);
    $body = ['scheduled_at' => now()->subDay()->toIso8601String()];

    $response = $this->withHeaders(parityApi($this->ownerToken))
        ->postJson(route('api.posts.approve', $api), $body)
        ->assertUnprocessable();

    TryPostServer::actingAs($this->owner)
        ->tool(ApprovePostTool::class, ['post_id' => $mcp->id, ...$body])
        ->assertHasErrors([$response->json('errors.scheduled_at.0')]);
});

test('approving with both a time and publish now gives the same validation message on api and mcp', function () {
    $api = approvalsParityPending($this->workspace, $this->member, $this->account);
    $mcp = approvalsParityPending($this->workspace, $this->member, $this->account);
    $body = ['publish_now' => true, 'scheduled_at' => now()->addDays(3)->toIso8601String()];

    $response = $this->withHeaders(parityApi($this->ownerToken))
        ->postJson(route('api.posts.approve', $api), $body)
        ->assertUnprocessable();

    TryPostServer::actingAs($this->owner)
        ->tool(ApprovePostTool::class, ['post_id' => $mcp->id, ...$body])
        ->assertHasErrors([$response->json('errors.scheduled_at.0')]);
});

test('approving a post that is no longer pending gives the same message on api and mcp', function () {
    $post = Post::factory()->draft()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->member->id]);

    $response = $this->withHeaders(parityApi($this->ownerToken))
        ->postJson(route('api.posts.approve', $post))
        ->assertUnprocessable();

    TryPostServer::actingAs($this->owner)
        ->tool(ApprovePostTool::class, ['post_id' => $post->id])
        ->assertHasErrors([$response->json('errors.post.0')]);
});

test('a non-approver is refused with the web authorization message through mcp', function () {
    $post = approvalsParityPending($this->workspace, $this->member, $this->account);
    $message = $this->actingAs($this->member)->put(route('app.posts.approve', $post))->assertForbidden()->exception->getMessage();

    TryPostServer::actingAs($this->member)->tool(ApprovePostTool::class, ['post_id' => $post->id])->assertHasErrors([$message]);
    TryPostServer::actingAs($this->member)->tool(RejectPostTool::class, ['post_id' => $post->id])->assertHasErrors([$message]);
});

test('a requester gets not found on web, mcp read and mcp write for another member request', function () {
    Storage::fake();
    Http::fake(['example.com/*' => Http::response(file_get_contents(__DIR__.'/../../fixtures/1x1.png'), 200, ['Content-Type' => 'image/png'])]);
    $other = workspaceMember($this->workspace, 'approval');
    $foreign = approvalsParityPending($this->workspace, $other, $this->account, 'Somebody else request');
    $token = (string) Str::uuid();
    Media::factory()->stored()->create([
        'mediable_type' => (new Workspace)->getMorphClass(),
        'mediable_id' => $this->workspace->id,
        'collection' => 'uploads',
        'upload_token' => $token,
    ]);
    $mcp = TryPostServer::actingAs($this->member);

    $mcp->tool(UpdatePostTool::class, ['post_id' => $foreign->id, 'content' => 'Hijacked'])->assertHasErrors(['Post not found.']);
    $mcp->tool(PublishPostTool::class, ['post_id' => $foreign->id])->assertHasErrors(['Post not found.']);
    $mcp->tool(DeletePostTool::class, ['post_id' => $foreign->id])->assertHasErrors(['Post not found.']);
    $mcp->tool(AttachMediaFromUploadTool::class, ['post_id' => $foreign->id, 'upload_token' => $token])->assertHasErrors(['Post not found.']);
    $mcp->tool(AttachMediaFromUrlTool::class, ['post_id' => $foreign->id, 'urls' => [['url' => 'https://example.com/photo.jpg']]])->assertHasErrors(['Post not found.']);

    $this->actingAs($this->member)->get(route('app.posts.edit', $foreign))->assertNotFound();
    $this->actingAs($this->member)->put(route('app.posts.update', $foreign), ['content' => 'Hijacked', 'status' => 'draft'])->assertNotFound();
    $this->actingAs($this->member)->delete(route('app.posts.destroy', $foreign))->assertNotFound();

    $fresh = $foreign->fresh();
    expect($fresh)->not->toBeNull()
        ->and($fresh->content)->toBe('Somebody else request')
        ->and($fresh->status)->toBe(Status::PendingApproval)
        ->and($fresh->ownedMedia()->count())->toBe(0);
});

test('the requester and an approver still reach a pending request on web and mcp', function () {
    $own = approvalsParityPending($this->workspace, $this->member, $this->account, 'My request');

    TryPostServer::actingAs($this->member)->tool(UpdatePostTool::class, ['post_id' => $own->id, 'content' => 'My edited request'])->assertOk();
    $this->actingAs($this->member)->get(route('app.posts.edit', $own))->assertRedirect();
    TryPostServer::actingAs($this->owner)->tool(GetPostTool::class, ['post_id' => $own->id])->assertOk();
    TryPostServer::actingAs($this->publisher)->tool(UpdatePostTool::class, ['post_id' => $own->id, 'content' => 'Approver edit'])->assertOk();
    $this->actingAs($this->publisher)->get(route('app.posts.edit', $own))->assertRedirect();
    $this->actingAs($this->owner)->delete(route('app.posts.destroy', $own))->assertRedirect();

    expect(Post::query()->find($own->id))->toBeNull();
});

test('a member who needs approval deletes only their own posts on web, api and mcp', function () {
    $scheduled = fn (User $author): Post => Post::factory()->forAccount($this->account, ContentType::LinkedInPost)->scheduled()->create([
        'user_id' => $author->id,
    ]);
    $othersOnWeb = $scheduled($this->publisher);
    $othersOnMcp = $scheduled($this->publisher);
    $ownOnWeb = $scheduled($this->member);
    $ownOnMcp = $scheduled($this->member);
    $requested = approvalsParityPending($this->workspace, $this->member, $this->account, 'My request');
    $approved = $scheduled($this->publisher);
    $approved->update(['approval_requested_by' => $this->member->id]);

    $this->withHeaders(parityApi($this->memberToken))->deleteJson(route('api.posts.destroy', $approved))->assertForbidden();
    $this->withHeaders(parityApi($this->memberToken))->deleteJson(route('api.posts.destroy', $othersOnWeb))->assertForbidden();
    auth()->forgetGuards();
    $this->actingAs($this->member)->delete(route('app.posts.destroy', $approved))->assertForbidden();
    TryPostServer::actingAs($this->member)->tool(DeletePostTool::class, ['post_id' => $approved->id])
        ->assertHasErrors(['This action is unauthorized.']);

    $this->actingAs($this->member)->delete(route('app.posts.destroy', $othersOnWeb))->assertForbidden();
    TryPostServer::actingAs($this->member)->tool(DeletePostTool::class, ['post_id' => $othersOnMcp->id])
        ->assertHasErrors(['This action is unauthorized.']);

    $this->actingAs($this->member)->delete(route('app.posts.destroy', $ownOnWeb))->assertRedirect();
    TryPostServer::actingAs($this->member)->tool(DeletePostTool::class, ['post_id' => $ownOnMcp->id])->assertOk();
    TryPostServer::actingAs($this->member)->tool(DeletePostTool::class, ['post_id' => $requested->id])->assertOk();

    $this->actingAs($this->publisher)->delete(route('app.posts.destroy', $othersOnWeb))->assertRedirect();
    TryPostServer::actingAs($this->publisher)->tool(DeletePostTool::class, ['post_id' => $othersOnMcp->id])->assertOk();

    expect(Post::query()->whereKey([$othersOnWeb->id, $othersOnMcp->id, $ownOnWeb->id, $ownOnMcp->id, $requested->id])->count())->toBe(0);
});

test('mcp post tools answer a validation error for a non uuid post id', function (string $tool) {
    TryPostServer::actingAs($this->owner)
        ->tool($tool, ['post_id' => 'not-a-uuid'])
        ->assertHasErrors(['The post id field must be a valid UUID.']);
})->with([
    'get' => GetPostTool::class,
    'update' => UpdatePostTool::class,
    'delete' => DeletePostTool::class,
    'publish' => PublishPostTool::class,
    'preview' => PreviewPostTool::class,
    'metrics' => GetPostMetricsTool::class,
    'attach from url' => AttachMediaFromUrlTool::class,
    'attach from upload' => AttachMediaFromUploadTool::class,
]);

test('get post through mcp fails closed when the user has no current workspace', function () {
    $own = approvalsParityPending($this->workspace, $this->member, $this->account);
    $this->owner->forceFill(['current_workspace_id' => null])->save();

    TryPostServer::actingAs($this->owner->fresh())
        ->tool(GetPostTool::class, ['post_id' => $own->id])
        ->assertHasErrors(['This action is unauthorized.']);
});
