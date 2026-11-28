<?php

declare(strict_types=1);

use App\Actions\Idea\UpdateIdea;
use App\Dto\MediaItem;
use App\Enums\PostPlatform\ContentType;
use App\Models\Idea;
use App\Models\IdeaStage;
use App\Models\Media;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceLabel;
use App\Support\AiPromptRules;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['trypost.self_hosted' => false]);
    Storage::fake();

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

function ideaCrudAsset(Workspace $workspace): Media
{
    return Media::factory()->temporaryUpload($workspace)->stored()->create();
}

/**
 * @return list<string>
 */
function ideaCrudMediaIds(Idea $idea): array
{
    return collect($idea->fresh()->media)->pluck('id')->all();
}

test('store creates an idea at the end of the stage with labels and media in order', function () {
    $stage = IdeaStage::factory()->create(['workspace_id' => $this->workspace->id]);
    Idea::factory()->inStage($stage)->create(['user_id' => $this->user->id, 'position' => 0]);
    $labels = WorkspaceLabel::factory()->count(2)->create(['workspace_id' => $this->workspace->id]);
    $a1 = ideaCrudAsset($this->workspace);
    $a2 = ideaCrudAsset($this->workspace);

    $this->actingAs($this->user)->post(route('app.create.ideas.store'), [
        'title' => 'Launch post',
        'body' => 'Some body',
        'idea_stage_id' => $stage->id,
        'label_ids' => $labels->pluck('id')->all(),
        'media_ids' => [$a2->id, $a1->id],
    ])->assertRedirect();

    $idea = Idea::where('title', 'Launch post')->firstOrFail();

    expect($idea->position)->toBe(1)
        ->and($idea->idea_stage_id)->toBe($stage->id)
        ->and($idea->user_id)->toBe($this->user->id)
        ->and($idea->labels()->count())->toBe(2)
        ->and(ideaCrudMediaIds($idea))->toBe([$a2->id, $a1->id])
        ->and($idea->ownedMedia()->pluck('id')->all())->toBe([$a2->id, $a1->id])
        ->and($a1->fresh()->collection)->toBe(Media::COLLECTION_MEDIA)
        ->and($a1->fresh()->upload_token)->toBeNull()
        ->and(Media::query()->count())->toBe(2);
});

test('store works with only a title and rejects an empty idea', function () {
    $this->actingAs($this->user)->post(route('app.create.ideas.store'), ['title' => 'Just a title'])->assertRedirect();

    expect(Idea::where('title', 'Just a title')->firstOrFail()->position)->toBe(0);

    $this->actingAs($this->user)->postJson(route('app.create.ideas.store'), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['title'])
        ->assertJsonPath('errors.title.0', __('create.ideas.errors.empty'));
});

