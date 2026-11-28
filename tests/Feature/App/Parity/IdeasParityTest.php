<?php

declare(strict_types=1);

use App\Actions\Idea\ListIdeas;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Idea\CreateIdeaStageTool;
use App\Mcp\Tools\Idea\CreateIdeaTool;
use App\Mcp\Tools\Idea\DeleteIdeaStageTool;
use App\Mcp\Tools\Idea\DeleteIdeasTool;
use App\Mcp\Tools\Idea\DuplicateIdeaTool;
use App\Mcp\Tools\Idea\GetIdeaTool;
use App\Mcp\Tools\Idea\ListIdeaStagesTool;
use App\Mcp\Tools\Idea\ListIdeasTool;
use App\Mcp\Tools\Idea\MoveIdeasTool;
use App\Mcp\Tools\Idea\ReorderIdeaStagesTool;
use App\Mcp\Tools\Idea\UpdateIdeaStageTool;
use App\Mcp\Tools\Idea\UpdateIdeaTool;
use App\Models\Idea;
use App\Models\IdeaStage;
use App\Models\Media;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceLabel;
use App\Support\Requests\Idea\IdeaRequestRules;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Testing\Fluent\AssertableJson;

test('the idea routes and tools are registered on the api and mcp', function () {
    $apiRoutes = collect(Route::getRoutes()->getRoutesByName())->keys()->filter(fn (string $name) => str_starts_with($name, 'api.ideas.'))->sort()->values()->all();
    $tools = collect((new ReflectionProperty(TryPostServer::class, 'tools'))->getDefaultValue());

    expect($apiRoutes)->toBe(['api.ideas.bulk-destroy', 'api.ideas.destroy', 'api.ideas.duplicate', 'api.ideas.index', 'api.ideas.move', 'api.ideas.show', 'api.ideas.store', 'api.ideas.update'])
        ->and($tools->contains(ListIdeasTool::class))->toBeTrue()
        ->and($tools->contains(MoveIdeasTool::class))->toBeTrue();
});

test('the api and mcp list ideas with each filter in the same order as the gallery query', function () {
    ['user' => $user, 'workspace' => $workspace, 'token' => $token] = parityContext();
    $stage = IdeaStage::factory()->create(['workspace_id' => $workspace->id]);
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $workspace->id]);
    $inStage = Idea::factory()->create(['workspace_id' => $workspace->id, 'idea_stage_id' => $stage->id, 'created_at' => now()->subDays(3)]);
    $tagged = Idea::factory()->create(['workspace_id' => $workspace->id, 'idea_stage_id' => null, 'created_at' => now()->subDays(2)]);
    $tagged->labels()->attach($label->id);
    $loose = Idea::factory()->create(['workspace_id' => $workspace->id, 'idea_stage_id' => null, 'created_at' => now()->subDay()]);
    Idea::factory()->create(['workspace_id' => Workspace::factory()->create()->id]);

    $cases = [
        'none' => [[], []],
        'stage' => [['stages' => [$stage->id]], ['stages' => [$stage->id]]],
        'unassigned' => [['unassigned' => true], ['unassigned' => true]],
        'labels' => [['labels' => [$label->id]], ['labels' => [$label->id]]],
        'untagged' => [['untagged' => true], ['untagged' => true]],
        'labels and untagged' => [['labels' => [$label->id], 'untagged' => true], ['labels' => [$label->id], 'untagged' => true]],
    ];

    foreach ($cases as [$arguments, $filters]) {
        $expected = ListIdeas::query($workspace, IdeaRequestRules::filterValues($filters))->pluck('id')->all();

        auth()->forgetGuards();
        $api = $this->withHeaders(parityApi($token))->getJson(route('api.ideas.index', $arguments))->assertOk()->json();
        $mcp = null;
        TryPostServer::actingAs($user)->tool(ListIdeasTool::class, $arguments)->assertStructuredContent(function (AssertableJson $json) use (&$mcp) {
            $mcp = $json->toArray();

            return $json->etc();
        });

        expect(array_column($api['data'], 'id'))->toBe($expected)
            ->and(array_column($mcp['ideas'], 'id'))->toBe($expected)
            ->and($api['meta']['per_page'])->toBe((int) config('app.pagination.default'))
            ->and($mcp['per_page'])->toBe($api['meta']['per_page'])
            ->and($mcp['total'])->toBe($api['meta']['total']);
    }

    auth()->forgetGuards();
    $all = $this->withHeaders(parityApi($token))->getJson(route('api.ideas.index'))->json('data.*.id');

    expect($all)->toBe([$loose->id, $tagged->id, $inStage->id]);
});

