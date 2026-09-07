<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\PostPlatform\Status as PostPlatformStatus;
use App\Enums\Repurpose\PublishMode;
use App\Enums\Repurpose\SourceFormat;
use App\Enums\Repurpose\Status;
use App\Enums\SocialAccount\Platform;
use App\Enums\UserWorkspace\Role;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\Repurpose;
use App\Models\RepurposeItem;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);

    $this->source = SocialAccount::factory()->for($this->workspace)->create(['platform' => Platform::Instagram]);
    $this->tiktok = SocialAccount::factory()->for($this->workspace)->create(['platform' => Platform::TikTok]);
});

function destinationPayload(SocialAccount $account): array
{
    return [
        'social_account_id' => $account->id,
        'content_type' => ContentType::TikTokVideo->value,
        'meta' => ['privacy_level' => 'PUBLIC_TO_EVERYONE'],
    ];
}

test('the index lists repurposes and the ready-made templates', function () {
    Repurpose::factory()->create([
        'workspace_id' => $this->workspace->id,
        'source_social_account_id' => $this->source->id,
    ]);

    $this->actingAs($this->user)
        ->get(route('app.repurposes.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('repurposes/Index')
            ->has('repurposes.data', 1)
            ->where('repurposes.data.0.source_account.id', $this->source->id)
            ->has('templates', 2)
            ->has('sourceAccounts', 1));
});

test('only networks we can download from are offered as a source', function () {
    $this->actingAs($this->user)
        ->get(route('app.repurposes.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('sourceAccounts', 1)
            ->where('sourceAccounts.0.id', $this->source->id));
});

test('storing creates a draft and redirects to its page', function () {
    $response = $this->actingAs($this->user)
        ->post(route('app.repurposes.store'), ['source_social_account_id' => $this->source->id]);

    $repurpose = Repurpose::sole();

    $response->assertRedirect(route('app.repurposes.show', $repurpose));

    expect($repurpose->status)->toBe(Status::Draft);
});

test('storing for an account that already has a repurpose redirects to the existing one', function () {
    $existing = Repurpose::factory()->create([
        'workspace_id' => $this->workspace->id,
        'source_social_account_id' => $this->source->id,
    ]);

    $this->actingAs($this->user)
        ->post(route('app.repurposes.store'), ['source_social_account_id' => $this->source->id])
        ->assertRedirect(route('app.repurposes.show', $existing));

    expect(Repurpose::count())->toBe(1);
});

test('the show page renders the repurpose, its destinations and its items', function () {
    $repurpose = Repurpose::factory()->create([
        'workspace_id' => $this->workspace->id,
        'source_social_account_id' => $this->source->id,
    ]);

    $this->actingAs($this->user)
        ->get(route('app.repurposes.show', $repurpose))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('repurposes/Show')
            ->where('repurpose.id', $repurpose->id)
            ->has('destinationAccounts', 2)
            ->has('items'));
});

test('updating saves destinations with their platform meta', function () {
    $repurpose = Repurpose::factory()->create([
        'workspace_id' => $this->workspace->id,
        'source_social_account_id' => $this->source->id,
    ]);

    $destination = destinationPayload($this->tiktok);

    $this->actingAs($this->user)
        ->put(route('app.repurposes.update', $repurpose), ['destinations' => [$destination]])
        ->assertRedirect();

    expect($repurpose->fresh()->destinations)->toEqual([$destination]);
});

test('the status transitions are exposed', function () {
    $repurpose = Repurpose::factory()->create([
        'workspace_id' => $this->workspace->id,
        'source_social_account_id' => $this->source->id,
        'destinations' => [destinationPayload($this->tiktok)],
    ]);

    $this->actingAs($this->user)->post(route('app.repurposes.activate', $repurpose))->assertRedirect();
    expect($repurpose->fresh()->status)->toBe(Status::Active);

    $this->actingAs($this->user)->post(route('app.repurposes.pause', $repurpose))->assertRedirect();
    expect($repurpose->fresh()->status)->toBe(Status::Paused);

    $this->actingAs($this->user)->post(route('app.repurposes.resume', $repurpose))->assertRedirect();
    expect($repurpose->fresh()->status)->toBe(Status::Active);

    $this->actingAs($this->user)->post(route('app.repurposes.disable', $repurpose))->assertRedirect();
    expect($repurpose->fresh()->status)->toBe(Status::Disabled);
});

test('activating without a destination fails validation', function () {
    $repurpose = Repurpose::factory()->create([
        'workspace_id' => $this->workspace->id,
        'source_social_account_id' => $this->source->id,
    ]);

    $this->actingAs($this->user)
        ->post(route('app.repurposes.activate', $repurpose))
        ->assertSessionHasErrors('destinations');
});

test('deleting removes the repurpose', function () {
    $repurpose = Repurpose::factory()->create([
        'workspace_id' => $this->workspace->id,
        'source_social_account_id' => $this->source->id,
    ]);

    $this->actingAs($this->user)
        ->delete(route('app.repurposes.destroy', $repurpose))
        ->assertRedirect(route('app.repurposes.index'));

    expect(Repurpose::count())->toBe(0);
});

test('a viewer cannot create a repurpose', function () {
    $viewer = User::factory()->create([
        'account_id' => $this->user->account_id,
        'current_workspace_id' => $this->workspace->id,
    ]);
    $this->workspace->members()->attach($viewer->id, ['role' => Role::Viewer->value]);

    $this->actingAs($viewer)
        ->post(route('app.repurposes.store'), ['source_social_account_id' => $this->source->id])
        ->assertForbidden();
});

test('a repurpose from another workspace is not reachable', function () {
    $stranger = Repurpose::factory()->create();

    $this->actingAs($this->user)
        ->get(route('app.repurposes.show', $stranger))
        ->assertForbidden();
});

test('an account from another workspace cannot become a source', function () {
    $stranger = SocialAccount::factory()->create(['platform' => Platform::Instagram]);

    $this->actingAs($this->user)
        ->post(route('app.repurposes.store'), ['source_social_account_id' => $stranger->id])
        ->assertSessionHasErrors('source_social_account_id');

    expect(Repurpose::count())->toBe(0);
});

test('an account from another workspace cannot become a destination', function () {
    $repurpose = Repurpose::factory()->create([
        'workspace_id' => $this->workspace->id,
        'source_social_account_id' => $this->source->id,
    ]);

    $stranger = SocialAccount::factory()->create(['platform' => Platform::TikTok]);

    $this->actingAs($this->user)
        ->put(route('app.repurposes.update', $repurpose), [
            'destinations' => [[
                'social_account_id' => $stranger->id,
                'content_type' => ContentType::TikTokVideo->value,
            ]],
        ])
        ->assertSessionHasErrors('destinations.0.social_account_id');

    expect($repurpose->fresh()->destinations)->toBe([]);
});

test('a switched-off account is accepted as a destination and skipped at publish time', function () {
    $repurpose = Repurpose::factory()->create([
        'workspace_id' => $this->workspace->id,
        'source_social_account_id' => $this->source->id,
    ]);

    $this->tiktok->update(['is_active' => false]);

    // This used to be rejected. Switching an account off means "don't post
    // here", not "this repurpose is invalid" — ProcessRepurposeItem skips such
    // a destination, and activation still demands one usable destination.
    $this->actingAs($this->user)
        ->put(route('app.repurposes.update', $repurpose), [
            'destinations' => [destinationPayload($this->tiktok)],
        ])
        ->assertSessionHasNoErrors();

    expect($repurpose->fresh()->destinations)->toHaveCount(1);
});

test('the source account token never reaches the page', function () {
    $this->source->update(['meta' => ['user_token' => 'EAAG-secret-token', 'page_id' => '1']]);

    $repurpose = Repurpose::factory()->create([
        'workspace_id' => $this->workspace->id,
        'source_social_account_id' => $this->source->id,
    ]);

    foreach ([route('app.repurposes.index'), route('app.repurposes.show', $repurpose)] as $url) {
        $this->actingAs($this->user)->get($url)->assertOk()->assertDontSee('EAAG-secret-token');
    }
});

test('an account from another workspace cannot be set as the source on update', function () {
    $repurpose = Repurpose::factory()->create([
        'workspace_id' => $this->workspace->id,
        'source_social_account_id' => $this->source->id,
    ]);

    $stranger = SocialAccount::factory()->create(['platform' => Platform::Instagram]);

    $this->actingAs($this->user)
        ->put(route('app.repurposes.update', $repurpose), ['source_social_account_id' => $stranger->id])
        ->assertSessionHasErrors('source_social_account_id');

    expect($repurpose->fresh()->source_social_account_id)->toBe($this->source->id);
});

test('destination meta errors read as friendly names, not raw array paths', function () {
    $pinterest = SocialAccount::factory()->for($this->workspace)->create(['platform' => Platform::Pinterest]);

    $repurpose = Repurpose::factory()->create([
        'workspace_id' => $this->workspace->id,
        'source_social_account_id' => $this->source->id,
    ]);

    $this->actingAs($this->user)
        ->from(route('app.repurposes.show', $repurpose))
        ->put(route('app.repurposes.update', $repurpose), [
            'source_social_account_id' => $this->source->id,
            'destinations' => [[
                'social_account_id' => $pinterest->id,
                'content_type' => ContentType::PinterestPin->value,
                'meta' => ['board_id' => 'board-1', 'title' => str_repeat('a', 101), 'link' => 'not-a-url'],
            ]],
        ])
        ->assertSessionHasErrors([
            'destinations.0.meta.title' => __('posts.form.pinterest.title_max'),
            'destinations.0.meta.link' => __('posts.form.pinterest.link_invalid'),
        ]);
});

test('the destination settings props load once and stay out of scroll pages', function () {
    Http::fake(['*' => Http::response(['data' => []])]);

    $repurpose = Repurpose::factory()->create([
        'workspace_id' => $this->workspace->id,
        'source_social_account_id' => $this->source->id,
    ]);

    $this->actingAs($this->user)
        ->get(route('app.repurposes.show', $repurpose))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('platformConfigs')
            ->has('pinterestBoards')
            ->has('tiktokCreatorInfos'));

    $partial = $this->actingAs($this->user)
        ->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => Inertia::getVersion(),
            'X-Inertia-Partial-Component' => 'repurposes/Show',
            'X-Inertia-Partial-Data' => 'items',
        ])
        ->get(route('app.repurposes.show', $repurpose))
        ->assertOk();

    expect($partial->json('props'))->toHaveKey('items')
        ->not->toHaveKey('platformConfigs')
        ->not->toHaveKey('pinterestBoards')
        ->not->toHaveKey('tiktokCreatorInfos');
});

