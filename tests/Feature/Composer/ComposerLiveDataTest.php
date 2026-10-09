<?php

declare(strict_types=1);

use App\Actions\Post\Queue\ListTakenSlots;
use App\Enums\SocialAccount\Platform;
use App\Http\Resources\App\HandleInertiaRequests\ComposerResource;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Social\Concerns\HasSocialHttpClient;
use App\Support\PostingSchedule;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['account_id' => $this->user->account_id, 'user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);

    $this->pinterest = SocialAccount::factory()->pinterest()->create([
        'workspace_id' => $this->workspace->id,
        'access_token' => 'pinterest-token',
        'token_expires_at' => now()->addDays(20),
    ]);
    $this->tiktok = SocialAccount::factory()->tiktok()->create([
        'workspace_id' => $this->workspace->id,
        'token_expires_at' => now()->addDays(20),
    ]);

    $this->pinterestApi = config('trypost.platforms.pinterest.api');
    $this->tiktokApi = config('trypost.platforms.tiktok.api');
});

function fakeComposerNetworks(mixed $test, array $pinterestResponses = [], array $tiktokResponses = []): void
{
    $board = ['id' => '42', 'name' => 'Recipes', 'media' => ['image_cover_url' => null]];
    $creator = ['data' => [
        'creator_nickname' => 'Paulo',
        'creator_username' => 'paulo',
        'creator_avatar_url' => null,
        'privacy_level_options' => ['PUBLIC_TO_EVERYONE'],
        'comment_disabled' => false,
        'duet_disabled' => false,
        'stitch_disabled' => false,
        'max_video_post_duration_sec' => 600,
    ]];

    Http::fake([
        "{$test->pinterestApi}/boards*" => $pinterestResponses === []
            ? Http::response(['items' => [$board], 'bookmark' => null])
            : Http::sequence($pinterestResponses),
        "{$test->tiktokApi}/post/publish/creator_info/query/" => $tiktokResponses === []
            ? Http::response($creator)
            : Http::sequence($tiktokResponses),
    ]);
}

function pinterestBoardReads(mixed $test): int
{
    return Http::recorded(fn (Request $request): bool => $request->method() === 'GET'
        && str_starts_with($request->url(), "{$test->pinterestApi}/boards"))->count();
}

test('each pinterest and tiktok channel reads its own boards or creator info', function () {
    fakeComposerNetworks($this);
    $this->actingAs($this->user);

    $this->getJson(route('app.posts.composer.account', $this->pinterest))
        ->assertOk()
        ->assertExactJson(['pinterestBoards' => [
            'boards' => [['id' => '42', 'name' => 'Recipes', 'cover_url' => null]],
            'truncated' => false,
        ]]);

    $this->getJson(route('app.posts.composer.account', $this->tiktok))
        ->assertOk()
        ->assertExactJson(['tiktokCreatorInfo' => [
            'creator_nickname' => 'Paulo',
            'creator_username' => 'paulo',
            'creator_avatar_url' => null,
            'privacy_level_options' => ['PUBLIC_TO_EVERYONE'],
            'comment_disabled' => false,
            'duet_disabled' => false,
            'stitch_disabled' => false,
            'max_video_post_duration_sec' => 600,
        ]]);
});

test('the composer open itself never waits on pinterest or tiktok', function () {
    fakeComposerNetworks($this);

    $this->actingAs($this->user)
        ->getJson(route('app.posts.composer.live', ['key' => ComposerResource::key($this->workspace)]))
        ->assertOk()
        ->assertExactJson([]);

    Http::assertNothingSent();
});

test('a channel timing out answers empty without holding the other channels', function () {
    Http::fake([
        "{$this->pinterestApi}/boards*" => Http::response(['items' => [['id' => '42', 'name' => 'Recipes']], 'bookmark' => null]),
        "{$this->tiktokApi}/post/publish/creator_info/query/" => fn () => throw new ConnectionException('cURL error 28: Operation timed out'),
    ]);
    $this->actingAs($this->user);

    $this->getJson(route('app.posts.composer.account', $this->tiktok))
        ->assertOk()
        ->assertExactJson(['tiktokCreatorInfo' => null]);

    $this->getJson(route('app.posts.composer.account', $this->pinterest))
        ->assertOk()
        ->assertJsonPath('pinterestBoards.boards.0.id', '42');
});