test('the api and mcp load the labels of a page of ideas in one query', function () {
    ['user' => $user, 'workspace' => $workspace, 'token' => $token] = parityContext();
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $workspace->id]);
    Idea::factory()->count(3)->create(['workspace_id' => $workspace->id])->each(fn (Idea $idea) => $idea->labels()->attach($label->id));

    $labelQueries = fn (array $log): int => count(array_filter($log, fn (array $entry): bool => str_contains($entry['query'], 'workspace_labels')));

    auth()->forgetGuards();
    DB::enableQueryLog();
    $this->withHeaders(parityApi($token))->getJson(route('api.ideas.index'))->assertOk();
    $api = $labelQueries(DB::getQueryLog());
    DB::flushQueryLog();
    TryPostServer::actingAs($user)->tool(ListIdeasTool::class, [])->assertOk();
    $mcp = $labelQueries(DB::getQueryLog());
    DB::disableQueryLog();

    expect($api)->toBe(1)->and($mcp)->toBe(1);
});

test('the api paginates ideas by the configured page size and ignores a requested one', function () {
    ['workspace' => $workspace, 'token' => $token] = parityContext();
    config(['app.pagination.default' => 2]);
    Idea::factory()->count(3)->create(['workspace_id' => $workspace->id]);

    auth()->forgetGuards();
    $response = $this->withHeaders(parityApi($token))->getJson(route('api.ideas.index', ['per_page' => 50]))->assertOk();

    expect($response->json('data'))->toHaveCount(2)
        ->and($response->json('meta.per_page'))->toBe(2)
        ->and($response->json('meta.last_page'))->toBe(2);
});

test('showing an idea returns the same fields on the api and mcp and refuses a foreign idea', function () {
    ['user' => $user, 'workspace' => $workspace, 'token' => $token] = parityContext();
    $stage = IdeaStage::factory()->create(['workspace_id' => $workspace->id]);
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $workspace->id]);
    $idea = Idea::factory()->create(['workspace_id' => $workspace->id, 'idea_stage_id' => $stage->id, 'title' => 'Hello', 'body' => 'World']);
    $idea->labels()->attach($label->id);
    $foreign = Idea::factory()->create(['workspace_id' => Workspace::factory()->create()->id]);

    auth()->forgetGuards();
    $api = $this->withHeaders(parityApi($token))->getJson(route('api.ideas.show', $idea))->assertOk()->json();

    expect($api)->toMatchArray(['id' => $idea->id, 'idea_stage_id' => $stage->id, 'title' => 'Hello', 'body' => 'World', 'media' => []])
        ->and($api['labels'][0]['id'])->toBe($label->id)
        ->and($api)->toHaveKeys(['created_at', 'updated_at']);

    TryPostServer::actingAs($user)->tool(GetIdeaTool::class, ['idea_id' => $idea->id])->assertStructuredContent($api);

    auth()->forgetGuards();
    $this->withHeaders(parityApi($token))->getJson(route('api.ideas.show', $foreign))->assertNotFound();
    TryPostServer::actingAs($user)->tool(GetIdeaTool::class, ['idea_id' => $foreign->id])->assertHasErrors(['Idea not found.']);
    TryPostServer::actingAs($user)->tool(GetIdeaTool::class, ['idea_id' => (string) Str::uuid()])->assertHasErrors(['Idea not found.']);
});

test('creating an idea through the api and mcp stores the same with stage, labels and media', function () {
    Storage::fake();
    ['user' => $user, 'workspace' => $workspace, 'token' => $token] = parityContext();
    $stage = IdeaStage::factory()->create(['workspace_id' => $workspace->id]);
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $workspace->id]);
    $payload = fn () => ['title' => 'Idea', 'body' => 'Text', 'idea_stage_id' => $stage->id, 'label_ids' => [$label->id], 'media_ids' => [Media::factory()->temporaryUpload($workspace)->stored()->create()->id]];

    auth()->forgetGuards();
    $api = $this->withHeaders(parityApi($token))->postJson(route('api.ideas.store'), $payload())->assertCreated()->json();
    $mcp = null;
    TryPostServer::actingAs($user)->tool(CreateIdeaTool::class, $payload())->assertStructuredContent(function (AssertableJson $json) use (&$mcp) {
        $mcp = $json->toArray();

        return $json->etc();
    });
    $this->actingAs($user)->post(route('app.create.ideas.store'), $payload())->assertRedirect();

    $stored = Idea::query()->where('workspace_id', $workspace->id)->with('labels')->orderBy('position')->get();
    $normalize = fn (array $idea) => [data_get($idea, 'title'), data_get($idea, 'body'), data_get($idea, 'idea_stage_id'), count(data_get($idea, 'media')), data_get($idea, 'labels.0.id')];

    expect($stored)->toHaveCount(3)
        ->and($stored->pluck('position')->all())->toBe([0, 1, 2])
        ->and($stored->map(fn (Idea $idea) => [$idea->title, $idea->body, $idea->idea_stage_id, count($idea->media), $idea->labels->pluck('id')->first()])->unique()->values()->all())->toHaveCount(1)
        ->and($normalize($api))->toBe($normalize($mcp))
        ->and($normalize($api))->toBe(['Idea', 'Text', $stage->id, 1, $label->id]);
});

