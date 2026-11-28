<?php

declare(strict_types=1);

use App\Enums\PostTemplate\Visibility;
use App\Enums\User\Locale;
use App\Models\PostTemplate;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Str;

function templatePayload(array $overrides = []): array
{
    return [
        'emoji' => '🚀',
        'title' => 'Launch day',
        'description' => 'Announce the launch.',
        'body' => "• Hook\n• Proof",
        'visibility' => 'personal',
        ...$overrides,
    ];
}

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

test('storing a personal template redirects to the personal list', function () {
    $this->actingAs($this->alice)->post(route('app.create.templates.store'), templatePayload())
        ->assertRedirect(route('app.create.templates.index', ['view' => 'personal']));

    $template = PostTemplate::query()->sole();

    expect($template->user_id)->toBe($this->alice->id)
        ->and($template->workspace_id)->toBe($this->workspace->id)
        ->and($template->visibility)->toBe(Visibility::Personal)
        ->and($template->title)->toBe('Launch day');
});

test('store validation rejects missing fields, long bodies and unknown visibilities', function () {
    $this->actingAs($this->alice)->post(route('app.create.templates.store'), templatePayload(['title' => '']))->assertSessionHasErrors('title');
    $this->actingAs($this->alice)->post(route('app.create.templates.store'), templatePayload(['body' => '']))->assertSessionHasErrors('body');
    $this->actingAs($this->alice)->post(route('app.create.templates.store'), templatePayload(['body' => Str::repeat('a', 10001)]))->assertSessionHasErrors('body');
    $this->actingAs($this->alice)->post(route('app.create.templates.store'), templatePayload(['visibility' => 'public']))->assertSessionHasErrors('visibility');

    expect(PostTemplate::query()->count())->toBe(0);
});

test('the creator updates every field including visibility', function () {
    $template = PostTemplate::factory()->personal($this->alice)->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->alice)->put(route('app.create.templates.update', $template), templatePayload(['visibility' => 'team', 'title' => 'Renamed']))
        ->assertRedirect(route('app.create.templates.index', ['view' => 'team']));

    $template->refresh();

    expect($template->title)->toBe('Renamed')
        ->and($template->emoji)->toBe('🚀')
        ->and($template->description)->toBe('Announce the launch.')
        ->and($template->body)->toBe("• Hook\n• Proof")
        ->and($template->visibility)->toBe(Visibility::Team);
});

test('another member can edit a team template', function () {
    $template = PostTemplate::factory()->team()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->alice->id]);

    $this->actingAs($this->bob)->put(route('app.create.templates.update', $template), templatePayload(['visibility' => 'team', 'title' => 'Edited by Bob']))
        ->assertRedirect();

    expect($template->fresh()->title)->toBe('Edited by Bob')
        ->and($template->fresh()->user_id)->toBe($this->alice->id);
});

test('another member can delete a team template but not a teammate personal one', function () {
    $team = PostTemplate::factory()->team()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->alice->id]);
    $personal = PostTemplate::factory()->personal($this->alice)->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->bob)->delete(route('app.create.templates.destroy', $personal))->assertForbidden();
    $this->actingAs($this->bob)->delete(route('app.create.templates.destroy', $team))->assertRedirect();

    expect(PostTemplate::query()->pluck('id')->all())->toBe([$personal->id]);
});

test('only the creator changes visibility', function () {
    $template = PostTemplate::factory()->team()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->alice->id]);

    $this->actingAs($this->bob)->put(route('app.create.templates.update', $template), templatePayload(['visibility' => 'personal']))
        ->assertSessionHasErrors('visibility');

    expect($template->fresh()->visibility)->toBe(Visibility::Team)
        ->and($template->fresh()->title)->toBe($template->title);
});

test('duplicating a custom template adds the copy suffix and redirects to the target scope', function () {
    $source = PostTemplate::factory()->personal($this->alice)->create(['workspace_id' => $this->workspace->id, 'title' => 'Weekly recap']);

    $this->actingAs($this->alice)->post(route('app.create.templates.duplicate', $source), ['visibility' => 'team'])
        ->assertRedirect(route('app.create.templates.index', ['view' => 'team']));

    $copy = PostTemplate::query()->where('id', '!=', $source->id)->sole();

    expect($copy->title)->toBe('Weekly recap (copy)')
        ->and($copy->visibility)->toBe(Visibility::Team)
        ->and($copy->user_id)->toBe($this->alice->id)
        ->and($copy->body)->toBe($source->body);
});

