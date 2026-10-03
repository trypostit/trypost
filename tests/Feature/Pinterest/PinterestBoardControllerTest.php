<?php

declare(strict_types=1);

use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['account_id' => $this->user->account_id, 'user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->user->refresh();

    $this->account = SocialAccount::factory()->pinterest()->create([
        'workspace_id' => $this->workspace->id,
        'access_token' => 'pinterest-token',
        'token_expires_at' => now()->addDays(20),
    ]);

    $this->api = config('trypost.platforms.pinterest.api');
});

test('creates a board through the pinterest api and returns it', function () {
    Http::fake([
        "{$this->api}/boards" => Http::response([
            'id' => '549755885175',
            'name' => 'Summer recipes',
            'privacy' => 'PUBLIC',
            'media' => ['image_cover_url' => null],
        ], 201),
    ]);

    $this->actingAs($this->user)
        ->postJson(route('app.pinterest.boards.store', $this->account), ['name' => '  Summer recipes  '])
        ->assertCreated()
        ->assertExactJson([
            'id' => '549755885175',
            'name' => 'Summer recipes',
            'cover_url' => null,
        ]);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === "{$this->api}/boards"
        && $request->hasHeader('Authorization', 'Bearer pinterest-token')
        && $request->data() === ['name' => 'Summer recipes']);
});

test('validates the board name', function (mixed $name) {
    Http::fake();

    $this->actingAs($this->user)
        ->postJson(route('app.pinterest.boards.store', $this->account), ['name' => $name])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('name');

    Http::assertNothingSent();
})->with([
    'missing' => [null],
    'blank' => ['   '],
    'too long' => [str_repeat('a', 51)],
    'not a string' => [['a']],
]);

test('asks to reconnect when pinterest refuses the board write', function (int $status) {
    Http::fake([
        "{$this->api}/boards" => Http::response(['code' => 3, 'message' => 'Not authorized to access the board.'], $status),
    ]);

    $this->actingAs($this->user)
        ->postJson(route('app.pinterest.boards.store', $this->account), ['name' => 'Ideas'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name' => __('posts.form.pinterest.boards_reconnect')]);
})->with([403]);

test('asks to reconnect when the pinterest token is dead', function () {
    Http::fake([
        "{$this->api}/boards" => Http::response(['code' => 2, 'message' => 'Authentication failed.'], 401),
    ]);

    $this->actingAs($this->user)
        ->postJson(route('app.pinterest.boards.store', $this->account), ['name' => 'Ideas'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name' => __('posts.form.pinterest.boards_reconnect')]);
});

test('reports a pinterest rate limit and other failures', function (int $status, string $key) {
    Http::fake([
        "{$this->api}/boards" => Http::response(['code' => 8, 'message' => 'Failed'], $status),
    ]);

    $this->actingAs($this->user)
        ->postJson(route('app.pinterest.boards.store', $this->account), ['name' => 'Ideas'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name' => __($key)]);
})->with([
    'rate limited' => [429, 'posts.form.pinterest.boards_rate_limited'],
    'server error' => [500, 'posts.form.pinterest.boards_failed'],
    'bad request' => [400, 'posts.form.pinterest.boards_failed'],
]);

test('hides an account from another workspace', function () {
    $foreign = SocialAccount::factory()->pinterest()->create();
    Http::fake();

    $this->actingAs($this->user)
        ->postJson(route('app.pinterest.boards.store', $foreign), ['name' => 'Ideas'])
        ->assertNotFound();

    $this->actingAs($this->user)
        ->getJson(route('app.pinterest.boards.index', $foreign))
        ->assertNotFound();

    Http::assertNothingSent();
});

test('rejects a social account that is not pinterest', function () {
    Http::fake();
    $discord = SocialAccount::factory()->discord()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->user)
        ->postJson(route('app.pinterest.boards.store', $discord), ['name' => 'Ideas'])
        ->assertNotFound();

    Http::assertNothingSent();
});

test('forbids a user outside the workspace from managing boards', function () {
    Http::fake();
    $outsider = workspaceOutsider($this->workspace);

    $this->actingAs($outsider)
        ->postJson(route('app.pinterest.boards.store', $this->account), ['name' => 'Ideas'])
        ->assertForbidden();

    $this->actingAs($outsider)
        ->getJson(route('app.pinterest.boards.index', $this->account))
        ->assertForbidden();

    Http::assertNothingSent();
});

test('a member can create boards', function () {
    Http::fake([
        "{$this->api}/boards" => Http::response(['id' => '1', 'name' => 'Ideas'], 201),
    ]);
    $member = User::factory()->create(['account_id' => $this->user->account_id, 'current_workspace_id' => $this->workspace->id]);
    $this->workspace->members()->attach($member->id, membershipPivot('member'));

    $this->actingAs($member)
        ->postJson(route('app.pinterest.boards.store', $this->account), ['name' => 'Ideas'])
        ->assertCreated()
        ->assertJsonPath('id', '1');
});

test('refreshes the board list with covers', function () {
    Http::fake([
        "{$this->api}/boards*" => Http::response([
            'items' => [
                ['id' => 'board_1', 'name' => 'Disney', 'media' => ['image_cover_url' => 'https://i.pinimg.com/400x300/cover.jpg']],
                ['id' => 'board_2', 'name' => 'Social'],
            ],
        ], 200),
    ]);

    $this->actingAs($this->user)
        ->getJson(route('app.pinterest.boards.index', $this->account))
        ->assertOk()
        ->assertExactJson([
            'boards' => [
                ['id' => 'board_1', 'name' => 'Disney', 'cover_url' => 'https://i.pinimg.com/400x300/cover.jpg'],
                ['id' => 'board_2', 'name' => 'Social', 'cover_url' => null],
            ],
            'truncated' => false,
        ]);
});

test('reports a failed refresh on the boards field', function () {
    Http::fake([
        "{$this->api}/boards*" => Http::response(['code' => 8, 'message' => 'Failed'], 503),
    ]);

    $this->actingAs($this->user)
        ->getJson(route('app.pinterest.boards.index', $this->account))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['boards' => __('posts.form.pinterest.boards_failed')]);
});
