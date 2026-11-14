<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Jobs\SendNotification;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\PostingSchedule;
use App\Support\Social\GoogleBusinessDerivativeCleaner;
use Illuminate\Support\Facades\DB;
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
        ->has('connectedChannels.data', 1)
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
        ->has('connectedChannels.data', 2)
        ->where('connectedChannels.data', fn ($accounts): bool => collect($accounts)->pluck('id')->contains($first->id)
            && collect($accounts)->pluck('id')->contains($second->id))
    );
});

test('accounts index sends each channel time zone, posting times per week, posting goal and where this week stands', function () {
    $this->travelTo(now('UTC')->next('Wednesday')->setTime(12, 0));

    $withGoal = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
        'timezone' => 'UTC',
        'posting_goal' => 5,
        'posting_schedule' => PostingSchedule::empty()->withTime(1, '09:00')->withTime(1, '15:00')->withTime(3, '09:00'),
    ]);
    $withoutGoal = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::X,
        'timezone' => 'UTC',
        'posting_goal' => null,
    ]);

    Post::factory()->forAccount($withGoal)->published()->create(['published_at' => now()->subHour()]);
    Post::factory()->forAccount($withGoal)->scheduled()->create(['scheduled_at' => now()->addHour()]);
    Post::factory()->forAccount($withoutGoal)->published()->create(['published_at' => now()->subHour()]);

    $response = $this->actingAs($this->user)->get(route('app.workspace.channels'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('settings/workspace/Channels', false)
        ->where('connectedChannels.data', function ($accounts) use ($withGoal, $withoutGoal): bool {
            $byId = collect($accounts)->keyBy('id');

            expect(data_get($byId, "{$withGoal->id}.posting_goal"))->toBe(5)
                ->and(data_get($byId, "{$withGoal->id}.sent_this_week"))->toBe(1)
                ->and(data_get($byId, "{$withGoal->id}.scheduled_this_week"))->toBe(1)
                ->and(data_get($byId, "{$withoutGoal->id}.posting_goal"))->toBeNull()
                ->and(data_get($byId, "{$withoutGoal->id}.sent_this_week"))->toBe(0)
                ->and(data_get($byId, "{$withGoal->id}.posting_times_per_week"))->toBe(3)
                ->and(data_get($byId, "{$withoutGoal->id}.posting_times_per_week"))->toBe(0)
                ->and(data_get($byId, "{$withGoal->id}.timezone_option"))->toBe(['value' => 'UTC', 'label' => 'UTC', 'offset' => 'GMT']);

            return true;
        })
    );
});

test('accounts index counts the week of every channel with a goal in the same number of queries', function () {
    $queriesFor = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->user)->get(route('app.workspace.channels'))->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::LinkedIn, 'posting_goal' => 3]);
    $queriesFor();
    $withOneGoal = $queriesFor();

    SocialAccount::factory()->count(3)->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::X, 'posting_goal' => 5]);

    expect($queriesFor())->toBe($withOneGoal);
});

test('accounts index pages the channels at the config page size and counts every channel in the title', function () {
    config(['app.pagination.default' => 2]);
    $channels = collect(range(0, 2))->map(fn (int $position) => SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::X,
        'position' => $position,
    ]));

    $this->actingAs($this->user)->get(route('app.workspace.channels'))
        ->assertInertia(fn ($page) => $page
            ->where('total', 3)
            ->has('connectedChannels.data', 2)
            ->where('connectedChannels.data.0.id', $channels[0]->id)
            ->where('connectedChannels.data.1.id', $channels[1]->id));

    $this->actingAs($this->user)->get(route('app.workspace.channels', ['page' => 2]))
        ->assertInertia(fn ($page) => $page
            ->has('connectedChannels.data', 1)
            ->where('connectedChannels.data.0.id', $channels[2]->id));
});

test('accounts index searches every word in the channel name or username', function () {
    $acme = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::X, 'display_name' => 'Acme Store', 'username' => 'acmestore']);
    $beta = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::X, 'display_name' => 'Beta Labs', 'username' => 'betalabs']);

    $this->actingAs($this->user)->get(route('app.workspace.channels', ['search' => 'ACME']))
        ->assertInertia(fn ($page) => $page
            ->where('filters.search', 'ACME')
            ->where('total', 2)
            ->has('connectedChannels.data', 1)
            ->where('connectedChannels.data.0.id', $acme->id));

    $this->actingAs($this->user)->get(route('app.workspace.channels', ['search' => 'labs betalabs']))
        ->assertInertia(fn ($page) => $page
            ->has('connectedChannels.data', 1)
            ->where('connectedChannels.data.0.id', $beta->id));

    $this->actingAs($this->user)->get(route('app.workspace.channels', ['search' => 'acme labs']))
        ->assertInertia(fn ($page) => $page->has('connectedChannels.data', 0));
});

test('accounts index finds a username searched with a leading @', function () {
    $acme = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::X, 'display_name' => 'Acme Store', 'username' => 'acmestore']);
    SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::X, 'display_name' => 'Beta Labs', 'username' => 'betalabs']);

    $this->actingAs($this->user)->get(route('app.workspace.channels', ['search' => '@acmestore']))
        ->assertInertia(fn ($page) => $page
            ->has('connectedChannels.data', 1)
            ->where('connectedChannels.data.0.id', $acme->id));
});