test('store rejects a media id from another workspace', function () {
    $foreign = Workspace::factory()->create();
    $asset = ideaCrudAsset($foreign);

    $this->actingAs($this->user)->postJson(route('app.create.ideas.store'), ['title' => 'x', 'media_ids' => [$asset->id]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['media_ids.0']);

    expect(Idea::count())->toBe(0);
});

test('store copies a media row owned by a post and the post keeps its own', function () {
    $post = Post::factory()->create(['workspace_id' => $this->workspace->id]);
    $media = Media::factory()->ownedByPost($post)->stored()->create();

    $this->actingAs($this->user)->post(route('app.create.ideas.store'), ['title' => 'x', 'media_ids' => [$media->id]])
        ->assertRedirect()->assertSessionHasNoErrors();

    $copy = Idea::query()->sole()->ownedMedia()->sole();

    expect($copy->id)->not->toBe($media->id)
        ->and($copy->path)->not->toBe($media->path)
        ->and(data_get($copy->meta, 'copied_from'))->toBe($media->id)
        ->and(Storage::exists($copy->path))->toBeTrue()
        ->and($media->fresh()->post_id)->toBe($post->id);
});

test('store rejects a logo or avatar row of the workspace', function () {
    $logo = Media::factory()->logo()->for($this->workspace, 'mediable')->create();

    $this->actingAs($this->user)->postJson(route('app.create.ideas.store'), ['title' => 'x', 'media_ids' => [$logo->id]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['media_ids.0']);
});

test('store rejects foreign and soft deleted labels', function () {
    $foreign = WorkspaceLabel::factory()->create();
    $deleted = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);
    $deleted->delete();

    $this->actingAs($this->user)->postJson(route('app.create.ideas.store'), ['title' => 'x', 'label_ids' => [$foreign->id]])
        ->assertUnprocessable()->assertJsonValidationErrors(['label_ids.0']);
    $this->actingAs($this->user)->postJson(route('app.create.ideas.store'), ['title' => 'x', 'label_ids' => [$deleted->id]])
        ->assertUnprocessable()->assertJsonValidationErrors(['label_ids.0']);
});

test('store rejects a stage from another workspace', function () {
    $stage = IdeaStage::factory()->create();

    $this->actingAs($this->user)->postJson(route('app.create.ideas.store'), ['title' => 'x', 'idea_stage_id' => $stage->id])
        ->assertUnprocessable()->assertJsonValidationErrors(['idea_stage_id']);
});

test('store rejects more media than the maximum', function () {
    $ids = collect(range(1, Idea::MAX_MEDIA + 1))->map(fn () => ideaCrudAsset($this->workspace)->id)->all();

    $this->actingAs($this->user)->postJson(route('app.create.ideas.store'), ['title' => 'x', 'media_ids' => $ids])
        ->assertUnprocessable()->assertJsonValidationErrors(['media_ids']);
});

test('update moves the idea to the end of the new stage and keeps media when absent', function () {
    $asset = ideaCrudAsset($this->workspace);
    $target = IdeaStage::factory()->create(['workspace_id' => $this->workspace->id]);
    Idea::factory()->inStage($target)->count(2)->sequence(['position' => 0], ['position' => 1])->create();
    $idea = Idea::factory()->withMedia($asset)->create(['workspace_id' => $this->workspace->id, 'position' => 0]);

    $this->actingAs($this->user)->put(route('app.create.ideas.update', $idea), [
        'title' => 'Renamed',
        'idea_stage_id' => $target->id,
    ])->assertRedirect();

    $idea->refresh();

    expect($idea->title)->toBe('Renamed')
        ->and($idea->idea_stage_id)->toBe($target->id)
        ->and($idea->position)->toBe(2)
        ->and($idea->media)->toEqual([MediaItem::fromMedia($asset)->toArray()]);
});

test('update can replace labels and media', function () {
    $idea = Idea::factory()->create(['workspace_id' => $this->workspace->id]);
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);
    $asset = ideaCrudAsset($this->workspace);

    $this->actingAs($this->user)->put(route('app.create.ideas.update', $idea), [
        'label_ids' => [$label->id],
        'media_ids' => [$asset->id],
    ])->assertRedirect();

    expect($idea->fresh()->labels()->pluck('workspace_labels.id')->all())->toBe([$label->id])
        ->and(ideaCrudMediaIds($idea))->toBe([$asset->id])
        ->and($asset->fresh()->idea_id)->toBe($idea->id);
});

test('update releasing an item deletes its row and, after commit, its file', function () {
    $idea = Idea::factory()->create(['workspace_id' => $this->workspace->id, 'title' => 'x']);
    $kept = ideaCrudAsset($this->workspace);
    $released = ideaCrudAsset($this->workspace);

    $this->actingAs($this->user)->put(route('app.create.ideas.update', $idea), ['media_ids' => [$kept->id, $released->id]])
        ->assertSessionHasNoErrors();
    $this->actingAs($this->user)->put(route('app.create.ideas.update', $idea), ['media_ids' => [$kept->id]])
        ->assertSessionHasNoErrors();

    expect(ideaCrudMediaIds($idea))->toBe([$kept->id])
        ->and(Media::find($released->id))->toBeNull()
        ->and(Storage::exists($released->path))->toBeFalse()
        ->and(Storage::exists($kept->path))->toBeTrue();
});