test('creating an idea refuses an empty idea and foreign label, media and stage with the web messages', function () {
    ['user' => $user, 'workspace' => $workspace, 'token' => $token] = parityContext();
    $other = Workspace::factory()->create();
    $foreignLabel = WorkspaceLabel::factory()->create(['workspace_id' => $other->id]);
    $foreignStage = IdeaStage::factory()->create(['workspace_id' => $other->id]);
    $foreignMedia = Media::factory()->temporaryUpload($other)->stored()->create();

    $cases = [
        'empty' => [['title' => ''], 'title'],
        'blank everything' => [['title' => '', 'body' => '', 'media_ids' => []], 'title'],
        'label' => [['title' => 'x', 'label_ids' => [$foreignLabel->id]], 'label_ids.0'],
        'stage' => [['title' => 'x', 'idea_stage_id' => $foreignStage->id], 'idea_stage_id'],
        'media' => [['title' => 'x', 'media_ids' => [$foreignMedia->id]], 'media_ids.0'],
    ];

    foreach ($cases as [$payload, $key]) {
        $web = $this->actingAs($user)->postJson(route('app.create.ideas.store'), $payload)->assertUnprocessable()->json('errors')[$key][0];
        auth()->forgetGuards();
        $api = $this->withHeaders(parityApi($token))->postJson(route('api.ideas.store'), $payload)->assertUnprocessable()->json('errors')[$key][0];
        TryPostServer::actingAs($user)->tool(CreateIdeaTool::class, $payload)->assertHasErrors([$web]);
        expect($api)->toBe($web);
    }

    expect(Idea::query()->where('workspace_id', $workspace->id)->count())->toBe(0)
        ->and(__('create.ideas.errors.empty'))->toBe($this->actingAs($user)->postJson(route('app.create.ideas.store'), ['title' => ''])->json('errors.title.0'));
});

test('updating an idea changes only the sent fields on the api and mcp and refuses blanking everything', function () {
    ['user' => $user, 'workspace' => $workspace, 'token' => $token] = parityContext();
    $stage = IdeaStage::factory()->create(['workspace_id' => $workspace->id]);
    $first = Idea::factory()->create(['workspace_id' => $workspace->id, 'idea_stage_id' => null, 'title' => 'One', 'body' => 'Body one']);
    $second = Idea::factory()->create(['workspace_id' => $workspace->id, 'idea_stage_id' => null, 'title' => 'Two', 'body' => 'Body two']);
    $foreign = Idea::factory()->create(['workspace_id' => Workspace::factory()->create()->id, 'title' => 'Theirs']);

    auth()->forgetGuards();
    $this->withHeaders(parityApi($token))->putJson(route('api.ideas.update', $first), ['title' => 'Renamed', 'idea_stage_id' => $stage->id])->assertOk()->assertJsonPath('title', 'Renamed');
    TryPostServer::actingAs($user)->tool(UpdateIdeaTool::class, ['idea_id' => $second->id, 'title' => 'Renamed', 'idea_stage_id' => $stage->id])->assertOk();

    expect($first->fresh()->only(['title', 'body', 'idea_stage_id']))->toBe(['title' => 'Renamed', 'body' => 'Body one', 'idea_stage_id' => $stage->id])
        ->and($second->fresh()->only(['title', 'body', 'idea_stage_id']))->toBe(['title' => 'Renamed', 'body' => 'Body two', 'idea_stage_id' => $stage->id]);

    $blank = ['title' => '', 'body' => '', 'media_ids' => []];
    $web = $this->actingAs($user)->putJson(route('app.create.ideas.update', $first), $blank)->assertUnprocessable()->json('errors.title.0');
    auth()->forgetGuards();
    $api = $this->withHeaders(parityApi($token))->putJson(route('api.ideas.update', $first), $blank)->assertUnprocessable()->json('errors.title.0');
    TryPostServer::actingAs($user)->tool(UpdateIdeaTool::class, ['idea_id' => $first->id, ...$blank])->assertHasErrors([$web]);
    expect($api)->toBe($web)->and($web)->toBe(__('create.ideas.errors.empty'));

    $other = Workspace::factory()->create();
    $foreignLabel = WorkspaceLabel::factory()->create(['workspace_id' => $other->id]);
    $foreignMedia = Media::factory()->temporaryUpload($other)->stored()->create();

    foreach ([[['media_ids' => [$foreignMedia->id]], 'media_ids.0'], [['label_ids' => [$foreignLabel->id]], 'label_ids.0']] as [$payload, $key]) {
        $web = $this->actingAs($user)->putJson(route('app.create.ideas.update', $first), $payload)->assertUnprocessable()->json('errors')[$key][0];
        auth()->forgetGuards();
        $api = $this->withHeaders(parityApi($token))->putJson(route('api.ideas.update', $first), $payload)->assertUnprocessable()->json('errors')[$key][0];
        TryPostServer::actingAs($user)->tool(UpdateIdeaTool::class, ['idea_id' => $first->id, ...$payload])->assertHasErrors([$web]);
        expect($api)->toBe($web);
    }

    expect($first->fresh()->labels)->toHaveCount(0)->and($first->fresh()->media ?? [])->toBeEmpty();

    $this->actingAs($user)->putJson(route('app.create.ideas.update', $foreign), ['title' => 'Hacked'])->assertNotFound();
    auth()->forgetGuards();
    $this->withHeaders(parityApi($token))->putJson(route('api.ideas.update', $foreign), ['title' => 'Hacked'])->assertNotFound();
    TryPostServer::actingAs($user)->tool(UpdateIdeaTool::class, ['idea_id' => $foreign->id, 'title' => 'Hacked'])->assertHasErrors(['Idea not found.']);

    expect($foreign->fresh()->title)->toBe('Theirs')->and($first->fresh()->title)->toBe('Renamed');
});