test('duplicating a library template copies the strings of the actor locale', function () {
    $user = workspaceMember($this->workspace, 'member', ['locale' => Locale::PortugueseBrazil]);
    app()->setLocale('pt-BR');
    $title = __('template_library.origin_story.title');
    $description = __('template_library.origin_story.description');
    $body = __('template_library.origin_story.body');
    app()->setLocale('en');

    $this->actingAs($user)->post(route('app.create.templates.library.duplicate', 'origin_story'), ['visibility' => 'personal'])
        ->assertRedirect(route('app.create.templates.index', ['view' => 'personal']));

    $copy = PostTemplate::query()->sole();

    expect($copy->title)->toBe("{$title} (cópia)")
        ->and($copy->description)->toBe($description)
        ->and($copy->body)->toBe($body)
        ->and($copy->emoji)->toBe('🌱')
        ->and($copy->user_id)->toBe($user->id)
        ->and($copy->visibility)->toBe(Visibility::Personal);
});

test('no write sets a success flash', function () {
    $template = PostTemplate::factory()->team()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->alice->id]);
    $this->actingAs($this->alice);

    $this->post(route('app.create.templates.store'), templatePayload())->assertSessionMissing('flash')->assertSessionMissing('success');
    $this->put(route('app.create.templates.update', $template), templatePayload(['visibility' => 'team']))->assertSessionMissing('flash')->assertSessionMissing('success');
    $this->post(route('app.create.templates.duplicate', $template), ['visibility' => 'team'])->assertSessionMissing('flash')->assertSessionMissing('success');
    $this->post(route('app.create.templates.library.duplicate', 'quick_win'), ['visibility' => 'team'])->assertSessionMissing('flash')->assertSessionMissing('success');
    $this->delete(route('app.create.templates.destroy', $template))->assertSessionMissing('flash')->assertSessionMissing('success');
});