test('a rate-limited network is asked once, not retried', function () {
    fakeComposerNetworks($this, [Http::response(['message' => 'slow down'], 429)], [Http::response(['error' => ['code' => 'rate_limit_exceeded']], 429)]);
    $this->actingAs($this->user);

    $this->getJson(route('app.posts.composer.account', $this->pinterest))->assertOk()->assertJsonPath('pinterestBoards.boards', []);
    $this->getJson(route('app.posts.composer.account', $this->tiktok))->assertOk();

    expect(pinterestBoardReads($this))->toBe(1)
        ->and(Http::recorded(fn (Request $request): bool => str_starts_with($request->url(), "{$this->tiktokApi}/post/publish/creator_info"))->count())->toBe(1);
});

test('interactive reads give up after a few seconds while publishing keeps its patience', function () {
    $client = new class
    {
        use HasSocialHttpClient;

        public function client(): PendingRequest
        {
            return $this->socialHttp();
        }
    };

    expect($client->interactive()->client()->getOptions())->toMatchArray(['connect_timeout' => 3, 'timeout' => 5])
        ->and($client->client()->getOptions()['timeout'])->toBe(120);
});

test('only pinterest and tiktok channels of the current workspace have account data', function () {
    $linkedin = SocialAccount::factory()->linkedin()->create(['workspace_id' => $this->workspace->id]);
    $foreign = SocialAccount::factory()->pinterest()->create();
    $this->actingAs($this->user);

    $this->getJson(route('app.posts.composer.account', $linkedin))->assertNotFound();
    $this->getJson(route('app.posts.composer.account', $foreign))->assertForbidden();
    $this->getJson(route('app.posts.composer.taken-slots', ['account' => $foreign, 'from' => now()->toIso8601String(), 'to' => now()->addDay()->toIso8601String()]))->assertForbidden();
});

test('taken slots cover only the asked day and never the past', function () {
    $from = now()->addDays(3)->startOfDay();
    $at = fn (CarbonInterface $instant): Post => Post::factory()->forAccount($this->pinterest)->scheduled()->create(['user_id' => $this->user->id, 'scheduled_at' => $instant]);
    $at($from->copy()->subMinute());
    $first = $at($from->copy());
    $last = $at($from->copy()->addDay()->subMinute());
    $at($from->copy()->addDay());
    $this->actingAs($this->user);

    $this->getJson(route('app.posts.composer.taken-slots', ['account' => $this->pinterest, 'from' => $from->toIso8601String(), 'to' => $from->copy()->addDay()->toIso8601String()]))
        ->assertOk()
        ->assertExactJson(['takenSlots' => [$first->scheduled_at->toIso8601ZuluString(), $last->scheduled_at->toIso8601ZuluString()]]);

    $this->getJson(route('app.posts.composer.taken-slots', ['account' => $this->pinterest, 'from' => now()->subDays(5)->toIso8601String(), 'to' => now()->subDays(4)->toIso8601String()]))
        ->assertOk()
        ->assertExactJson(['takenSlots' => []]);
});