test('deleting one idea or several through the api and mcp matches the web and skips foreign ids', function () {
    ['user' => $user, 'workspace' => $workspace, 'token' => $token] = parityContext();
    $foreign = Idea::factory()->create(['workspace_id' => Workspace::factory()->create()->id]);
    $mine = Idea::factory()->count(5)->create(['workspace_id' => $workspace->id]);

    $this->actingAs($user)->deleteJson(route('app.create.ideas.destroy', $foreign))->assertNotFound();
    auth()->forgetGuards();
    $this->withHeaders(parityApi($token))->deleteJson(route('api.ideas.destroy', $foreign))->assertNotFound();
    $this->withHeaders(parityApi($token))->deleteJson(route('api.ideas.destroy', $mine[0]))->assertNoContent();
    $this->withHeaders(parityApi($token))->deleteJson(route('api.ideas.bulk-destroy'), ['idea_ids' => [$mine[1]->id, $foreign->id]])->assertNoContent();
    TryPostServer::actingAs($user)->tool(DeleteIdeasTool::class, ['idea_ids' => [$mine[2]->id, $foreign->id]])->assertOk();
    $this->actingAs($user)->deleteJson(route('app.create.ideas.bulk-destroy'), ['idea_ids' => [$mine[3]->id, $foreign->id]])->assertRedirect();

    expect(Idea::query()->where('workspace_id', $workspace->id)->pluck('id')->all())->toBe([$mine[4]->id])
        ->and($foreign->fresh())->not->toBeNull();

    $tooMany = array_map(fn () => (string) Str::uuid(), range(1, Idea::MAX_BATCH + 1));
    $web = $this->actingAs($user)->deleteJson(route('app.create.ideas.bulk-destroy'), ['idea_ids' => $tooMany])->assertUnprocessable()->json('errors.idea_ids.0');
    auth()->forgetGuards();
    $api = $this->withHeaders(parityApi($token))->deleteJson(route('api.ideas.bulk-destroy'), ['idea_ids' => $tooMany])->assertUnprocessable()->json('errors.idea_ids.0');
    TryPostServer::actingAs($user)->tool(DeleteIdeasTool::class, ['idea_ids' => $tooMany])->assertHasErrors([$web]);

    expect($api)->toBe($web);
});