test('a destination whose account was switched off is kept, not rejected', function () {
    $destination = destinationPayload($this->tiktok);

    $repurpose = Repurpose::factory()->create([
        'workspace_id' => $this->workspace->id,
        'source_social_account_id' => $this->source->id,
        'destinations' => [$destination],
    ]);

    $this->tiktok->update(['is_active' => false]);

    // Switching an account off means "don't post here", which the job already
    // honours by skipping it. Rejecting the payload instead would stop the user
    // saving any edit at all, because the editor round-trips the whole list.
    $this->actingAs($this->user)
        ->put(route('app.repurposes.update', $repurpose), [
            'source_social_account_id' => $this->source->id,
            'destinations' => [$destination],
        ])
        ->assertSessionHasNoErrors();

    expect($repurpose->fresh()->destinations)->toHaveCount(1);

    $this->actingAs($this->user)
        ->put(route('app.repurposes.update', $repurpose), [
            'source_social_account_id' => $this->source->id,
            'destinations' => [],
        ])
        ->assertSessionHasNoErrors();

    expect($repurpose->fresh()->destinations)->toBe([]);
});

test('the index scrolls instead of loading every repurpose at once', function () {
    config()->set('app.pagination.default', 1);

    $second = SocialAccount::factory()->for($this->workspace)->create(['platform' => Platform::Facebook]);

    Repurpose::factory()->create([
        'workspace_id' => $this->workspace->id,
        'source_social_account_id' => $this->source->id,
        'created_at' => now()->subHour(),
    ]);

    Repurpose::factory()->create([
        'workspace_id' => $this->workspace->id,
        'source_social_account_id' => $second->id,
        'created_at' => now(),
    ]);

    $this->actingAs($this->user)
        ->get(route('app.repurposes.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('repurposes.data', 1)
            ->where('repurposes.meta.per_page', 1)
            ->where('repurposes.meta.total', 2));
});

test('every per-platform meta key the web surface accepts is stored, not stripped', function () {
    $discord = SocialAccount::factory()->for($this->workspace)->create(['platform' => Platform::Discord]);

    $repurpose = Repurpose::factory()->create([
        'workspace_id' => $this->workspace->id,
        'source_social_account_id' => $this->source->id,
    ]);

    $meta = [
        'channel_id' => '9001',
        'channel_name' => 'reels',
        'embeds' => [['title' => 'Watch', 'url' => 'https://trypost.it', 'color' => '#ff8800']],
    ];

    $this->actingAs($this->user)
        ->put(route('app.repurposes.update', $repurpose), [
            'source_social_account_id' => $this->source->id,
            'destinations' => [[
                'social_account_id' => $discord->id,
                'content_type' => ContentType::DiscordMessage->value,
                'meta' => $meta,
            ]],
        ])
        ->assertSessionHasNoErrors();

    expect(data_get($repurpose->fresh()->destinations, '0.meta'))->toEqual($meta);
});

test('each destination is told which content type to start on', function () {
    $youtube = SocialAccount::factory()->for($this->workspace)->create(['platform' => Platform::YouTube]);

    $repurpose = Repurpose::factory()->create([
        'workspace_id' => $this->workspace->id,
        'source_social_account_id' => $this->source->id,
        'source_format' => SourceFormat::Reel,
    ]);

    $this->actingAs($this->user)
        ->get(route('app.repurposes.show', $repurpose))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where("recommendedFormats.{$this->tiktok->id}", ContentType::TikTokVideo->value)
            ->where("recommendedFormats.{$youtube->id}", ContentType::YouTubeShort->value));
});

