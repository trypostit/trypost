<?php

declare(strict_types=1);

use App\Models\PostTemplate;
use App\Models\User;
use App\Models\Workspace;
use App\Support\TemplateLibrary;
use Inertia\Testing\AssertableInertia as Assert;

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

test('discover is the default view with the featured band and capped rows', function () {
    PostTemplate::factory()->team()->count(2)->create(['workspace_id' => $this->workspace->id]);
    PostTemplate::factory()->personal($this->alice)->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->alice)->get(route('app.create.templates.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('create/Templates')
            ->where('view', 'discover')
            ->where('counts', ['discover' => 45, 'team' => 2, 'personal' => 1])
            ->has('library.featured', 3)
            ->has('library.rows', 8)
            ->where('library.rows.0.type', 'tip')
            ->where('library.rows.0.total', 12)
            ->has('library.rows.0.templates', 10)
            ->where('library.rows.2.type', 'story')
            ->where('library.rows.2.total', 17)
            ->has('library.rows.2.templates', 10)
            ->missing('modal')
            ->missing('templates'));
});

test('filters are AND across facets and garbage values are ignored', function () {
    $this->actingAs($this->alice)->get(route('app.create.templates.index', ['types' => ['tip'], 'goals' => ['education']]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('library.results', fn ($results) => collect($results)->pluck('key')->all() === [
                'quick_win', 'mistake_to_avoid', 'faq_answer', 'beginner_guide', 'shortcut', 'common_setting',
            ])
            ->where('filters.types', ['tip'])
            ->where('filters.goals', ['education']));

    $this->actingAs($this->alice)->get(route('app.create.templates.index', ['types' => ['nonsense'], 'view' => 'garbage']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('view', 'discover')
            ->where('filters.types', [])
            ->has('library.rows', 8));
});

test('team view paginates newest first and search narrows it', function () {
    config(['app.pagination.default' => 2]);

    $old = PostTemplate::factory()->team()->create(['workspace_id' => $this->workspace->id, 'title' => 'Old', 'created_at' => now()->subDays(3)]);
    $launch = PostTemplate::factory()->team()->create(['workspace_id' => $this->workspace->id, 'title' => 'Product Launch', 'created_at' => now()->subDay()]);
    $new = PostTemplate::factory()->team()->create(['workspace_id' => $this->workspace->id, 'title' => 'New', 'created_at' => now()]);

    $this->actingAs($this->alice)->get(route('app.create.templates.index', ['view' => 'team']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('view', 'team')
            ->missing('library')
            ->has('templates.data', 2)
            ->where('templates.data.0.id', $new->id)
            ->where('templates.data.1.id', $launch->id));

    $this->actingAs($this->alice)->get(route('app.create.templates.index', ['view' => 'team', 'search' => 'LAUNCH']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('templates.data', 1)
            ->where('templates.data.0.id', $launch->id)
            ->where('filters.search', 'LAUNCH'));

    expect($old->exists)->toBeTrue();
});

test('the user template search matches the title and description but not the body', function () {
    $byTitle = PostTemplate::factory()->team()->create(['workspace_id' => $this->workspace->id, 'title' => 'Zebra tips', 'description' => 'x', 'body' => 'x']);
    $byDescription = PostTemplate::factory()->team()->create(['workspace_id' => $this->workspace->id, 'title' => 'One', 'description' => 'About zebra', 'body' => 'x']);
    PostTemplate::factory()->team()->create(['workspace_id' => $this->workspace->id, 'title' => 'Two', 'description' => 'x', 'body' => 'zebra only in the body']);

    $this->actingAs($this->alice)->get(route('app.create.templates.index', ['view' => 'team', 'search' => 'zebra']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('templates.data', 2)
            ->where('templates.data', fn ($data) => collect($data)->pluck('id')->sort()->values()->all() === collect([$byTitle->id, $byDescription->id])->sort()->values()->all()));
});

test('non-array facets and search are ignored instead of redirecting', function () {
    $this->actingAs($this->alice)->get(route('app.create.templates.index', ['types' => 'tip', 'search' => ['x']]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.types', ['tip'])
            ->where('filters.search', null)
            ->has('library.results'));
});

test('the counts are skipped on partial visits that do not ask for them', function () {
    $this->actingAs($this->alice)->get(route('app.create.templates.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('counts')
            ->reloadOnly('filters', fn (Assert $reload) => $reload->missing('counts')));
});

test('a teammate personal template never appears in the list or the counts', function () {
    PostTemplate::factory()->personal($this->alice)->create(['workspace_id' => $this->workspace->id]);
    $bobs = PostTemplate::factory()->personal($this->bob)->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->bob)->get(route('app.create.templates.index', ['view' => 'personal']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('counts.personal', 1)
            ->has('templates.data', 1)
            ->where('templates.data.0.id', $bobs->id));
});

test('the list carries what the detail and editor dialogs need', function () {
    $template = PostTemplate::factory()->personal($this->alice)->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->alice)->get(route('app.create.templates.index', ['view' => 'personal']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('templates.data.0.id', $template->id)
            ->where('templates.data.0.body', $template->body)
            ->where('templates.data.0.can_edit', true)
            ->where('templates.data.0.can_change_visibility', true));

    $team = PostTemplate::factory()->team()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->alice->id]);

    $this->actingAs($this->bob)->get(route('app.create.templates.index', ['view' => 'team']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('templates.data.0.id', $team->id)
            ->where('templates.data.0.can_edit', true)
            ->where('templates.data.0.can_change_visibility', false));
});

test('the library cards carry the resolved strings the detail dialog shows', function () {
    $featured = TemplateLibrary::featured()[0];

    $this->actingAs($this->alice)->get(route('app.create.templates.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('library.featured.0.key', $featured->key)
            ->where('library.featured.0.title', __("template_library.{$featured->key}.title"))
            ->where('library.featured.0.body', __("template_library.{$featured->key}.body")));
});

test('a user outside the workspace cannot open the templates page', function () {
    $outsider = workspaceOutsider($this->workspace);

    $this->actingAs($outsider)->get(route('app.create.templates.index'))->assertForbidden();
});