test('duplicating an idea through the api and mcp inserts the copy after the original like the web', function () {
    Storage::fake();
    ['user' => $user, 'workspace' => $workspace, 'token' => $token] = parityContext();
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $workspace->id]);
    $foreign = Idea::factory()->create(['workspace_id' => Workspace::factory()->create()->id]);
    $outcomes = [];

    foreach (['web', 'api', 'mcp'] as $surface) {
        Idea::query()->where('workspace_id', $workspace->id)->delete();
        $a = Idea::factory()->create(['workspace_id' => $workspace->id, 'idea_stage_id' => null, 'position' => 0, 'title' => 'A']);
        $b = Idea::factory()->create(['workspace_id' => $workspace->id, 'idea_stage_id' => null, 'position' => 1, 'title' => 'B']);
        $b->labels()->attach($label->id);

        auth()->forgetGuards();
        match ($surface) {
            'web' => $this->actingAs($user)->post(route('app.create.ideas.duplicate', $a))->assertRedirect(),
            'api' => $this->withHeaders(parityApi($token))->postJson(route('api.ideas.duplicate', $a))->assertCreated()->assertJsonPath('title', 'A'),
            'mcp' => TryPostServer::actingAs($user)->tool(DuplicateIdeaTool::class, ['idea_id' => $a->id])->assertOk(),
        };

        $outcomes[$surface] = Idea::query()->where('workspace_id', $workspace->id)->orderBy('position')->get()->map(fn (Idea $idea) => [$idea->title, $idea->position])->all();
    }

    $this->actingAs($user)->postJson(route('app.create.ideas.duplicate', $foreign))->assertNotFound();
    auth()->forgetGuards();
    $this->withHeaders(parityApi($token))->postJson(route('api.ideas.duplicate', $foreign))->assertNotFound();
    TryPostServer::actingAs($user)->tool(DuplicateIdeaTool::class, ['idea_id' => $foreign->id])->assertHasErrors(['Idea not found.']);

    expect($outcomes['api'])->toBe($outcomes['web'])
        ->and($outcomes['mcp'])->toBe($outcomes['web'])
        ->and($outcomes['web'])->toBe([['A', 0], ['A', 1], ['B', 2]]);
});

test('moving an idea through the api and mcp stores the same stage and order, to no stage too, and refuses a foreign stage', function () {
    ['user' => $user, 'workspace' => $workspace, 'token' => $token] = parityContext();
    $stage = IdeaStage::factory()->create(['workspace_id' => $workspace->id]);
    $foreignStage = IdeaStage::factory()->create(['workspace_id' => Workspace::factory()->create()->id]);
    $outcomes = [];

    foreach (['web', 'api', 'mcp'] as $surface) {
        Idea::query()->where('workspace_id', $workspace->id)->delete();
        $resident = Idea::factory()->create(['workspace_id' => $workspace->id, 'idea_stage_id' => $stage->id, 'position' => 0, 'title' => 'resident']);
        $moving = Idea::factory()->create(['workspace_id' => $workspace->id, 'idea_stage_id' => null, 'position' => 0, 'title' => 'moving']);
        $payload = ['idea_stage_id' => $stage->id, 'idea_ids' => [$moving->id, $resident->id]];

        auth()->forgetGuards();
        match ($surface) {
            'web' => $this->actingAs($user)->putJson(route('app.create.ideas.move', $moving), $payload)->assertRedirect(),
            'api' => $this->withHeaders(parityApi($token))->putJson(route('api.ideas.move', $moving), $payload)->assertOk()->assertJsonPath('idea_stage_id', $stage->id),
            'mcp' => TryPostServer::actingAs($user)->tool(MoveIdeasTool::class, ['idea_id' => $moving->id, ...$payload])->assertOk(),
        };

        $outcomes[$surface] = Idea::query()->where('workspace_id', $workspace->id)->orderBy('position')->get()->map(fn (Idea $idea) => [$idea->title, $idea->idea_stage_id === $stage->id, $idea->position])->all();
    }

    expect($outcomes['api'])->toBe($outcomes['web'])
        ->and($outcomes['mcp'])->toBe($outcomes['web'])
        ->and($outcomes['web'])->toBe([['moving', true, 0], ['resident', true, 1]]);

    $idea = Idea::query()->where('workspace_id', $workspace->id)->where('title', 'moving')->first();
    auth()->forgetGuards();
    $this->withHeaders(parityApi($token))->putJson(route('api.ideas.move', $idea), ['idea_stage_id' => null, 'idea_ids' => [$idea->id]])->assertOk()->assertJsonPath('idea_stage_id', null);
    expect($idea->fresh()->idea_stage_id)->toBeNull();
    $idea->update(['idea_stage_id' => $stage->id]);
    TryPostServer::actingAs($user)->tool(MoveIdeasTool::class, ['idea_id' => $idea->id, 'idea_ids' => [$idea->id]])->assertOk();
    expect($idea->fresh()->idea_stage_id)->toBeNull();

    $payload = ['idea_stage_id' => $foreignStage->id, 'idea_ids' => [$idea->id]];
    $web = $this->actingAs($user)->putJson(route('app.create.ideas.move', $idea), $payload)->assertUnprocessable()->json('errors.idea_stage_id.0');
    auth()->forgetGuards();
    $api = $this->withHeaders(parityApi($token))->putJson(route('api.ideas.move', $idea), $payload)->assertUnprocessable()->json('errors.idea_stage_id.0');
    TryPostServer::actingAs($user)->tool(MoveIdeasTool::class, ['idea_id' => $idea->id, ...$payload])->assertHasErrors([$web]);

    expect($api)->toBe($web)->and($idea->fresh()->idea_stage_id)->toBeNull();

    $foreignIdea = Idea::factory()->create(['workspace_id' => Workspace::factory()->create()->id]);
    $payload = ['idea_stage_id' => null, 'idea_ids' => [$idea->id, $foreignIdea->id]];
    $web = $this->actingAs($user)->putJson(route('app.create.ideas.move', $idea), $payload)->assertUnprocessable()->json('errors');
    auth()->forgetGuards();
    $api = $this->withHeaders(parityApi($token))->putJson(route('api.ideas.move', $idea), $payload)->assertUnprocessable()->json('errors');
    $webMessage = collect($web)->flatten()->first();
    TryPostServer::actingAs($user)->tool(MoveIdeasTool::class, ['idea_id' => $idea->id, ...$payload])->assertHasErrors([$webMessage]);

    expect($api)->toBe($web)->and($foreignIdea->fresh()->position)->toBe($foreignIdea->position);
});