test('the same routes answer JSON when the request expects it', function () {
    $this->actingAs($this->alice);

    $created = $this->postJson(route('app.create.templates.store'), templatePayload())
        ->assertCreated()
        ->assertJsonStructure(['id', 'emoji', 'title', 'description', 'body', 'visibility', 'author', 'can_edit', 'can_change_visibility', 'created_at'])
        ->assertJsonPath('title', 'Launch day')
        ->assertJsonPath('can_change_visibility', true);

    $template = PostTemplate::query()->findOrFail($created->json('id'));

    $this->putJson(route('app.create.templates.update', $template), templatePayload(['title' => 'Updated']))
        ->assertOk()
        ->assertJsonPath('title', 'Updated');

    $this->postJson(route('app.create.templates.duplicate', $template), ['visibility' => 'team'])
        ->assertCreated()
        ->assertJsonPath('title', 'Updated (copy)');

    $this->postJson(route('app.create.templates.library.duplicate', 'quick_win'), ['visibility' => 'team'])
        ->assertCreated()
        ->assertJsonPath('title', __('template_library.quick_win.title').' (copy)');

    $this->deleteJson(route('app.create.templates.destroy', $template))->assertNoContent();

    $this->postJson(route('app.create.templates.store'), templatePayload(['title' => '']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('title');

    $aliceOnly = PostTemplate::factory()->personal($this->alice)->create(['workspace_id' => $this->workspace->id]);
    $this->actingAs($this->bob);

    $this->putJson(route('app.create.templates.update', $aliceOnly), templatePayload())->assertForbidden();
    $this->deleteJson(route('app.create.templates.destroy', $aliceOnly))->assertForbidden();
    $this->postJson(route('app.create.templates.duplicate', $aliceOnly), ['visibility' => 'team'])->assertForbidden();
});

test('a user outside the workspace is forbidden on every write endpoint', function () {
    $outsider = workspaceOutsider($this->workspace);
    $template = PostTemplate::factory()->team()->create(['workspace_id' => $this->workspace->id]);
    $this->actingAs($outsider);

    $this->post(route('app.create.templates.store'), templatePayload())->assertForbidden();
    $this->put(route('app.create.templates.update', $template), templatePayload())->assertForbidden();
    $this->delete(route('app.create.templates.destroy', $template))->assertForbidden();
    $this->post(route('app.create.templates.duplicate', $template), ['visibility' => 'team'])->assertForbidden();
    $this->post(route('app.create.templates.library.duplicate', 'quick_win'), ['visibility' => 'team'])->assertForbidden();

    expect(PostTemplate::query()->count())->toBe(1);
});

test('a template from another workspace is forbidden on every endpoint', function () {
    $stranger = User::factory()->create();
    $otherWorkspace = Workspace::factory()->create(['account_id' => $stranger->account_id, 'user_id' => $stranger->id]);
    $foreign = PostTemplate::factory()->team()->create(['workspace_id' => $otherWorkspace->id]);
    $this->actingAs($this->alice);

    $this->put(route('app.create.templates.update', $foreign), templatePayload(['visibility' => 'team']))->assertForbidden();
    $this->delete(route('app.create.templates.destroy', $foreign))->assertForbidden();
    $this->post(route('app.create.templates.duplicate', $foreign), ['visibility' => 'team'])->assertForbidden();

    expect($foreign->fresh())->not->toBeNull();
});

test('a member can duplicate a teammate team template', function () {
    $source = PostTemplate::factory()->team()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->alice->id, 'title' => 'Shared']);

    $this->actingAs($this->bob)->post(route('app.create.templates.duplicate', $source), ['visibility' => 'personal'])
        ->assertRedirect(route('app.create.templates.index', ['view' => 'personal']));

    $copy = PostTemplate::query()->where('id', '!=', $source->id)->sole();

    expect($copy->user_id)->toBe($this->bob->id)
        ->and($copy->visibility)->toBe(Visibility::Personal)
        ->and($copy->title)->toBe('Shared (copy)');
});

test('duplicating a custom template uses the actor locale for the suffix', function () {
    $user = workspaceMember($this->workspace, 'member', ['locale' => Locale::PortugueseBrazil]);
    $source = PostTemplate::factory()->team()->create(['workspace_id' => $this->workspace->id, 'title' => 'Recap']);

    $this->actingAs($user)->post(route('app.create.templates.duplicate', $source), ['visibility' => 'team'])->assertRedirect();

    expect(PostTemplate::query()->where('id', '!=', $source->id)->sole()->title)->toBe('Recap (cópia)');
});

test('duplicate titles are cut to 120 characters, not width', function () {
    $title = Str::repeat('日', 120);
    $source = PostTemplate::factory()->team()->create(['workspace_id' => $this->workspace->id, 'title' => $title]);

    $this->actingAs($this->alice)->post(route('app.create.templates.duplicate', $source), ['visibility' => 'team'])->assertRedirect();

    $copy = PostTemplate::query()->where('id', '!=', $source->id)->sole();

    expect(mb_strlen($copy->title))->toBe(120)
        ->and($copy->title)->toBe($title);
});

test('update and destroy send the visit back where it came from', function () {
    $template = PostTemplate::factory()->team()->create(['workspace_id' => $this->workspace->id]);
    $from = route('app.create.templates.index', ['view' => 'team']);

    $this->actingAs($this->alice)->from($from)->put(route('app.create.templates.update', $template), templatePayload(['visibility' => 'team']))->assertRedirect($from);
    $this->actingAs($this->alice)->from($from)->delete(route('app.create.templates.destroy', $template))->assertRedirect($from);
});

test('the emoji is limited to 16 characters', function () {
    $this->actingAs($this->alice)->post(route('app.create.templates.store'), templatePayload(['emoji' => Str::repeat('a', 17)]))->assertSessionHasErrors('emoji');
    $this->actingAs($this->alice)->post(route('app.create.templates.store'), templatePayload(['emoji' => Str::repeat('a', 16)]))->assertSessionDoesntHaveErrors();
});