test('the publishing mode is offered on the page and saved', function () {
    $repurpose = Repurpose::factory()->create([
        'workspace_id' => $this->workspace->id,
        'source_social_account_id' => $this->source->id,
    ]);

    $this->actingAs($this->user)
        ->get(route('app.repurposes.show', $repurpose))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('publishModes', 2)
            ->where('publishModes.0.value', PublishMode::Publish->value)
            ->where('repurpose.publish_mode', PublishMode::Publish->value));

    $this->actingAs($this->user)
        ->put(route('app.repurposes.update', $repurpose), [
            'source_social_account_id' => $this->source->id,
            'publish_mode' => PublishMode::Draft->value,
            'destinations' => [destinationPayload($this->tiktok)],
        ])
        ->assertSessionHasNoErrors();

    expect($repurpose->fresh()->publish_mode)->toBe(PublishMode::Draft);
});

test('the source account can be changed from the edit page', function () {
    config()->set('trypost.allow_multiple_social_accounts', true);

    $other = SocialAccount::factory()->for($this->workspace)->create(['platform' => Platform::Facebook]);

    $repurpose = Repurpose::factory()->create([
        'workspace_id' => $this->workspace->id,
        'source_social_account_id' => $this->source->id,
        'activated_at' => now()->subDays(3),
        'status' => Status::Paused,
    ]);

    $this->actingAs($this->user)
        ->get(route('app.repurposes.show', $repurpose))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('sourceAccounts', 2));

    $this->actingAs($this->user)
        ->put(route('app.repurposes.update', $repurpose), [
            'source_social_account_id' => $other->id,
            'destinations' => [destinationPayload($this->tiktok)],
        ])
        ->assertSessionHasNoErrors();

    $fresh = $repurpose->fresh();

    expect($fresh->source_social_account_id)->toBe($other->id)
        ->and($fresh->activated_at->isToday())->toBeTrue();
});