test('a member without access gets the unauthorized error before the body is validated on the idea tools', function () {
    ['workspace' => $workspace] = parityContext();
    $outsider = User::factory()->create();
    $stage = IdeaStage::factory()->create(['workspace_id' => $workspace->id]);
    $idea = Idea::factory()->create(['workspace_id' => $workspace->id]);
    $unauthorized = (new AuthorizationException)->getMessage();

    TryPostServer::actingAs($outsider)->tool(UpdateIdeaStageTool::class, ['idea_stage_id' => $stage->id, 'name' => ''])->assertHasErrors([$unauthorized]);
    TryPostServer::actingAs($outsider)->tool(DeleteIdeaStageTool::class, ['idea_stage_id' => $stage->id])->assertHasErrors([$unauthorized]);
    TryPostServer::actingAs($outsider)->tool(UpdateIdeaTool::class, ['idea_id' => $idea->id, 'title' => ''])->assertHasErrors([$unauthorized]);
    TryPostServer::actingAs($outsider)->tool(MoveIdeasTool::class, ['idea_id' => $idea->id, 'idea_ids' => ['nope']])->assertHasErrors([$unauthorized]);
    TryPostServer::actingAs($outsider)->tool(CreateIdeaTool::class, ['title' => ''])->assertHasErrors([$unauthorized]);
    TryPostServer::actingAs($outsider)->tool(DeleteIdeasTool::class, ['idea_ids' => ['nope']])->assertHasErrors([$unauthorized]);
});

test('the idea tool descriptions say ideas cannot become posts through mcp', function () {
    foreach ([ListIdeasTool::class, GetIdeaTool::class, CreateIdeaTool::class, UpdateIdeaTool::class, DeleteIdeasTool::class, DuplicateIdeaTool::class, MoveIdeasTool::class] as $tool) {
        expect((new $tool)->description())->toContain('Ideas cannot be turned into posts through MCP; use create-post-tool with the idea text if needed.');
    }
});

test('the idea rules run without a request or auth and match the web refusals', function () {
    ['user' => $user, 'workspace' => $workspace] = parityContext();
    $foreignLabel = WorkspaceLabel::factory()->create(['workspace_id' => Workspace::factory()->create()->id]);
    $payload = ['title' => 'Idea', 'label_ids' => [$foreignLabel->id]];

    auth()->forgetGuards();
    $failures = Validator::make($payload, IdeaRequestRules::attributes($workspace))->errors()->keys();

    $webErrors = $this->actingAs($user)->postJson(route('app.create.ideas.store'), $payload)->assertUnprocessable()->json('errors');

    expect($failures)->toBe(array_keys($webErrors))
        ->and(IdeaRequestRules::emptyIdeaViolation(['title' => '', 'body' => null], null))->toBe(__('create.ideas.errors.empty'))
        ->and(IdeaRequestRules::emptyIdeaViolation(['title' => 'Ok'], null))->toBeNull();

    $webEmpty = $this->actingAs($user)->postJson(route('app.create.ideas.store'), ['title' => ''])->assertUnprocessable()->json('errors.title.0');

    expect($webEmpty)->toBe(IdeaRequestRules::emptyIdeaViolation(['title' => '']));
});