test('a failed update keeps the released item and its file', function () {
    $idea = Idea::factory()->create(['workspace_id' => $this->workspace->id, 'title' => 'x']);
    $kept = ideaCrudAsset($this->workspace);
    $released = ideaCrudAsset($this->workspace);
    UpdateIdea::execute($idea, ['media_ids' => [$kept->id, $released->id]]);

    expect(fn () => UpdateIdea::execute($idea->fresh(), ['media_ids' => [$kept->id], 'label_ids' => [(string) Str::uuid()]]))
        ->toThrow(QueryException::class);

    expect(Media::find($released->id)?->idea_id)->toBe($idea->id)
        ->and(Storage::exists($released->path))->toBeTrue();
});

test('update cannot leave the idea empty', function () {
    $idea = Idea::factory()->create(['workspace_id' => $this->workspace->id, 'title' => 'Only', 'body' => null]);

    $this->actingAs($this->user)->putJson(route('app.create.ideas.update', $idea), ['title' => null])
        ->assertUnprocessable()->assertJsonValidationErrors(['title']);
});

test('duplicate inserts a copy right after the original with labels and media', function () {
    $stage = IdeaStage::factory()->create(['workspace_id' => $this->workspace->id]);
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);
    [$a, $b, $c] = Idea::factory()->inStage($stage)->count(3)
        ->sequence(['position' => 0, 'title' => 'a'], ['position' => 1, 'title' => 'b'], ['position' => 2, 'title' => 'c'])
        ->create();
    $owned = Media::factory()->ownedByIdea($b)->stored()->create();
    $b->update(['media' => [[...MediaItem::fromMedia($owned)->toArray(), 'meta' => [...$owned->meta, 'alt_text' => 'Alt']]]]);
    $b->labels()->sync([$label->id]);

    $this->actingAs($this->user)->post(route('app.create.ideas.duplicate', $b))->assertRedirect();

    $ordered = Idea::where('idea_stage_id', $stage->id)->orderBy('position')->get();
    $copy = $ordered[2];

    expect($ordered->count())->toBe(4)
        ->and($ordered->pluck('id')->all()[0])->toBe($a->id)
        ->and($ordered->pluck('id')->all()[1])->toBe($b->id)
        ->and($ordered->pluck('id')->all()[3])->toBe($c->id)
        ->and($copy->id)->not->toBe($b->id)
        ->and($copy->title)->toBe('b')
        ->and($copy->user_id)->toBe($this->user->id)
        ->and($copy->media)->toHaveCount(1)
        ->and(data_get($copy->media, '0.id'))->not->toBe($owned->id)
        ->and(data_get($copy->media, '0.path'))->not->toBe($owned->path)
        ->and(data_get($copy->media, '0.meta.alt_text'))->toBe('Alt')
        ->and($copy->ownedMedia()->pluck('id')->all())->toBe([data_get($copy->media, '0.id')])
        ->and(Storage::exists(data_get($copy->media, '0.path')))->toBeTrue()
        ->and(ideaCrudMediaIds($b))->toBe([$owned->id])
        ->and($owned->fresh()->idea_id)->toBe($b->id)
        ->and($copy->labels()->pluck('workspace_labels.id')->all())->toBe([$label->id])
        ->and($ordered->pluck('position')->all())->toBe([0, 1, 2, 3]);
});