test('the edit page does not offer an account we cannot download from as a source', function () {
    $repurpose = Repurpose::factory()->create([
        'workspace_id' => $this->workspace->id,
        'source_social_account_id' => $this->source->id,
    ]);

    $this->actingAs($this->user)
        ->get(route('app.repurposes.show', $repurpose))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('sourceAccounts', 1)
            ->where('sourceAccounts.0.id', $this->source->id));
});

test('every connected account is sent so the page can exclude whichever becomes the source', function () {
    $repurpose = Repurpose::factory()->create([
        'workspace_id' => $this->workspace->id,
        'source_social_account_id' => $this->source->id,
    ]);

    $this->actingAs($this->user)
        ->get(route('app.repurposes.show', $repurpose))
        ->assertInertia(function (AssertableInertia $page) {
            $ids = collect($page->toArray()['props']['destinationAccounts'])->pluck('id');

            expect($ids)->toContain($this->source->id)
                ->and($ids)->toContain($this->tiktok->id);
        });
});

test('activity is ordered by when the original was posted, which is what it shows', function () {
    $repurpose = Repurpose::factory()->create([
        'workspace_id' => $this->workspace->id,
        'source_social_account_id' => $this->source->id,
    ]);

    $older = RepurposeItem::factory()->for($repurpose)->create([
        'source_created_at' => now()->subDays(2),
        'created_at' => now(),
    ]);

    $newer = RepurposeItem::factory()->for($repurpose)->create([
        'source_created_at' => now()->subHour(),
        'created_at' => now()->subDay(),
    ]);

    $this->actingAs($this->user)
        ->get(route('app.repurposes.show', $repurpose))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('items.data.0.id', $newer->id)
            ->where('items.data.1.id', $older->id));
});