test('the api and mcp list the stages in board order with the same ids', function () {
    ['user' => $user, 'workspace' => $workspace, 'token' => $token] = parityContext();
    $workspace->ideaStages()->delete();
    $second = IdeaStage::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Second', 'position' => 1]);
    $first = IdeaStage::factory()->create(['workspace_id' => $workspace->id, 'name' => 'First', 'position' => 0]);
    Idea::factory()->count(2)->create(['workspace_id' => $workspace->id, 'idea_stage_id' => $first->id]);
    IdeaStage::factory()->create(['workspace_id' => Workspace::factory()->create()->id]);

    auth()->forgetGuards();
    $api = $this->withHeaders(parityApi($token))->getJson(route('api.idea-stages.index'))->assertOk()->json();
    $web = $this->actingAs($user)->get(route('app.create.ideas.index'))->viewData('page')['props']['stages'];
    $mcp = TryPostServer::actingAs($user)->tool(ListIdeaStagesTool::class, []);

    expect(array_column($api, 'id'))->toBe([$first->id, $second->id])
        ->and(array_column($api, 'id'))->toBe(array_column($web, 'id'))
        ->and(array_column($api, 'ideas_count'))->toBe([2, 0])
        ->and(array_keys($api[0]))->toBe(['id', 'name', 'position', 'ideas_count', 'created_at', 'updated_at']);

    $mcp->assertOk()->assertStructuredContent(['stages' => $api]);
});

test('creating a stage through the api and mcp stores the same and refuses the same names', function () {
    ['user' => $user, 'workspace' => $workspace, 'token' => $token] = parityContext();
    $before = $workspace->ideaStages()->count();

    auth()->forgetGuards();
    $this->withHeaders(parityApi($token))->postJson(route('api.idea-stages.store'), ['name' => 'Review'])->assertCreated()->assertJsonPath('position', $before);
    TryPostServer::actingAs($user)->tool(CreateIdeaStageTool::class, ['name' => 'Review'])->assertOk();

    foreach (['', str_repeat('a', 61)] as $name) {
        $web = $this->actingAs($user)->postJson(route('app.create.idea-stages.store'), ['name' => $name])->assertUnprocessable()->json('errors.name.0');
        auth()->forgetGuards();
        $api = $this->withHeaders(parityApi($token))->postJson(route('api.idea-stages.store'), ['name' => $name])->assertUnprocessable()->json('errors.name.0');
        TryPostServer::actingAs($user)->tool(CreateIdeaStageTool::class, ['name' => $name])->assertHasErrors([$web]);
        expect($api)->toBe($web);
    }

    expect($workspace->ideaStages()->where('name', 'Review')->pluck('position')->all())->toBe([$before, $before + 1])
        ->and($workspace->ideaStages()->count())->toBe($before + 2);
});

test('renaming a stage through the api and mcp stores the same and refuses a foreign stage', function () {
    ['user' => $user, 'workspace' => $workspace, 'token' => $token] = parityContext();
    $first = IdeaStage::factory()->create(['workspace_id' => $workspace->id]);
    $second = IdeaStage::factory()->create(['workspace_id' => $workspace->id]);
    $foreign = IdeaStage::factory()->create(['workspace_id' => Workspace::factory()->create()->id, 'name' => 'Theirs']);

    auth()->forgetGuards();
    $this->withHeaders(parityApi($token))->putJson(route('api.idea-stages.update', $first), ['name' => 'Renamed'])->assertOk()->assertJsonPath('name', 'Renamed');
    TryPostServer::actingAs($user)->tool(UpdateIdeaStageTool::class, ['idea_stage_id' => $second->id, 'name' => 'Renamed'])->assertOk();
    auth()->forgetGuards();
    $this->withHeaders(parityApi($token))->putJson(route('api.idea-stages.update', $first), ['name' => ''])->assertUnprocessable();
    TryPostServer::actingAs($user)->tool(UpdateIdeaStageTool::class, ['idea_stage_id' => $second->id, 'name' => ''])->assertHasErrors();

    $this->actingAs($user)->putJson(route('app.create.idea-stages.update', $foreign), ['name' => 'Hacked'])->assertNotFound();
    auth()->forgetGuards();
    $this->withHeaders(parityApi($token))->putJson(route('api.idea-stages.update', $foreign), ['name' => 'Hacked'])->assertNotFound();
    TryPostServer::actingAs($user)->tool(UpdateIdeaStageTool::class, ['idea_stage_id' => $foreign->id, 'name' => 'Hacked'])->assertHasErrors(['Idea stage not found.']);

    expect($first->fresh()->name)->toBe($second->fresh()->name)->toBe('Renamed')
        ->and($foreign->fresh()->name)->toBe('Theirs');
});

