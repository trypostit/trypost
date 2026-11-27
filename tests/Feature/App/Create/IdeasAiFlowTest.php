<?php

declare(strict_types=1);

use App\Ai\Agents\IdeaGenerator;
use App\Ai\Agents\MediaAltTextGenerator;
use App\Ai\Agents\PostWritingAssistant;
use App\Enums\Ai\PostAssistantMode;
use App\Models\Idea;
use App\Models\IdeaStage;
use App\Models\Media;
use App\Models\RssFeed;
use App\Models\RssFeedItem;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\Response as AuthResponse;
use Illuminate\Support\Facades\Gate;
use Laravel\Ai\Prompts\AgentPrompt;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function () {
    config(['trypost.self_hosted' => false]);

    $owner = User::factory()->create();
    $this->workspace = Workspace::factory()->create([
        'account_id' => $owner->account_id,
        'user_id' => $owner->id,
    ]);
    $this->workspace->ideaStages()->delete();
    $this->workspace->members()->attach($owner->id, membershipPivot('admin'));
    $owner->update(['current_workspace_id' => $this->workspace->id]);
    $this->owner = $owner->fresh();
    subscribeAccount($owner->account);
});

test('every member flavour runs the whole ideas board', function (string $access) {
    $user = workspaceMember($this->workspace, $access);
    $stage = IdeaStage::factory()->create(['workspace_id' => $this->workspace->id, 'position' => 0]);
    $other = IdeaStage::factory()->create(['workspace_id' => $this->workspace->id, 'position' => 1]);

    $this->actingAs($user)->get(route('app.create.ideas.index'))->assertOk();
    $this->actingAs($user)->get(route('app.create.ideas.create'))->assertOk();

    $this->actingAs($user)->post(route('app.create.idea-stages.store'), ['name' => 'Backlog'])->assertSessionHasNoErrors();
    $this->actingAs($user)->put(route('app.create.idea-stages.update', $stage), ['name' => 'Renamed'])->assertSessionHasNoErrors();
    $this->actingAs($user)->put(route('app.create.idea-stages.reorder'), [
        'stage_ids' => $this->workspace->ideaStages()->pluck('id')->reverse()->values()->all(),
    ])->assertSessionHasNoErrors();

    $this->actingAs($user)->post(route('app.create.ideas.store'), ['title' => 'Idea', 'idea_stage_id' => $stage->id])
        ->assertSessionHasNoErrors();
    $idea = Idea::query()->sole();

    $this->actingAs($user)->get(route('app.create.ideas.show', $idea))->assertOk();
    $this->actingAs($user)->put(route('app.create.ideas.update', $idea), ['title' => 'Edited'])->assertSessionHasNoErrors();
    $this->actingAs($user)->put(route('app.create.ideas.move', $idea), [
        'idea_stage_id' => $other->id,
        'idea_ids' => [$idea->id],
    ])->assertSessionHasNoErrors();
    $this->actingAs($user)->post(route('app.create.ideas.duplicate', $idea))->assertSessionHasNoErrors();

    expect($idea->fresh()->title)->toBe('Edited')
        ->and($idea->fresh()->idea_stage_id)->toBe($other->id)
        ->and($idea->fresh()->user_id)->toBe($user->id)
        ->and(Idea::count())->toBe(2)
        ->and($stage->fresh()->name)->toBe('Renamed');

    $this->actingAs($user)->deleteJson(route('app.create.ideas.bulk-destroy'), ['idea_ids' => Idea::pluck('id')->all()])->assertSessionHasNoErrors();
    $this->actingAs($user)->delete(route('app.create.idea-stages.destroy', $other))->assertSessionHasNoErrors();

    expect(Idea::count())->toBe(0)->and(IdeaStage::find($other->id))->toBeNull();
})->with(['admin', 'member', 'approval']);

test('a user outside the workspace cannot open or change the board', function () {
    $outsider = workspaceOutsider($this->workspace);
    $stage = IdeaStage::factory()->create(['workspace_id' => $this->workspace->id]);
    $idea = Idea::factory()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($outsider)->get(route('app.create.ideas.index'))->assertForbidden();
    $this->actingAs($outsider)->get(route('app.create.ideas.create'))->assertForbidden();
    $this->actingAs($outsider)->get(route('app.create.ideas.show', $idea))->assertForbidden();
    $this->actingAs($outsider)->putJson(route('app.create.ideas.move', $idea), ['idea_stage_id' => $stage->id, 'idea_ids' => [$idea->id]])->assertForbidden();
    $this->actingAs($outsider)->postJson(route('app.create.idea-stages.store'), ['name' => 'x'])->assertForbidden();
    $this->actingAs($outsider)->putJson(route('app.create.idea-stages.reorder'), ['stage_ids' => [$stage->id]])->assertForbidden();

    expect(IdeaStage::count())->toBe(1)->and($idea->fresh()->idea_stage_id)->toBeNull();
});

test('an idea of another workspace the user belongs to is not reachable from the current one', function () {
    $user = workspaceMember($this->workspace, 'member');
    $second = Workspace::factory()->create(['account_id' => $this->workspace->account_id, 'user_id' => $this->owner->id]);
    $second->members()->attach($user->id, membershipPivot('member'));
    $foreignIdea = Idea::factory()->create(['workspace_id' => $second->id]);
    $foreignStage = IdeaStage::factory()->create(['workspace_id' => $second->id]);

    $this->actingAs($user)->get(route('app.create.ideas.show', $foreignIdea))->assertNotFound();
    $this->actingAs($user)->putJson(route('app.create.ideas.update', $foreignIdea), ['title' => 'x'])->assertNotFound();
    $this->actingAs($user)->deleteJson(route('app.create.ideas.destroy', $foreignIdea))->assertNotFound();
    $this->actingAs($user)->putJson(route('app.create.idea-stages.update', $foreignStage), ['name' => 'x'])->assertNotFound();
    $this->actingAs($user)->deleteJson(route('app.create.idea-stages.destroy', $foreignStage))->assertNotFound();

    expect($foreignIdea->fresh()->title)->toBe($foreignIdea->title)->and(IdeaStage::find($foreignStage->id))->not->toBeNull();
});