test('bulk destroy deletes own ideas and ignores foreign ones', function () {
    $own = Idea::factory()->count(2)->create(['workspace_id' => $this->workspace->id]);
    $foreign = Idea::factory()->create();

    $this->actingAs($this->user)->delete(route('app.create.ideas.bulk-destroy'), [
        'idea_ids' => [...$own->pluck('id')->all(), $foreign->id],
    ])->assertRedirect();

    expect(Idea::where('workspace_id', $this->workspace->id)->count())->toBe(0)
        ->and(Idea::find($foreign->id))->not->toBeNull();
});

test('destroy deletes the idea', function () {
    $idea = Idea::factory()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->user)->delete(route('app.create.ideas.destroy', $idea))->assertRedirect();

    expect(Idea::find($idea->id))->toBeNull();
});

test('a user outside the workspace is forbidden on every endpoint', function () {
    $outsider = workspaceOutsider($this->workspace);
    $idea = Idea::factory()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($outsider)->postJson(route('app.create.ideas.store'), ['title' => 'x'])->assertForbidden();
    $this->actingAs($outsider)->putJson(route('app.create.ideas.update', $idea), ['title' => 'x'])->assertForbidden();
    $this->actingAs($outsider)->deleteJson(route('app.create.ideas.destroy', $idea))->assertForbidden();
    $this->actingAs($outsider)->deleteJson(route('app.create.ideas.bulk-destroy'), ['idea_ids' => [$idea->id]])->assertForbidden();
    $this->actingAs($outsider)->postJson(route('app.create.ideas.duplicate', $idea))->assertForbidden();

    expect(Idea::find($idea->id))->not->toBeNull();
});

test('another workspaces idea is not found', function () {
    $idea = Idea::factory()->create();

    $this->actingAs($this->user)->putJson(route('app.create.ideas.update', $idea), ['title' => 'x'])->assertNotFound();
    $this->actingAs($this->user)->deleteJson(route('app.create.ideas.destroy', $idea))->assertNotFound();
    $this->actingAs($this->user)->postJson(route('app.create.ideas.duplicate', $idea))->assertNotFound();

    expect(Idea::find($idea->id)->title)->toBe($idea->title);
});

test('update rejects a foreign label and a foreign media id', function () {
    $idea = Idea::factory()->create(['workspace_id' => $this->workspace->id]);
    $foreignLabel = WorkspaceLabel::factory()->create();
    $foreignAsset = ideaCrudAsset(Workspace::factory()->create());

    $this->actingAs($this->user)->putJson(route('app.create.ideas.update', $idea), ['label_ids' => [$foreignLabel->id]])
        ->assertUnprocessable()->assertJsonValidationErrors(['label_ids.0']);
    $this->actingAs($this->user)->putJson(route('app.create.ideas.update', $idea), ['media_ids' => [$foreignAsset->id]])
        ->assertUnprocessable()->assertJsonValidationErrors(['media_ids.0']);
});

test('a duplicated media id is rejected', function () {
    $asset = ideaCrudAsset($this->workspace);

    $this->actingAs($this->user)->postJson(route('app.create.ideas.store'), ['title' => 'x', 'media_ids' => [$asset->id, $asset->id]])
        ->assertUnprocessable()->assertJsonValidationErrors(['media_ids.0']);
});