test('deleting a stage through the api and mcp moves its ideas to the unassigned column like the web', function () {
    ['user' => $user, 'workspace' => $workspace, 'token' => $token] = parityContext();
    $workspace->ideaStages()->delete();
    $foreign = IdeaStage::factory()->create(['workspace_id' => Workspace::factory()->create()->id]);
    $outcomes = [];

    foreach (['web', 'api', 'mcp'] as $surface) {
        $workspace->ideaStages()->delete();
        Idea::query()->where('workspace_id', $workspace->id)->delete();
        $kept = IdeaStage::factory()->create(['workspace_id' => $workspace->id, 'position' => 0]);
        $doomed = IdeaStage::factory()->create(['workspace_id' => $workspace->id, 'position' => 1]);
        $last = IdeaStage::factory()->create(['workspace_id' => $workspace->id, 'position' => 2]);
        $loose = Idea::factory()->create(['workspace_id' => $workspace->id, 'idea_stage_id' => null, 'position' => 0, 'title' => 'loose']);
        $inside = Idea::factory()->create(['workspace_id' => $workspace->id, 'idea_stage_id' => $doomed->id, 'position' => 0, 'title' => 'inside']);

        auth()->forgetGuards();
        match ($surface) {
            'web' => $this->actingAs($user)->delete(route('app.create.idea-stages.destroy', $doomed))->assertRedirect(),
            'api' => $this->withHeaders(parityApi($token))->deleteJson(route('api.idea-stages.destroy', $doomed))->assertNoContent(),
            'mcp' => TryPostServer::actingAs($user)->tool(DeleteIdeaStageTool::class, ['idea_stage_id' => $doomed->id])->assertOk(),
        };

        $outcomes[$surface] = [
            Idea::query()->where('workspace_id', $workspace->id)->orderBy('title')->get(['title', 'idea_stage_id', 'position'])->map(fn (Idea $idea) => [$idea->title, $idea->idea_stage_id === null, $idea->position])->all(),
            $workspace->ideaStages()->get()->map(fn (IdeaStage $stage) => [$stage->id === $kept->id, $stage->id === $last->id, $stage->position])->all(),
        ];
    }

    $this->actingAs($user)->deleteJson(route('app.create.idea-stages.destroy', $foreign))->assertNotFound();
    auth()->forgetGuards();
    $this->withHeaders(parityApi($token))->deleteJson(route('api.idea-stages.destroy', $foreign))->assertNotFound();
    TryPostServer::actingAs($user)->tool(DeleteIdeaStageTool::class, ['idea_stage_id' => $foreign->id])->assertHasErrors(['Idea stage not found.']);

    expect($outcomes['api'])->toEqual($outcomes['web'])
        ->and($outcomes['mcp'])->toEqual($outcomes['web'])
        ->and($outcomes['web'][0])->toEqual([['inside', true, 1], ['loose', true, 0]])
        ->and($foreign->fresh())->not->toBeNull();
});

test('reordering stages through the api and mcp stores the same order and refuses a stale or foreign list like the web', function () {
    ['user' => $user, 'workspace' => $workspace, 'token' => $token] = parityContext();
    $workspace->ideaStages()->delete();
    $stages = IdeaStage::factory()->count(3)->sequence(['position' => 0], ['position' => 1], ['position' => 2])->create(['workspace_id' => $workspace->id]);
    $foreign = IdeaStage::factory()->create(['workspace_id' => Workspace::factory()->create()->id]);
    $reversed = $stages->pluck('id')->reverse()->values()->all();

    auth()->forgetGuards();
    $apiOrder = $this->withHeaders(parityApi($token))->putJson(route('api.idea-stages.reorder'), ['stage_ids' => $reversed])->assertOk()->json('*.id');
    expect($apiOrder)->toBe($reversed);

    $original = $stages->pluck('id')->all();
    $mcp = TryPostServer::actingAs($user)->tool(ReorderIdeaStagesTool::class, ['stage_ids' => $original])->assertOk();
    expect($workspace->ideaStages()->pluck('id')->all())->toBe($original);

    $this->actingAs($user)->putJson(route('app.create.idea-stages.reorder'), ['stage_ids' => $reversed])->assertRedirect();
    expect($workspace->ideaStages()->pluck('id')->all())->toBe($reversed);

    foreach ([[...$reversed, $foreign->id], array_slice($reversed, 1), [$reversed[0], $reversed[1], $foreign->id]] as $bad) {
        $web = $this->actingAs($user)->putJson(route('app.create.idea-stages.reorder'), ['stage_ids' => $bad])->assertUnprocessable()->json('errors.stage_ids.0');
        auth()->forgetGuards();
        $api = $this->withHeaders(parityApi($token))->putJson(route('api.idea-stages.reorder'), ['stage_ids' => $bad])->assertUnprocessable()->json('errors.stage_ids.0');
        TryPostServer::actingAs($user)->tool(ReorderIdeaStagesTool::class, ['stage_ids' => $bad])->assertHasErrors([$web]);
        expect($api)->toBe($web);
    }

    expect($workspace->ideaStages()->pluck('id')->all())->toBe($reversed);
});