test('the activity item carries both the moment we acted and the original date', function () {
    $repurpose = Repurpose::factory()->create([
        'workspace_id' => $this->workspace->id,
        'source_social_account_id' => $this->source->id,
    ]);

    $item = RepurposeItem::factory()->for($repurpose)->create([
        'source_created_at' => now()->subDays(5),
        'created_at' => now()->subMinutes(20),
    ]);

    $this->actingAs($this->user)
        ->get(route('app.repurposes.show', $repurpose))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('items.data.0.id', $item->id)
            ->where('items.data.0.created_at', $item->created_at->toIso8601String())
            ->where('items.data.0.source_created_at', $item->source_created_at->toIso8601String()));
});

test('the activity list exposes each replicated post status', function () {
    $repurpose = Repurpose::factory()->create([
        'workspace_id' => $this->workspace->id,
        'source_social_account_id' => $this->source->id,
        'destinations' => [destinationPayload($this->tiktok)],
    ]);

    $item = RepurposeItem::factory()->for($repurpose)->create();

    $published = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'repurpose_item_id' => $item->id,
    ]);
    PostPlatform::factory()->for($published)->create([
        'platform' => Platform::Mastodon,
        'enabled' => true,
        'status' => PostPlatformStatus::Published,
    ]);

    $failed = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'repurpose_item_id' => $item->id,
    ]);
    PostPlatform::factory()->for($failed)->create([
        'platform' => Platform::Threads,
        'enabled' => true,
        'status' => PostPlatformStatus::Failed,
    ]);

    $this->actingAs($this->user)
        ->get(route('app.repurposes.show', $repurpose))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('items.data.0.posts', 2)
            ->where('items.data.0.posts.0.platforms.0.status', PostPlatformStatus::Published->value)
            ->where('items.data.0.posts.1.platforms.0.status', PostPlatformStatus::Failed->value));
});

test('a switched-off destination is still sent to the page so editing cannot drop it', function () {
    $repurpose = Repurpose::factory()->create([
        'workspace_id' => $this->workspace->id,
        'source_social_account_id' => $this->source->id,
        'destinations' => [destinationPayload($this->tiktok)],
    ]);

    $this->tiktok->update(['is_active' => false]);

    // The editor round-trips whatever it was given. An account missing from
    // destinationAccounts is filtered out of the form, so the next save would
    // erase a destination the user only paused.
    $this->actingAs($this->user)
        ->get(route('app.repurposes.show', $repurpose))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('destinationAccounts', 2)
            ->where('repurpose.destinations.0.social_account_id', $this->tiktok->id));
});