test('a feed item is saved as an idea by every member flavour and never across workspaces', function (string $access) {
    $user = workspaceMember($this->workspace, $access);
    $item = RssFeedItem::factory()->for(RssFeed::factory()->create(['workspace_id' => $this->workspace->id]), 'feed')
        ->create(['title' => 'News', 'url' => 'https://example.com/news', 'image_url' => null]);

    $this->actingAs($user)->post(route('app.create.feed-items.idea', $item))->assertSessionHasNoErrors();

    expect(Idea::query()->sole()->user_id)->toBe($user->id);
})->with(['member', 'approval']);

test('the assistant runs every rewrite mode on the posted text and returns the suggestion', function (PostAssistantMode $mode) {
    PostWritingAssistant::fake(['Better caption']);
    $user = workspaceMember($this->workspace, 'approval');

    $this->actingAs($user)->postJson(route('app.posts.ai.assist'), [
        'mode' => $mode->value,
        'current_content' => 'Olá mundo',
    ])->assertOk()->assertJsonPath('content', 'Better caption');

    PostWritingAssistant::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->agent->mode === $mode
        && str_contains($prompt->agent->instructions(), 'Olá mundo')
        && str_contains($prompt->agent->instructions(), 'Keep the language of the existing caption'));
})->with([
    PostAssistantMode::Rephrase,
    PostAssistantMode::Shorten,
    PostAssistantMode::Expand,
    PostAssistantMode::MoreCasual,
    PostAssistantMode::MoreFormal,
]);

test('a rewrite that comes back empty is a validation error on the content', function () {
    PostWritingAssistant::fake(['   ']);

    $this->actingAs($this->owner)->postJson(route('app.posts.ai.assist'), ['mode' => 'shorten', 'current_content' => 'Hello there'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['current_content']);
});

test('a user outside the workspace gets no AI of any kind', function () {
    PostWritingAssistant::fake();
    MediaAltTextGenerator::fake();
    IdeaGenerator::fake();
    $outsider = workspaceOutsider($this->workspace);
    $asset = Media::factory()->temporaryUpload($this->workspace)->create();

    $this->actingAs($outsider)->postJson(route('app.posts.ai.assist'), ['mode' => 'rephrase', 'current_content' => 'Hello'])->assertForbidden();
    $this->actingAs($outsider)->postJson(route('app.posts.ai.alt-text'), ['media_id' => $asset->id])->assertForbidden();
    $this->actingAs($outsider)->postJson(route('app.create.ideas.generate'), ['business' => 'a', 'audience' => 'b'])->assertForbidden();

    PostWritingAssistant::assertNeverPrompted();
    MediaAltTextGenerator::assertNeverPrompted();
    IdeaGenerator::assertNeverPrompted();
});

test('every member flavour can use the idea generator and the alt text generator', function (string $access) {
    IdeaGenerator::fake([['title' => 'T', 'body' => 'B']]);
    MediaAltTextGenerator::fake(['A photo.']);
    $user = workspaceMember($this->workspace, $access);
    $asset = Media::factory()->temporaryUpload($this->workspace)->create();

    $this->actingAs($user)->postJson(route('app.create.ideas.generate'), ['business' => 'a', 'audience' => 'b'])->assertOk();
    $this->actingAs($user)->postJson(route('app.posts.ai.alt-text'), ['media_id' => $asset->id])->assertOk()->assertJsonPath('alt_text', 'A photo.');
})->with(['member', 'approval']);

test('a denied AI gate stops the alt text before the model is called', function () {
    MediaAltTextGenerator::fake();
    Gate::before(fn (User $user, string $ability): ?AuthResponse => $ability === 'useAi'
        ? AuthResponse::deny(__('billing.flash.subscription_required'))
        : null);
    $asset = Media::factory()->temporaryUpload($this->workspace)->create();

    $this->actingAs($this->owner)->postJson(route('app.posts.ai.alt-text'), ['media_id' => $asset->id])
        ->assertStatus(Response::HTTP_PAYMENT_REQUIRED)
        ->assertJsonPath('message', __('billing.flash.subscription_required'));

    MediaAltTextGenerator::assertNeverPrompted();
});

test('the idea generator and the alt text keep their rate limit', function (string $route) {
    IdeaGenerator::fake([['title' => 'T', 'body' => 'B']]);
    MediaAltTextGenerator::fake(['A photo.']);
    $asset = Media::factory()->temporaryUpload($this->workspace)->create();
    $payload = $route === 'app.posts.ai.alt-text' ? ['media_id' => $asset->id] : ['business' => 'a', 'audience' => 'b'];

    foreach (range(1, 10) as $attempt) {
        $this->actingAs($this->owner)->postJson(route($route), $payload)->assertOk();
    }

    $this->actingAs($this->owner)->postJson(route($route), $payload)->assertStatus(Response::HTTP_TOO_MANY_REQUESTS);
})->with(['app.create.ideas.generate', 'app.posts.ai.alt-text']);