test('update keeps accepting a stored media id whose row is gone and keeps it as stored', function () {
    $gone = ideaCrudAsset($this->workspace);
    $kept = ideaCrudAsset($this->workspace);
    $idea = Idea::factory()->create([
        'workspace_id' => $this->workspace->id,
        'title' => 'With media',
        'media' => [MediaItem::fromMedia($gone)->toArray(), MediaItem::fromMedia($kept)->toArray()],
    ]);
    Media::query()->whereKey($gone->id)->delete();

    $this->actingAs($this->user)->put(route('app.create.ideas.update', $idea), [
        'title' => 'Renamed',
        'media_ids' => [$gone->id, $kept->id],
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($idea->fresh()->title)->toBe('Renamed')
        ->and(ideaCrudMediaIds($idea))->toBe([$gone->id, $kept->id])
        ->and(data_get($idea->fresh()->media, '0'))->toEqual(MediaItem::fromMedia($gone)->toArray())
        ->and($idea->ownedMedia()->pluck('id')->all())->toBe([$kept->id]);
});

test('update still rejects a vanished media id that was not on the idea', function () {
    $gone = ideaCrudAsset($this->workspace);
    $idea = Idea::factory()->create(['workspace_id' => $this->workspace->id, 'title' => 'x']);
    Media::query()->whereKey($gone->id)->delete();

    $this->actingAs($this->user)->putJson(route('app.create.ideas.update', $idea), ['media_ids' => [$gone->id]])
        ->assertUnprocessable()->assertJsonValidationErrors(['media_ids.0']);
});

test('a media copy that fails while saving an idea reports the error on media_ids', function () {
    $post = Post::factory()->create(['workspace_id' => $this->workspace->id]);
    $missingFile = Media::factory()->ownedByPost($post)->create();
    $idea = Idea::factory()->create(['workspace_id' => $this->workspace->id, 'title' => 'x']);

    $this->actingAs($this->user)->postJson(route('app.create.ideas.store'), ['title' => 'x', 'media_ids' => [$missingFile->id]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['media_ids.0' => __('posts.errors.media_expired')])
        ->assertJsonMissingValidationErrors(['media.0']);
    $this->actingAs($this->user)->putJson(route('app.create.ideas.update', $idea), ['media_ids' => [$missingFile->id]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['media_ids.0' => __('posts.errors.media_expired')]);

    $withMissing = Idea::factory()->create([
        'workspace_id' => $this->workspace->id,
        'media' => [MediaItem::fromMedia($missingFile)->toArray()],
    ]);

    $this->actingAs($this->user)->postJson(route('app.create.ideas.duplicate', $withMissing))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['media_ids.0' => __('posts.errors.media_expired')]);
});

test('the body is capped at the assistant prompt limit', function () {
    $this->actingAs($this->user)->postJson(route('app.create.ideas.store'), ['body' => str_repeat('a', AiPromptRules::PROMPT_MAX_LENGTH + 1)])
        ->assertUnprocessable()->assertJsonValidationErrors(['body']);

    $this->actingAs($this->user)->post(route('app.create.ideas.store'), ['body' => str_repeat('a', AiPromptRules::PROMPT_MAX_LENGTH)])
        ->assertRedirect()->assertSessionHasNoErrors();
});

test('creating a post from an idea copies its media and the idea keeps its own', function () {
    $channel = SocialAccount::factory()->linkedin()->create(['workspace_id' => $this->workspace->id]);
    $first = ideaCrudAsset($this->workspace);
    $second = ideaCrudAsset($this->workspace);
    $this->actingAs($this->user)->post(route('app.create.ideas.store'), ['title' => 'Idea', 'media_ids' => [$first->id, $second->id]])
        ->assertSessionHasNoErrors();
    $idea = Idea::query()->sole();

    $this->actingAs($this->user)->post(route('app.posts.store'), [
        'status' => 'draft',
        'content' => 'From the idea',
        'media' => $idea->media,
        'destinations' => [['social_account_id' => $channel->id, 'content_type' => ContentType::LinkedInPost->value]],
    ])->assertSessionHasNoErrors();

    $copies = Post::query()->sole()->ownedMedia;

    expect($copies->map(fn (Media $media) => data_get($media->meta, 'copied_from'))->all())->toBe([$first->id, $second->id])
        ->and($copies->pluck('path')->intersect([$first->path, $second->path]))->toBeEmpty()
        ->and($copies->every(fn (Media $media) => Storage::exists($media->path)))->toBeTrue()
        ->and(ideaCrudMediaIds($idea))->toBe([$first->id, $second->id])
        ->and($idea->ownedMedia()->pluck('id')->all())->toBe([$first->id, $second->id])
        ->and(Storage::exists($first->path))->toBeTrue();
});
