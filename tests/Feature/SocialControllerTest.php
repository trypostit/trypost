<?php

declare(strict_types=1);

use App\Enums\Post\Status;
use App\Enums\SocialAccount\Platform;
use App\Enums\UserWorkspace\Role;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;

beforeEach(function () {
    $this->user = User::factory()->create([]);
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, ['role' => Role::Member->value]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
});

// Index tests
test('accounts index requires authentication', function () {
    $response = $this->get(route('app.accounts'));

    $response->assertRedirect(route('login'));
});

test('accounts index shows platforms and connected accounts', function () {
    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
    ]);

    $response = $this->actingAs($this->user)->get(route('app.accounts'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('accounts/Index', false)
        ->has('workspace')
        ->has('platforms')
        ->has('platforms.0.network')
        ->has('connectedAccounts', 1)
    );
});

test('accounts index lists platforms alphabetically by label', function () {
    $response = $this->actingAs($this->user)->get(route('app.accounts'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('accounts/Index', false)
        ->where('platforms', function ($platforms): bool {
            $labels = collect($platforms)->pluck('label')->all();

            $sorted = $labels;
            natcasesort($sorted);

            return array_values($labels) === array_values($sorted);
        })
    );
});

test('accounts index offers a single linkedin card and no standalone linkedin page card', function () {
    $response = $this->actingAs($this->user)->get(route('app.accounts'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('accounts/Index', false)
        ->where('platforms', fn ($platforms) => collect($platforms)->contains('value', Platform::LinkedIn->value)
            && ! collect($platforms)->contains('value', Platform::LinkedInPage->value)
        )
    );
});

test('the linkedin card still shows when only company pages are enabled', function () {
    config(['trypost.platforms.linkedin.enabled' => false]);
    config(['trypost.platforms.linkedin-page.enabled' => true]);

    $response = $this->actingAs($this->user)->get(route('app.accounts'));

    $response->assertInertia(fn ($page) => $page
        ->component('accounts/Index', false)
        ->where('platforms', fn ($platforms) => collect($platforms)->contains('value', Platform::LinkedIn->value))
    );
});

test('the linkedin card disappears only when both capabilities are disabled', function () {
    config(['trypost.platforms.linkedin.enabled' => false]);
    config(['trypost.platforms.linkedin-page.enabled' => false]);

    $response = $this->actingAs($this->user)->get(route('app.accounts'));

    $response->assertInertia(fn ($page) => $page
        ->component('accounts/Index', false)
        ->where('platforms', fn ($platforms) => ! collect($platforms)->contains('value', Platform::LinkedIn->value))
    );
});

test('a connected linkedin page account is still returned so it surfaces under the linkedin card', function () {
    SocialAccount::factory()->linkedinPage()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    $response = $this->actingAs($this->user)->get(route('app.accounts'));

    $response->assertOk();
    // The grid groups by the account's own `network`, so a linkedin-page account
    // must report network=linkedin to surface under the single LinkedIn card.
    $response->assertInertia(fn ($page) => $page
        ->component('accounts/Index', false)
        ->where('connectedAccounts.0.platform', Platform::LinkedInPage->value)
        ->where('connectedAccounts.0.network', 'linkedin')
    );
});

test('an unsubscribed account can disconnect during onboarding (no active subscription required)', function () {
    config(['trypost.self_hosted' => false]);

    $account = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id]);

    $response = $this->actingAs($this->user)->delete(route('app.accounts.disconnect', $account));

    $response->assertRedirect();
    $response->assertSessionMissing('errors');
    expect(SocialAccount::find($account->id))->toBeNull();
});

test('accounts index redirects if no workspace', function () {
    $this->user->update(['current_workspace_id' => null]);

    $response = $this->actingAs($this->user)->get(route('app.accounts'));

    $response->assertRedirect(route('app.workspaces.create'));
});

// Disconnect tests
test('disconnect requires authentication', function () {
    $account = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id]);

    $response = $this->delete(route('app.accounts.disconnect', $account));

    $response->assertRedirect(route('login'));
});

test('disconnect removes social account', function () {
    $account = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id]);

    $response = $this->actingAs($this->user)->delete(route('app.accounts.disconnect', $account));

    $response->assertRedirect();
    expect(SocialAccount::find($account->id))->toBeNull();
});

test('disconnect deletes pending platform rows from drafts and keeps published history', function () {
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

    $pendingPlatform = PostPlatform::factory()->create([
        'post_id' => $draftPost->id,
        'social_account_id' => $account->id,
        'status' => App\Enums\PostPlatform\Status::Pending,
    ]);
    $publishedPlatform = PostPlatform::factory()->create([
        'post_id' => $publishedPost->id,
        'social_account_id' => $account->id,
        'status' => App\Enums\PostPlatform\Status::Published,
        'platform_name' => 'Snapshot Name',
        'platform_avatar' => 'avatars/snapshot.jpg',
    ]);

    $this->actingAs($this->user)->delete(route('app.accounts.disconnect', $account));

    expect(PostPlatform::find($pendingPlatform->id))->toBeNull();

    $publishedPlatform->refresh();
    expect($publishedPlatform->social_account_id)->toBeNull();
    expect($publishedPlatform->platform_name)->toBe('Snapshot Name');
    expect($publishedPlatform->display_avatar)->toContain('avatars/snapshot.jpg');
});

test('disconnect returns 403 for other workspace account', function () {
    $otherWorkspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->create(['workspace_id' => $otherWorkspace->id]);

    $response = $this->actingAs($this->user)->delete(route('app.accounts.disconnect', $account));

    $response->assertForbidden();
});

// Member authorization tests
test('member cannot disconnect social account', function () {
    $member = User::factory()->create([]);
    $this->workspace->members()->attach($member->id, ['role' => Role::Member->value]);
    $member->update(['current_workspace_id' => $this->workspace->id]);

    $account = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($member)->delete(route('app.accounts.disconnect', $account))->assertForbidden();
});

test('member cannot toggle social account', function () {
    $member = User::factory()->create([]);
    $this->workspace->members()->attach($member->id, ['role' => Role::Member->value]);
    $member->update(['current_workspace_id' => $this->workspace->id]);

    $account = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($member)->put(route('app.accounts.toggle', $account))->assertForbidden();
});