test('taken slots are asked for one day at a time', function () {
    $this->actingAs($this->user)
        ->getJson(route('app.posts.composer.taken-slots', ['account' => $this->pinterest, 'from' => now()->toIso8601String(), 'to' => now()->addDays(3)->toIso8601String()]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('to');
});

test('guests cannot read the live data', function () {
    $this->getJson(route('app.posts.composer.live'))->assertUnauthorized();
});

test('pinterest boards are read once per five minutes', function () {
    fakeComposerNetworks($this);
    $this->actingAs($this->user);

    $this->getJson(route('app.posts.composer.account', $this->pinterest))->assertOk();
    $this->getJson(route('app.posts.composer.account', $this->pinterest))->assertOk();

    expect(pinterestBoardReads($this))->toBe(1);

    $this->travel(6)->minutes();
    $this->getJson(route('app.posts.composer.account', $this->pinterest))->assertOk();

    expect(pinterestBoardReads($this))->toBe(2);
});

test('the board picker refresh bypasses the cache and refreshes it', function () {
    fakeComposerNetworks($this);
    $this->actingAs($this->user);

    $this->getJson(route('app.posts.composer.account', $this->pinterest))->assertOk();
    $this->getJson(route('app.pinterest.boards.index', $this->pinterest))->assertOk();
    $this->getJson(route('app.posts.composer.account', $this->pinterest))->assertOk();

    expect(pinterestBoardReads($this))->toBe(2);
});

test('creating a board, reconnecting or disconnecting forgets the cached boards', function (Closure $change) {
    Http::fake(["{$this->pinterestApi}/boards" => Http::response(['id' => '7', 'name' => 'New', 'media' => ['image_cover_url' => null]], 201)]);
    fakeComposerNetworks($this);
    $this->actingAs($this->user);

    $this->getJson(route('app.posts.composer.account', $this->pinterest))->assertOk();
    $change($this);
    $this->getJson(route('app.posts.composer.account', $this->pinterest))->assertOk();

    expect(pinterestBoardReads($this))->toBe(2);
})->with([
    'board created' => [fn (mixed $test) => $test->postJson(route('app.pinterest.boards.store', $test->pinterest), ['name' => 'New'])->assertCreated()],
    'reconnected' => [fn (mixed $test) => $test->pinterest->update(['access_token' => 'new-token'])],
    'disconnected and connected again' => [function (mixed $test) {
        $id = $test->pinterest->id;
        $test->pinterest->delete();
        $test->pinterest = SocialAccount::factory()->pinterest()->create([
            'id' => $id,
            'workspace_id' => $test->workspace->id,
            'token_expires_at' => now()->addDays(20),
        ]);
    }],
]);

test('failed reads are not cached', function () {
    fakeComposerNetworks($this, [
        Http::response(['message' => 'down'], 500),
        Http::response(['items' => [['id' => '42', 'name' => 'Recipes']], 'bookmark' => null]),
    ], [
        Http::response(['error' => ['code' => 'internal_error']], 500),
        Http::response(['data' => ['creator_nickname' => 'Paulo', 'privacy_level_options' => []]]),
    ]);
    $this->actingAs($this->user);

    $this->getJson(route('app.posts.composer.account', $this->pinterest))->assertOk()->assertJsonPath('pinterestBoards.boards', []);
    $this->getJson(route('app.posts.composer.account', $this->tiktok))->assertOk()->assertJsonPath('tiktokCreatorInfo.creator_nickname', null);

    $this->getJson(route('app.posts.composer.account', $this->pinterest))->assertOk()->assertJsonPath('pinterestBoards.boards.0.id', '42');
    $this->getJson(route('app.posts.composer.account', $this->tiktok))->assertOk()->assertJsonPath('tiktokCreatorInfo.creator_nickname', 'Paulo');
});

test('taken slots are read in one query whatever the number of channels', function () {
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $count = function (int $channels) use (&$queries): int {
        $ids = SocialAccount::factory()->count($channels)->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::X])
            ->each(function (SocialAccount $account): void {
                Post::factory()->forAccount($account)->scheduled()->create(['user_id' => $this->user->id, 'scheduled_at' => now()->addDay()]);
            })
            ->modelKeys();

        $queries = 0;
        $taken = ListTakenSlots::handle($ids, now(), now()->addWeek());

        expect(collect($taken)->flatten())->toHaveCount($channels);

        return $queries;
    };

    expect($count(1))->toBe(1);
    expect($count(6))->toBe(1);
});

test('the live data leaves the composer bundle out while the client key is current', function () {
    fakeComposerNetworks($this);

    $this->actingAs($this->user)
        ->getJson(route('app.posts.composer.live', ['key' => ComposerResource::key($this->workspace)]))
        ->assertOk()
        ->assertJsonMissingPath('composer');
});

test('the live data carries the fresh bundle when the user changed it without a page visit', function (Closure $change) {
    fakeComposerNetworks($this);
    $staleKey = ComposerResource::key($this->workspace);

    $change($this);

    $response = $this->actingAs($this->user)
        ->getJson(route('app.posts.composer.live', ['key' => $staleKey]))
        ->assertOk();

    expect($response->json('composer'))->toEqual(json_decode(json_encode(ComposerResource::make($this->workspace->fresh())), true));
})->with([
    'channel time zone saved from the settings page' => [fn (mixed $test) => $test->actingAs($test->user)->putJson(route('app.channels.posting-schedule.update', $test->pinterest), [
        'timezone' => 'Asia/Tokyo',
        'posting_goal' => 1,
        'posting_schedule' => PostingSchedule::empty()->withTime(1, '09:00')->toArray(),
    ])->assertOk()],
    'signature created in the composer' => [fn (mixed $test) => $test->actingAs($test->user)->postJson(route('app.signatures.store'), ['name' => 'Thanks', 'content' => 'Thanks!'])->assertCreated()],
]);

test('a fresh bundle reflects the new channel time zone', function () {
    fakeComposerNetworks($this);
    $staleKey = ComposerResource::key($this->workspace);
    $this->pinterest->update(['timezone' => 'Asia/Tokyo']);

    $this->actingAs($this->user)
        ->getJson(route('app.posts.composer.live', ['key' => $staleKey]))
        ->assertOk()
        ->assertJsonPath("composer.accounts.{$this->pinterest->id}.timezone", 'Asia/Tokyo')
        ->assertJsonPath('composerKey', ComposerResource::key($this->workspace));
});