test('accounts index finds the channels of a network searched by its name', function () {
    $youtube = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::YouTube, 'display_name' => 'Acme Videos', 'username' => 'acmevideos']);
    $x = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::X, 'display_name' => 'Acme Store', 'username' => 'acmestore']);

    $this->actingAs($this->user)->get(route('app.workspace.channels', ['search' => 'YouTube']))
        ->assertInertia(fn ($page) => $page
            ->has('connectedChannels.data', 1)
            ->where('connectedChannels.data.0.id', $youtube->id));

    $this->actingAs($this->user)->get(route('app.workspace.channels', ['search' => 'acme youtube']))
        ->assertInertia(fn ($page) => $page
            ->has('connectedChannels.data', 1)
            ->where('connectedChannels.data.0.id', $youtube->id));

    $this->actingAs($this->user)->get(route('app.workspace.channels', ['search' => 'store']))
        ->assertInertia(fn ($page) => $page
            ->has('connectedChannels.data', 1)
            ->where('connectedChannels.data.0.id', $x->id));
});

test('accounts index drops a network filter the workspace no longer has a channel on', function () {
    $x = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::X]);

    $this->actingAs($this->user)->get(route('app.workspace.channels', ['networks' => ['linkedin']]))
        ->assertInertia(fn ($page) => $page
            ->where('filters.networks', [])
            ->where('networks', ['x'])
            ->has('connectedChannels.data', 1)
            ->where('connectedChannels.data.0.id', $x->id));
});

test('accounts index filters by network, Instagram through Facebook included, and lists the workspace networks', function () {
    $x = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::X, 'position' => 0]);
    $instagram = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::Instagram, 'position' => 1]);
    $viaFacebook = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::InstagramFacebook, 'position' => 2]);

    $this->actingAs($this->user)->get(route('app.workspace.channels', ['networks' => ['instagram', 'not-a-network']]))
        ->assertInertia(fn ($page) => $page
            ->where('filters.networks', ['instagram'])
            ->where('networks', ['x', 'instagram'])
            ->has('connectedChannels.data', 2)
            ->where('connectedChannels.data.0.id', $instagram->id)
            ->where('connectedChannels.data.1.id', $viaFacebook->id));

    $this->actingAs($this->user)->get(route('app.workspace.channels', ['networks' => ['x']]))
        ->assertInertia(fn ($page) => $page
            ->has('connectedChannels.data', 1)
            ->where('connectedChannels.data.0.id', $x->id));
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
        ->where('connectedChannels.data.0.platform', Platform::LinkedInPage->value)
        ->where('connectedChannels.data.0.network', 'linkedin')
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
        ->where('connectedChannels.data.0.platform', Platform::InstagramFacebook->value)
        ->where('connectedChannels.data.0.network', 'instagram')
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

    $draftPost = Post::factory()->forAccount($account)->draft()->create([
        'user_id' => $this->user->id,
    ]);
    $publishedPost = Post::factory()->forAccount($account)->published()->create([
        'user_id' => $this->user->id,
    ]);

    $this->actingAs($this->user)->delete(route('app.channels.disconnect', $account));

    expect(Post::query()->whereKey([$draftPost->id, $publishedPost->id])->exists())->toBeFalse();
});

test('disconnect deletes a google business post still waiting on the account and prunes its image', function () {
    Queue::fake([SendNotification::class]);
    Storage::fake();

    $account = SocialAccount::factory()->googleBusiness()->create(['workspace_id' => $this->workspace->id]);
    $post = Post::factory()->forAccount($account)->pendingReview()->create([
        'user_id' => $this->user->id,
        'platform_post_id' => 'accounts/1/locations/2/localPosts/3',
    ]);
    $path = GoogleBusinessDerivativeCleaner::pathFor($post);
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

test('a workspace admin who is not the owner can disconnect a channel that lost its connection', function () {
    $admin = workspaceMember($this->workspace, 'admin');
    $account = SocialAccount::factory()->tokenExpired()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($admin)
        ->delete(route('app.channels.disconnect', $account))
        ->assertRedirect()
        ->assertSessionHas('flash.banner', __('accounts.flash.disconnected'));

    expect(SocialAccount::find($account->id))->toBeNull();
});

test('a member who needs approval cannot disconnect a channel or its posts', function () {
    $member = workspaceMember($this->workspace, 'approval');
    $account = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id]);
    $post = Post::factory()->forAccount($account)->draft()->create(['user_id' => $member->id]);

    $this->actingAs($member)->delete(route('app.channels.disconnect', $account))->assertForbidden();

    expect(SocialAccount::find($account->id))->not->toBeNull()
        ->and(Post::find($post->id))->not->toBeNull();
});

test('accounts index merges the next page of channels by id', function () {
    SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::X]);

    $page = $this->actingAs($this->user)->get(route('app.workspace.channels'))->viewData('page');

    expect(data_get($page, 'matchPropsOn'))->toContain('connectedChannels.data.id');
});

test('accounts index shows a channel whose stored time zone php does not know in utc instead of failing', function () {
    $channel = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::LinkedIn]);
    $channel->forceFill(['timezone' => 'Gone/Zone'])->saveQuietly();

    $this->actingAs($this->user)
        ->get(route('app.workspace.channels'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('connectedChannels.data.0.timezone_option', ['value' => 'UTC', 'label' => 'UTC', 'offset' => 'GMT'])
            ->etc());
});
