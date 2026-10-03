<?php

declare(strict_types=1);

use App\Enums\Post\Status;
use App\Enums\PostPlatform\Status as PlatformStatus;
use App\Enums\SocialAccount\Platform;
use App\Jobs\SendNotification;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Social\GoogleBusinessDerivativeCleaner;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->user = User::factory()->create([]);
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('member'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
});

// Index tests
test('accounts index requires authentication', function () {
    $response = $this->get(route('app.workspace.channels'));

    $response->assertRedirect(route('login'));
});

test('accounts index shows platforms and connected accounts', function () {
    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
    ]);

    $response = $this->actingAs($this->user)->get(route('app.workspace.channels'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('settings/workspace/Channels', false)
        ->has('connectedChannels', 1)
    );
});

test('accounts index lists every account of the same network', function () {
    [$first, $second] = SocialAccount::withoutEvents(fn () => [
        SocialAccount::factory()->create([
            'workspace_id' => $this->workspace->id,
            'platform' => Platform::LinkedIn,
            'platform_user_id' => 'li-a',
        ]),
        SocialAccount::factory()->create([
            'workspace_id' => $this->workspace->id,
            'platform' => Platform::LinkedIn,
            'platform_user_id' => 'li-b',
        ]),
    ]);

    $response = $this->actingAs($this->user)->get(route('app.workspace.channels'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('settings/workspace/Channels', false)
        ->has('connectedChannels', 2)
        ->where('connectedChannels', fn ($accounts): bool => collect($accounts)->pluck('id')->contains($first->id)
            && collect($accounts)->pluck('id')->contains($second->id))
    );
});

test('a connected linkedin page account is still returned so it surfaces under the linkedin card', function () {
    SocialAccount::factory()->linkedinPage()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    $response = $this->actingAs($this->user)->get(route('app.workspace.channels'));

    $response->assertOk();
    // The grid groups by the account's own `network`, so a linkedin-page account
    // must report network=linkedin to surface under the single LinkedIn card.
    $response->assertInertia(fn ($page) => $page
        ->component('settings/workspace/Channels', false)
        ->where('connectedChannels.0.platform', Platform::LinkedInPage->value)
        ->where('connectedChannels.0.network', 'linkedin')
    );
});

test('a connected instagram-facebook account is still returned so it surfaces under the instagram card', function () {
    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::InstagramFacebook,
        'scopes' => Platform::InstagramFacebook->requiredPublishScopes(),
    ]);

    $response = $this->actingAs($this->user)->get(route('app.workspace.channels'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('settings/workspace/Channels', false)
        ->where('connectedChannels.0.platform', Platform::InstagramFacebook->value)
        ->where('connectedChannels.0.network', 'instagram')
    );
});

test('an unsubscribed account can disconnect without an active subscription', function () {
    config(['trypost.self_hosted' => false]);

    $account = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id]);

    $response = $this->actingAs($this->user)->delete(route('app.channels.disconnect', $account));

    $response->assertRedirect();
    $response->assertSessionMissing('errors');
    expect(SocialAccount::find($account->id))->toBeNull();
});

test('accounts index redirects if no workspace', function () {
    $this->user->update(['current_workspace_id' => null]);

    $response = $this->actingAs($this->user)->get(route('app.workspace.channels'));

    $response->assertRedirect(route('app.workspaces.create'));
});

// Disconnect tests
test('disconnect requires authentication', function () {
    $account = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id]);

    $response = $this->delete(route('app.channels.disconnect', $account));

    $response->assertRedirect(route('login'));
});

test('disconnect removes social account', function () {
    $account = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id]);

    $response = $this->actingAs($this->user)->delete(route('app.channels.disconnect', $account));

    $response->assertRedirect();
    expect(SocialAccount::find($account->id))->toBeNull();
});

test('disconnect deletes the channel drafts and published history', function () {
    $account = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id]);

    $draftPost = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => Status::Draft,
    ]);
    $publishedPost = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => Status::Published,
    ]);

    PostPlatform::factory()->create([
        'post_id' => $draftPost->id,
        'social_account_id' => $account->id,
        'status' => PlatformStatus::Pending,
    ]);
    PostPlatform::factory()->create([
        'post_id' => $publishedPost->id,
        'social_account_id' => $account->id,
        'status' => PlatformStatus::Published,
    ]);

    $this->actingAs($this->user)->delete(route('app.channels.disconnect', $account));

    expect(Post::query()->whereKey([$draftPost->id, $publishedPost->id])->exists())->toBeFalse();
});

test('disconnect deletes a google business post still waiting on the account and prunes its image', function () {
    Queue::fake([SendNotification::class]);
    Storage::fake();

    $account = SocialAccount::factory()->googleBusiness()->create(['workspace_id' => $this->workspace->id]);
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => Status::Publishing,
    ]);
    $target = PostPlatform::factory()->googleBusiness()->pendingReview()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform_post_id' => 'accounts/1/locations/2/localPosts/3',
    ]);
    $path = GoogleBusinessDerivativeCleaner::pathFor($target->id);
    Storage::put($path, 'image');

    $this->actingAs($this->user)->delete(route('app.channels.disconnect', $account));

    expect(SocialAccount::find($account->id))->toBeNull()
        ->and(Post::query()->whereKey($post->id)->exists())->toBeFalse();
    Storage::assertMissing($path);
    Queue::assertNotPushed(SendNotification::class);
});

test('disconnect returns 403 for other workspace account', function () {
    $otherWorkspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->create(['workspace_id' => $otherWorkspace->id]);

    $response = $this->actingAs($this->user)->delete(route('app.channels.disconnect', $account));

    $response->assertForbidden();
});

// Member authorization tests
test('member cannot disconnect social account', function () {
    $member = User::factory()->create([]);
    $this->workspace->members()->attach($member->id, membershipPivot('member'));
    $member->update(['current_workspace_id' => $this->workspace->id]);

    $account = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($member)->delete(route('app.channels.disconnect', $account))->assertForbidden();
});
