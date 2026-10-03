<?php

declare(strict_types=1);

use App\Dto\MediaItem;
use App\Models\Idea;
use App\Models\IdeaStage;
use App\Models\Media;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceLabel;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['trypost.self_hosted' => false]);

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->user = $this->user->fresh();
    subscribeAccount($this->user->account);
});

test('a new workspace renders the board with its three default stages', function () {
    $this->actingAs($this->user)->get(route('app.create.ideas.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('create/Ideas')
            ->where('view', 'board')
            ->has('stages', 3)
            ->where('unassigned_count', 0)
            ->where('editor', null));
});

test('visiting the page does not bring back deleted stages', function () {
    $this->workspace->ideaStages()->delete();

    $this->actingAs($this->user)->get(route('app.create.ideas.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('stages', 0));

    expect($this->workspace->ideaStages()->count())->toBe(0);
});

test('board lists every idea ordered by position within its stage with light cards', function () {
    $g1 = IdeaStage::factory()->create(['workspace_id' => $this->workspace->id, 'position' => 0]);
    $g2 = IdeaStage::factory()->create(['workspace_id' => $this->workspace->id, 'position' => 1]);
    $media = Media::factory()->temporaryUpload($this->workspace)->count(2)->create();
    $u = Idea::factory()->create(['workspace_id' => $this->workspace->id, 'position' => 0]);
    $b = Idea::factory()->inStage($g2)->create(['position' => 0]);
    $a2 = Idea::factory()->inStage($g1)->create(['position' => 1, 'body' => str_repeat('x', 500)]);
    $a1 = Idea::factory()->inStage($g1)->create(['position' => 0]);
    $a2->update(['media' => $media->map(fn (Media $m) => MediaItem::fromMedia($m)->toArray())->all()]);

    $this->actingAs($this->user)->get(route('app.create.ideas.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('board', function ($board) use ($g1, $g2, $a1, $a2, $b, $u, $media): bool {
                $columns = collect($board)
                    ->groupBy(fn (array $card): string => $card['idea_stage_id'] ?? 'unassigned')
                    ->map(fn ($cards): array => $cards->pluck('id')->all());

                $card = collect($board)->firstWhere('id', $a2->id);

                return $columns->get($g1->id) === [$a1->id, $a2->id]
                    && $columns->get($g2->id) === [$b->id]
                    && $columns->get('unassigned') === [$u->id]
                    && mb_strlen($card['excerpt']) <= 300
                    && data_get($card, 'cover.id') === $media[0]->id
                    && ! array_key_exists('body', $card)
                    && ! array_key_exists('position', $card);
            })
            ->where('unassigned_count', 1)
            ->missing('ideas'));
});

test('gallery paginates newest first with the configured page size', function () {
    $size = (int) config('app.pagination.default');
    foreach (range(1, $size + 1) as $i) {
        Idea::factory()->create(['workspace_id' => $this->workspace->id, 'created_at' => now()->subMinutes($size + 2 - $i)]);
    }
    $newest = Idea::orderByDesc('created_at')->first();

    $this->actingAs($this->user)->get(route('app.create.ideas.index', ['view' => 'gallery']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('view', 'gallery')
            ->missing('board')
            ->has('ideas.data', $size)
            ->where('ideas.data.0.id', $newest->id)
            ->where('ideas.meta.current_page', 1)
            ->where('ideas.meta.last_page', 2));
});

test('label, untagged and stage filters', function () {
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);
    $stage = IdeaStage::factory()->create(['workspace_id' => $this->workspace->id]);
    $tagged = Idea::factory()->create(['workspace_id' => $this->workspace->id]);
    $tagged->labels()->sync([$label->id]);
    $other = Idea::factory()->create(['workspace_id' => $this->workspace->id]);
    $other->labels()->sync([WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id])->id]);
    $bare = Idea::factory()->inStage($stage)->create();

    $ids = fn (array $query) => $this->actingAs($this->user)
        ->get(route('app.create.ideas.index', $query))
        ->assertOk()
        ->viewData('page')['props']['board'];

    $boardIds = fn (array $query) => collect($ids($query))->pluck('id')->sort()->values()->all();

    expect($boardIds(['labels' => [$label->id]]))->toBe([$tagged->id])
        ->and($boardIds(['untagged' => 1]))->toBe([$bare->id])
        ->and($boardIds(['labels' => [$label->id], 'untagged' => 1]))->toBe(collect([$tagged->id, $bare->id])->sort()->values()->all())
        ->and($boardIds(['labels' => ['not-a-uuid']]))->toHaveCount(3);

    $this->actingAs($this->user)->get(route('app.create.ideas.index', ['view' => 'gallery', 'stages' => [$stage->id]]))
        ->assertInertia(fn (Assert $page) => $page->has('ideas.data', 1)->where('ideas.data.0.id', $bare->id));
});

