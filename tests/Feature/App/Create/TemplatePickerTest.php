<?php

declare(strict_types=1);

use App\Models\PostTemplate;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    config(['trypost.self_hosted' => false]);

    $owner = User::factory()->create();
    $this->workspace = Workspace::factory()->create([
        'account_id' => $owner->account_id,
        'user_id' => $owner->id,
    ]);
    $this->alice = workspaceMember($this->workspace, 'member');
    $this->bob = workspaceMember($this->workspace, 'member');
    subscribeAccount($owner->account);
});

test('the picker returns the library, team and own personal templates', function () {
    $team = PostTemplate::factory()->team()->create(['workspace_id' => $this->workspace->id]);
    $personal = PostTemplate::factory()->personal($this->alice)->create(['workspace_id' => $this->workspace->id]);

    $response = $this->actingAs($this->alice)->getJson(route('app.create.templates.picker'))
        ->assertOk()
        ->assertJsonCount(45, 'library')
        ->assertJsonPath('library.0.key', 'quick_win')
        ->assertJsonPath('library.0.title', __('template_library.quick_win.title'))
        ->assertJsonPath('team.0.id', $team->id)
        ->assertJsonPath('team.0.can_edit', true)
        ->assertJsonPath('personal.0.id', $personal->id)
        ->assertJsonPath('personal.0.can_change_visibility', true)
        ->assertJsonPath('has_more', ['team' => false, 'personal' => false]);

    expect($response->json('library.0'))->toHaveKeys(['key', 'emoji', 'type', 'audiences', 'format', 'goal', 'featured', 'title', 'description', 'body']);
});

test('each scope is capped at the page size and flags has_more', function () {
    config(['app.pagination.default' => 3]);
    PostTemplate::factory()->team()->count(4)->create(['workspace_id' => $this->workspace->id]);
    PostTemplate::factory()->personal($this->alice)->count(3)->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->alice)->getJson(route('app.create.templates.picker'))
        ->assertOk()
        ->assertJsonCount(3, 'team')
        ->assertJsonCount(3, 'personal')
        ->assertJsonPath('has_more', ['team' => true, 'personal' => false]);
});

test('a teammate personal template is never listed', function () {
    PostTemplate::factory()->personal($this->alice)->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->bob)->getJson(route('app.create.templates.picker'))
        ->assertOk()
        ->assertJsonCount(0, 'personal')
        ->assertJsonCount(0, 'team');
});

test('search filters the library, team and personal lists together', function () {
    PostTemplate::factory()->team()->create(['workspace_id' => $this->workspace->id, 'title' => 'Zebra launch']);
    PostTemplate::factory()->team()->create(['workspace_id' => $this->workspace->id, 'title' => 'Other']);
    PostTemplate::factory()->personal($this->alice)->create(['workspace_id' => $this->workspace->id, 'title' => 'My ZEBRA idea']);
    PostTemplate::factory()->personal($this->alice)->create(['workspace_id' => $this->workspace->id, 'title' => 'Nope']);

    $this->actingAs($this->alice)->getJson(route('app.create.templates.picker', ['search' => 'zebra']))
        ->assertOk()
        ->assertJsonCount(0, 'library')
        ->assertJsonCount(1, 'team')
        ->assertJsonCount(1, 'personal');

    $this->actingAs($this->alice)->getJson(route('app.create.templates.picker', ['search' => 'five-minute']))
        ->assertOk()
        ->assertJsonPath('library.0.key', 'quick_win');
});

test('a user outside the workspace is forbidden and a guest is unauthenticated', function () {
    $outsider = workspaceOutsider($this->workspace);

    $this->actingAs($outsider)->getJson(route('app.create.templates.picker'))->assertForbidden();

    auth()->logout();
    $this->getJson(route('app.create.templates.picker'))->assertUnauthorized();
});

test('a search over 100 characters is truncated, not rejected', function () {
    $this->actingAs($this->alice)->getJson(route('app.create.templates.picker', ['search' => str_repeat('a', 150)]))->assertOk();
});

test('query count does not grow with the number of templates', function () {
    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    $count = function () use (&$queries): int {
        $queries = 0;
        $this->actingAs($this->alice)->getJson(route('app.create.templates.picker'))->assertOk();
        $this->actingAs($this->alice)->get(route('app.create.templates.index', ['view' => 'team']))->assertOk();

        return $queries;
    };

    PostTemplate::factory()->team()->count(2)->create(['workspace_id' => $this->workspace->id]);
    PostTemplate::factory()->personal($this->alice)->create(['workspace_id' => $this->workspace->id]);
    $count();
    $few = $count();

    PostTemplate::factory()->team()->count(10)->create(['workspace_id' => $this->workspace->id]);
    PostTemplate::factory()->personal($this->alice)->count(4)->create(['workspace_id' => $this->workspace->id]);

    expect($count())->toBe($few);
});