test('column counts follow the label filter', function () {
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);
    $stage = IdeaStage::factory()->create(['workspace_id' => $this->workspace->id]);
    Idea::factory()->create(['workspace_id' => $this->workspace->id]);
    Idea::factory()->inStage($stage)->create()->labels()->sync([$label->id]);
    Idea::factory()->inStage($stage)->create();

    $this->actingAs($this->user)
        ->get(route('app.create.ideas.index', ['labels' => [$label->id]]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('unassigned_count', 0)
            ->where('stages', fn ($stages) => collect($stages)->firstWhere('id', $stage->id)['ideas_count'] === 1));

    $this->actingAs($this->user)
        ->get(route('app.create.ideas.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('unassigned_count', 1)
            ->where('stages', fn ($stages) => collect($stages)->firstWhere('id', $stage->id)['ideas_count'] === 2));
});

test('create editor carries the stage and is not treated as an idea id', function () {
    $stage = IdeaStage::factory()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->user)->get(route('app.create.ideas.create', ['stage' => $stage->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('editor', ['mode' => 'create', 'idea_stage_id' => $stage->id]));

    $this->actingAs($this->user)->get(route('app.create.ideas.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('editor.idea_stage_id', null));
});

test('show carries the full idea', function () {
    $asset = Media::factory()->temporaryUpload($this->workspace)->create();
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);
    $idea = Idea::factory()->withMedia($asset)->create(['workspace_id' => $this->workspace->id, 'body' => str_repeat('y', 500)]);
    $idea->labels()->sync([$label->id]);

    $this->actingAs($this->user)->get(route('app.create.ideas.show', $idea))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('editor.mode', 'edit')
            ->where('editor.idea.id', $idea->id)
            ->where('editor.idea.body', str_repeat('y', 500))
            ->where('editor.idea.media', [MediaItem::fromMedia($asset)->toArray()])
            ->where('editor.idea.label_ids', [$label->id]));
});

test('a foreign idea and a user outside the workspace are forbidden', function () {
    $this->actingAs($this->user)->get(route('app.create.ideas.show', Idea::factory()->create()))->assertForbidden();

    $outsider = workspaceOutsider($this->workspace);

    $this->actingAs($outsider->fresh())->get(route('app.create.ideas.index'))->assertForbidden();
});

test('the gallery stage filter can include ideas without a stage', function () {
    $stage = IdeaStage::factory()->create(['workspace_id' => $this->workspace->id]);
    $other = IdeaStage::factory()->create(['workspace_id' => $this->workspace->id]);
    $loose = Idea::factory()->create(['workspace_id' => $this->workspace->id]);
    $staged = Idea::factory()->inStage($stage)->create();
    Idea::factory()->inStage($other)->create();

    $galleryIds = fn (array $query) => collect(
        $this->actingAs($this->user)
            ->get(route('app.create.ideas.index', ['view' => 'gallery', ...$query]))
            ->assertOk()
            ->viewData('page')['props']['ideas']['data'],
    )->pluck('id')->sort()->values()->all();

    expect($galleryIds(['unassigned' => 1]))->toBe([$loose->id])
        ->and($galleryIds(['unassigned' => 1, 'stages' => [$stage->id]]))->toBe(collect([$loose->id, $staged->id])->sort()->values()->all())
        ->and($galleryIds([]))->toHaveCount(3);

    $this->actingAs($this->user)->get(route('app.create.ideas.index', ['view' => 'gallery', 'unassigned' => 1]))
        ->assertInertia(fn (Assert $page) => $page->where('filters.unassigned', true));

    $this->actingAs($this->user)->get(route('app.create.ideas.index', ['unassigned' => 1]))
        ->assertInertia(fn (Assert $page) => $page->has('board', 3));
});
