<?php

declare(strict_types=1);

use App\Ai\Agents\IdeaGenerator;
use App\Ai\Agents\PostWritingAssistant;
use App\Models\Idea;
use App\Models\IdeaStage;
use App\Models\Media;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceLabel;
use Illuminate\Auth\Access\Response as AuthResponse;
use Illuminate\Support\Facades\Gate;
use Laravel\Ai\Exceptions\ProviderOverloadedException;

function waitForCreateIdeaEditorTestId(mixed $page, string $testId, string $text = ''): void
{
    $expected = json_encode($text);

    $page->script(<<<JS
        (async () => {
            for (let attempt = 0; attempt < 200; attempt++) {
                const element = document.querySelector('[data-testid="{$testId}"]');
                if (element?.getBoundingClientRect().height > 0
                    && (element.value ?? element.textContent ?? '').includes({$expected})) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);
}

function waitForCreateIdeaEditorCondition(mixed $page, string $condition): void
{
    $page->script(<<<JS
        (async () => {
            for (let attempt = 0; attempt < 200; attempt++) {
                if ({$condition}) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);
}

function waitForCreateIdeaEditorDatabase(mixed $page, Closure $condition): void
{
    for ($attempt = 0; $attempt < 50 && ! $condition(); $attempt++) {
        $page->script('new Promise((resolve) => setTimeout(resolve, 100))');
    }
}

/**
 * The editor opens as an animated dialog; a click that lands before the
 * animation settles is swallowed.
 */
function waitForCreateIdeaEditorDialog(mixed $page): void
{
    $page->script(<<<'JS'
        (async () => {
            for (let attempt = 0; attempt < 200; attempt++) {
                const dialog = document.querySelector('[data-testid="idea-editor"]');
                if (dialog?.getAttribute('data-state') === 'open'
                    && dialog.getAnimations().every((animation) => animation.playState !== 'running')) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);
}

/**
 * @return array{0: User, 1: Workspace, 2: IdeaStage}
 */
function createIdeaEditorSetup(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    return [$user->fresh(), $workspace, $workspace->ideaStages()->firstOrFail()];
}

function createIdeaEditorIdea(Workspace $workspace, User $user, array $attributes = []): Idea
{
    return Idea::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'idea_stage_id' => null,
        'position' => 0,
        ...$attributes,
    ]);
}

test('a label created from the idea editor is selected and saved with the idea', function () {
    [$user, $workspace] = createIdeaEditorSetup();
    $this->actingAs($user);

    $page = visit(route('app.create.ideas.create'));
    waitForCreateIdeaEditorDialog($page);

    $page->fill('@idea-editor-title', 'Labelled idea')
        ->click('@idea-editor-labels');
    waitForCreateIdeaEditorTestId($page, 'idea-editor-label-empty-create');
    $page->click('@idea-editor-label-empty-create');
    waitForCreateIdeaEditorTestId($page, 'idea-editor-label-new-name');
    $page->fill('@idea-editor-label-new-name', 'Launch')
        ->click('@submit-idea-editor-label-new');

    $label = null;
    waitForCreateIdeaEditorDatabase($page, function () use ($workspace, &$label): bool {
        $label = WorkspaceLabel::where('workspace_id', $workspace->id)->where('name', 'Launch')->first();

        return $label !== null;
    });
    waitForCreateIdeaEditorTestId($page, "idea-editor-label-checkbox-{$label->id}");

    $page->assertScript("document.querySelector('[data-testid=\"idea-editor-label-checkbox-{$label->id}\"]').getAttribute('data-state')", 'checked')
        ->click('@idea-editor-labels')
        ->click('@idea-editor-save');

    waitForCreateIdeaEditorDatabase($page, fn (): bool => Idea::where('title', 'Labelled idea')->exists());
    $idea = Idea::where('title', 'Labelled idea')->sole();

    expect($idea->labels()->pluck('workspace_labels.id')->all())->toBe([$label->id]);
    $page->assertNoJavaScriptErrors();
});

test('a new idea is saved with its stage and label and the editor returns to the board', function () {
    [$user, $workspace, $stage] = createIdeaEditorSetup();
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $workspace->id]);
    $this->actingAs($user);

    $page = visit(route('app.create.ideas.create'));
    waitForCreateIdeaEditorDialog($page);

    $page->assertDisabled('@idea-editor-save')
        ->assertDisabled('@idea-editor-create-post')
        ->fill('@idea-editor-title', 'Spring launch')
        ->assertEnabled('@idea-editor-save')
        ->click('@idea-editor-stage');
    waitForCreateIdeaEditorTestId($page, "idea-editor-stage-option-{$stage->id}");
    $page->click("@idea-editor-stage-option-{$stage->id}")
        ->assertSeeIn('@idea-editor-stage', $stage->name)
        ->click('@idea-editor-labels');
    waitForCreateIdeaEditorTestId($page, "idea-editor-label-option-{$label->id}");
    $page->click("@idea-editor-label-option-{$label->id}")
        ->click('@idea-editor-labels')
        ->click('@idea-editor-save');

    waitForCreateIdeaEditorDatabase($page, fn (): bool => Idea::where('title', 'Spring launch')->exists());
    $idea = Idea::where('title', 'Spring launch')->sole();

    expect($idea->idea_stage_id)->toBe($stage->id)
        ->and($idea->labels()->pluck('workspace_labels.id')->all())->toBe([$label->id]);

    $indexPath = parse_url(route('app.create.ideas.index'), PHP_URL_PATH);
    waitForCreateIdeaEditorCondition($page, "!document.querySelector('[data-testid=\"idea-editor\"]') && location.pathname === '{$indexPath}'");
    waitForCreateIdeaEditorTestId($page, "idea-card-{$idea->id}");

    expect($page->script('location.pathname'))->toBe($indexPath);
    $page->assertMissing('@idea-editor')
        ->assertVisible("@idea-card-{$idea->id}")
        ->assertNoJavaScriptErrors();
});

test('an existing idea opens in the editor and saves its changes', function () {
    [$user, $workspace] = createIdeaEditorSetup();
    $idea = createIdeaEditorIdea($workspace, $user, ['title' => 'Old title', 'body' => 'Old body']);
    $this->actingAs($user);

    $page = visit(route('app.create.ideas.show', $idea));
    waitForCreateIdeaEditorDialog($page);

    $page->assertValue('@idea-editor-title', 'Old title')
        ->assertValue('@idea-editor-body', 'Old body')
        ->fill('@idea-editor-body', 'New body')
        ->click('@idea-editor-save');

    waitForCreateIdeaEditorDatabase($page, fn (): bool => $idea->refresh()->body === 'New body');

    expect($idea->refresh()->body)->toBe('New body')
        ->and($idea->title)->toBe('Old title');
    waitForCreateIdeaEditorCondition($page, "!document.querySelector('[data-testid=\"idea-editor\"]')");
    $page->assertMissing('@idea-editor')
        ->assertNoJavaScriptErrors();
});

/**
 * Intercepts POST XMLHttpRequests whose URL contains `$path`: `hold` keeps them until
 * `window.__releaseIdeaRequests()`, `fail` makes them fail with a network error.
 * `window.__ideaAborted` counts aborted ones.
 */
function interceptCreateIdeaEditorRequests(mixed $page, string $path, string $mode = 'hold'): void
{
    $page->script(<<<JS
        (() => {
            const prototype = XMLHttpRequest.prototype;
            const { open, send, abort } = prototype;
            const matches = (xhr) => xhr.__method === 'POST' && String(xhr.__url ?? '').includes('{$path}');
            window.__ideaRequests = [];
            window.__ideaAborted = 0;
            prototype.open = function (method, url, ...rest) {
                this.__url = url;
                this.__method = String(method).toUpperCase();
                return open.call(this, method, url, ...rest);
            };
            prototype.abort = function () {
                if (matches(this)) window.__ideaAborted++;
                return abort.call(this);
            };
            prototype.send = function (body) {
                if (! matches(this)) return send.call(this, body);
                if ('{$mode}' === 'fail') {
                    setTimeout(() => this.dispatchEvent(new ProgressEvent('error')));
                    return;
                }
                window.__ideaRequests.push(() => send.call(this, body));
            };
            window.__releaseIdeaRequests = () => window.__ideaRequests.splice(0).forEach((release) => release());
        })();
    JS);
}

function holdCreateIdeaEditorUploads(mixed $page, string $mode = 'hold'): void
{
    interceptCreateIdeaEditorRequests($page, route('app.media.store-chunked', absolute: false), $mode);
}

function selectCreateIdeaEditorFile(mixed $page, string $name): void
{
    $base64 = base64_encode((string) file_get_contents(base_path('tests/fixtures/crop-quadrants.png')));

    $page->script(<<<JS
        (() => {
            const bytes = Uint8Array.from(atob('{$base64}'), (character) => character.charCodeAt(0));
            const input = document.querySelector('[data-testid="idea-file-input"]');
            const transfer = new DataTransfer();
            transfer.items.add(new File([bytes], '{$name}', { type: 'image/png' }));
            input.files = transfer.files;
            input.dispatchEvent(new Event('change', { bubbles: true }));
        })();
    JS);
}

test('an uploaded image blocks saving until it is ready, then the idea owns it', function () {
    [$user, $workspace] = createIdeaEditorSetup();
    $this->actingAs($user);

    $page = visit(route('app.create.ideas.create'));
    waitForCreateIdeaEditorDialog($page);
    waitForCreateIdeaEditorTestId($page, 'idea-dropzone');
    holdCreateIdeaEditorUploads($page);
    $page->fill('@idea-editor-title', 'With a picture');
    selectCreateIdeaEditorFile($page, 'idea-photo.png');
    waitForCreateIdeaEditorCondition($page, 'window.__ideaRequests.length === 1');

    $page->assertVisible('@idea-upload-item')
        ->assertDisabled('@idea-editor-save')
        ->assertDisabled('@idea-editor-create-post');

    $page->script('window.__releaseIdeaRequests()');
    waitForCreateIdeaEditorTestId($page, 'idea-media-item');
    $page->assertMissing('@idea-upload-item')
        ->assertEnabled('@idea-editor-save')
        ->click('@idea-editor-save');

    waitForCreateIdeaEditorDatabase($page, fn (): bool => Idea::where('title', 'With a picture')->exists());
    $idea = Idea::where('title', 'With a picture')->sole();
    $owned = $idea->ownedMedia()->sole();

    expect($idea->ownedMedia()->count())->toBe(1)
        ->and(data_get($idea->media, '0.id'))->toBe($owned->id)
        ->and($owned->original_filename)->toBe('idea-photo.png')
        ->and(Media::where('workspace_id', $workspace->id)->count())->toBe(1);

    waitForCreateIdeaEditorCondition($page, "document.querySelector('[data-testid=\"idea-card-{$idea->id}\"] img')?.getBoundingClientRect().height > 0");
    expect($page->script("document.querySelector('[data-testid=\"idea-card-{$idea->id}\"] img').getAttribute('src')"))->toBe($owned->url);

    $page = visit(route('app.create.ideas.show', $idea));
    waitForCreateIdeaEditorDialog($page);
    waitForCreateIdeaEditorTestId($page, 'idea-media-item');
    $page->click('@idea-remove-0')
        ->assertMissing('@idea-media-item')
        ->click('@idea-editor-save');

    waitForCreateIdeaEditorDatabase($page, fn (): bool => $idea->refresh()->media === []);

    expect($idea->refresh()->media)->toBe([])
        ->and(Media::find($owned->id))->toBeNull();
    $page->assertNoJavaScriptErrors();
});

test('a failed upload blocks saving until it is removed', function () {
    [$user] = createIdeaEditorSetup();
    $this->actingAs($user);

    $page = visit(route('app.create.ideas.create'));
    waitForCreateIdeaEditorDialog($page);
    waitForCreateIdeaEditorTestId($page, 'idea-dropzone');
    holdCreateIdeaEditorUploads($page, 'fail');
    $page->fill('@idea-editor-title', 'Failing upload');
    selectCreateIdeaEditorFile($page, 'broken.png');
    waitForCreateIdeaEditorTestId($page, 'idea-upload-error');

    $page->assertVisible('@idea-editor-upload-blocked')
        ->assertDisabled('@idea-editor-save')
        ->assertDisabled('@idea-editor-create-post')
        ->click('@idea-upload-remove-0')
        ->assertMissing('@idea-upload-error')
        ->assertMissing('@idea-editor-upload-blocked')
        ->assertEnabled('@idea-editor-save')
        ->assertNoJavaScriptErrors();
    expect(Idea::where('title', 'Failing upload')->exists())->toBeFalse();
});

test('a media error from the server lands on the tile it belongs to', function () {
    [$user, $workspace] = createIdeaEditorSetup();
    $this->actingAs($user);

    $page = visit(route('app.create.ideas.create'));
    waitForCreateIdeaEditorDialog($page);
    waitForCreateIdeaEditorTestId($page, 'idea-dropzone');
    $page->fill('@idea-editor-title', 'Two pictures');
    selectCreateIdeaEditorFile($page, 'first.png');
    waitForCreateIdeaEditorCondition($page, "document.querySelectorAll('[data-testid=\"idea-media-item\"]').length === 1");
    selectCreateIdeaEditorFile($page, 'second.png');
    waitForCreateIdeaEditorCondition($page, "document.querySelectorAll('[data-testid=\"idea-media-item\"]').length === 2");

    Media::where('workspace_id', $workspace->id)->where('original_filename', 'second.png')->sole()->delete();

    $page->click('@idea-editor-save');
    waitForCreateIdeaEditorTestId($page, 'idea-media-error-1');

    $page->assertVisible('@idea-media-error-1')
        ->assertMissing('@idea-media-error-0')
        ->assertMissing('@idea-editor-error')
        ->assertVisible('@idea-editor')
        ->assertNoJavaScriptErrors();
    expect(Idea::where('title', 'Two pictures')->exists())->toBeFalse();
});

test('closing the editor aborts uploads in flight', function () {
    [$user, $workspace] = createIdeaEditorSetup();
    $this->actingAs($user);

    $page = visit(route('app.create.ideas.create'));
    waitForCreateIdeaEditorDialog($page);
    waitForCreateIdeaEditorTestId($page, 'idea-dropzone');
    holdCreateIdeaEditorUploads($page);
    selectCreateIdeaEditorFile($page, 'pending.png');
    waitForCreateIdeaEditorCondition($page, 'window.__ideaRequests.length === 1');

    $page->click('@idea-editor-cancel');
    waitForCreateIdeaEditorCondition($page, "!document.querySelector('[data-testid=\"idea-editor\"]') && window.__ideaAborted > 0");

    expect($page->script('window.__ideaAborted'))->toBe(1);
    $page->assertMissing('@idea-editor')
        ->assertNoJavaScriptErrors();
    expect(Media::where('workspace_id', $workspace->id)->exists())->toBeFalse();
});

test('the dropzone is disabled while the idea saves', function () {
    [$user] = createIdeaEditorSetup();
    $this->actingAs($user);

    $page = visit(route('app.create.ideas.create'));
    waitForCreateIdeaEditorDialog($page);
    interceptCreateIdeaEditorRequests($page, route('app.create.ideas.store', absolute: false));
    $page->fill('@idea-editor-title', 'Saving slowly')
        ->assertEnabled('@idea-dropzone')
        ->click('@idea-editor-save');
    waitForCreateIdeaEditorCondition($page, 'window.__ideaRequests.length === 1');

    $page->assertDisabled('@idea-dropzone');

    $page->script('window.__releaseIdeaRequests()');
    waitForCreateIdeaEditorCondition($page, "!document.querySelector('[data-testid=\"idea-editor\"]')");
    $page->assertNoJavaScriptErrors();
});

test('the toolbar has no add button and the dropzone opens the file dialog', function () {
    [$user] = createIdeaEditorSetup();
    $this->actingAs($user);

    $page = visit(route('app.create.ideas.create'));
    waitForCreateIdeaEditorDialog($page);
    waitForCreateIdeaEditorTestId($page, 'idea-dropzone');
    $page->script(<<<'JS'
        (() => {
            window.__ideaFileDialogOpened = 0;
            document.querySelector('[data-testid="idea-file-input"]').click = () => { window.__ideaFileDialogOpened++; };
        })();
    JS);
    $page->assertNotPresent('@idea-editor-add-media')
        ->click('@idea-dropzone');

    expect($page->script('window.__ideaFileDialogOpened'))->toBe(1);
    $page->assertMissing('@media-picker-confirm')
        ->assertNoJavaScriptErrors();
});

test('the AI assistant rewrites and extends the body without a channel', function () {
    [$user] = createIdeaEditorSetup();
    PostWritingAssistant::fake(['Sharper text']);
    $this->actingAs($user);

    $page = visit(route('app.create.ideas.create'));
    waitForCreateIdeaEditorDialog($page);
    $page->fill('@idea-editor-body', 'Rough text')
        ->click('@idea-editor-ai');
    waitForCreateIdeaEditorTestId($page, 'writing-assistant-panel');

    $page->assertMissing('@writing-assistant-channel')
        ->assertVisible('@writing-assistant-disclaimer')
        ->click('@writing-assistant-mode-rephrase');
    waitForCreateIdeaEditorTestId($page, 'writing-assistant-replace');
    $page->click('@writing-assistant-replace')
        ->assertValue('@idea-editor-body', 'Sharper text');

    PostWritingAssistant::fake(['New angle']);

    $page->click('@writing-assistant-mode-generate');
    waitForCreateIdeaEditorTestId($page, 'writing-assistant-prompt');
    $page->fill('@writing-assistant-prompt', 'ideas for our spring sale')
        ->click('@writing-assistant-generate');
    waitForCreateIdeaEditorTestId($page, 'writing-assistant-insert');
    $page->click('@writing-assistant-insert')
        ->assertValue('@idea-editor-body', "Sharper text\n\nNew angle")
        ->assertNoJavaScriptErrors();
});

test('create post closes the editor and opens the composer with the idea', function () {
    [$user, $workspace] = createIdeaEditorSetup();
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $workspace->id]);
    $idea = createIdeaEditorIdea($workspace, $user, ['title' => 'Greeting', 'body' => 'Hello']);
    $idea->labels()->attach($label->id);
    $this->actingAs($user);

    $page = visit(route('app.create.ideas.show', $idea));
    waitForCreateIdeaEditorDialog($page);
    $page->click('@idea-editor-create-post');
    waitForCreateIdeaEditorTestId($page, 'composer-base-content', 'Hello');

    $page->assertVisible('@post-composer-dialog')
        ->assertValue('@composer-base-content', 'Hello')
        ->assertSeeIn('@composer-tags-trigger', $label->name)
        ->assertMissing('@idea-editor')
        ->assertNoJavaScriptErrors();
    expect(Idea::whereKey($idea->id)->exists())->toBeTrue();
});

test('generate ideas shows one idea, generates again and opens it unsaved in the new idea editor', function (?array $size) {
    [$user, $workspace] = createIdeaEditorSetup();
    IdeaGenerator::fake([
        ['title' => 'Behind the kiln', 'body' => 'Show the firing process.'],
        ['title' => 'Glaze of the week', 'body' => 'Feature one glaze.'],
    ]);
    $this->actingAs($user);

    $page = visit(route('app.create.ideas.index'));
    if ($size !== null) {
        $page->resize(...$size);
    }
    waitForCreateIdeaEditorTestId($page, 'ideas-generate');
    $page->click('@ideas-generate');
    waitForCreateIdeaEditorTestId($page, 'ideas-generate-dialog');

    $page->assertMissing('@ideas-generate-count')
        ->assertMissing('@ideas-generate-stage')
        ->assertDisabled('@ideas-generate-submit')
        ->fill('@ideas-generate-audience', 'Home decor lovers')
        ->assertDisabled('@ideas-generate-submit')
        ->fill('@ideas-generate-business', 'A handmade ceramics studio')
        ->assertEnabled('@ideas-generate-submit')
        ->click('@ideas-generate-submit');
    waitForCreateIdeaEditorTestId($page, 'ideas-generate-result', 'Behind the kiln');

    $page->assertSeeIn('@ideas-generate-result', 'Behind the kiln')
        ->assertSeeIn('@ideas-generate-result', 'Show the firing process.');
    expect($page->script(<<<'JS'
        (() => {
            const ids = ['ideas-generate-cancel', 'ideas-generate-again', 'ideas-generate-use'];
            const nodes = ids.map((id) => document.querySelector(`[data-testid="${id}"]`));
            return nodes.every(Boolean)
                && Boolean(nodes[0].compareDocumentPosition(nodes[1]) & Node.DOCUMENT_POSITION_FOLLOWING)
                && Boolean(nodes[1].compareDocumentPosition(nodes[2]) & Node.DOCUMENT_POSITION_FOLLOWING)
                && document.documentElement.scrollWidth <= window.innerWidth;
        })()
    JS))->toBeTrue();

    $page->click('@ideas-generate-again');
    waitForCreateIdeaEditorTestId($page, 'ideas-generate-result', 'Glaze of the week');

    $page->assertSeeIn('@ideas-generate-result', 'Feature one glaze.')
        ->assertDontSeeIn('@ideas-generate-result', 'Behind the kiln');
    IdeaGenerator::assertPrompted(fn ($prompt): bool => $prompt->agent->audience === 'Home decor lovers'
        && $prompt->agent->business === 'A handmade ceramics studio');

    $page->click('@ideas-generate-use');
    waitForCreateIdeaEditorDialog($page);
    waitForCreateIdeaEditorTestId($page, 'idea-editor-title', 'Glaze of the week');

    $page->assertMissing('@ideas-generate-dialog')
        ->assertValue('@idea-editor-title', 'Glaze of the week')
        ->assertValue('@idea-editor-body', 'Feature one glaze.')
        ->assertSeeIn('@idea-editor-stage', __('create.ideas.unassigned'));
    expect(Idea::where('workspace_id', $workspace->id)->exists())->toBeFalse();

    $page->click('@idea-editor-save');
    waitForCreateIdeaEditorDatabase($page, fn (): bool => Idea::where('workspace_id', $workspace->id)->exists());

    $idea = Idea::where('workspace_id', $workspace->id)->sole();
    expect($idea->title)->toBe('Glaze of the week')
        ->and($idea->body)->toBe('Feature one glaze.')
        ->and($idea->idea_stage_id)->toBeNull();

    waitForCreateIdeaEditorCondition($page, "!document.querySelector('[data-testid=\"idea-editor\"]')");
    waitForCreateIdeaEditorTestId($page, 'ideas-new');
    $page->click('@ideas-new');
    waitForCreateIdeaEditorDialog($page);

    $page->assertValue('@idea-editor-title', '')
        ->assertValue('@idea-editor-body', '');

    $page->click('@idea-editor-cancel');
    waitForCreateIdeaEditorCondition($page, "!document.querySelector('[data-testid=\"idea-editor\"]')");
    $page->click('@ideas-generate');
    waitForCreateIdeaEditorTestId($page, 'ideas-generate-audience');

    $page->assertValue('@ideas-generate-audience', '')
        ->assertValue('@ideas-generate-business', '')
        ->assertMissing('@ideas-generate-result')
        ->assertDisabled('@ideas-generate-submit')
        ->assertNoJavaScriptErrors();
})->with([
    'desktop' => [null],
]);

test('generate ideas shows the payment required message inline when AI is denied', function () {
    [$user, $workspace] = createIdeaEditorSetup();
    IdeaGenerator::fake();
    Gate::before(fn (User $user, string $ability): ?AuthResponse => $ability === 'useAi'
        ? AuthResponse::deny(__('billing.flash.subscription_required'))
        : null);
    $this->actingAs($user);

    $page = visit(route('app.create.ideas.index'));
    waitForCreateIdeaEditorTestId($page, 'ideas-generate');
    $page->click('@ideas-generate');
    waitForCreateIdeaEditorTestId($page, 'ideas-generate-dialog');
    $page->fill('@ideas-generate-business', 'A handmade ceramics studio')
        ->fill('@ideas-generate-audience', 'Home decor lovers')
        ->click('@ideas-generate-submit');
    waitForCreateIdeaEditorTestId($page, 'ideas-generate-error', __('billing.flash.subscription_required'));

    $page->assertSeeIn('@ideas-generate-error', __('billing.flash.subscription_required'))
        ->assertNoJavaScriptErrors();
    expect(Idea::where('workspace_id', $workspace->id)->exists())->toBeFalse();
    IdeaGenerator::assertNeverPrompted();
});

test('generate ideas shows the failure inline when the provider fails and a retry recovers', function () {
    [$user, $workspace] = createIdeaEditorSetup();
    $attempts = 0;
    IdeaGenerator::fake(function () use (&$attempts): array {
        if (++$attempts === 1) {
            throw new ProviderOverloadedException('overloaded');
        }

        return ['title' => 'Behind the kiln', 'body' => 'Show the firing process.'];
    });
    $this->actingAs($user);

    $page = visit(route('app.create.ideas.index'));
    waitForCreateIdeaEditorTestId($page, 'ideas-generate');
    $page->click('@ideas-generate');
    waitForCreateIdeaEditorTestId($page, 'ideas-generate-dialog');
    $page->fill('@ideas-generate-business', 'A handmade ceramics studio')
        ->fill('@ideas-generate-audience', 'Home decor lovers')
        ->click('@ideas-generate-submit');
    waitForCreateIdeaEditorTestId($page, 'ideas-generate-error', __('create.ideas.errors.generate_failed'));

    $page->assertSeeIn('@ideas-generate-error', __('create.ideas.errors.generate_failed'))
        ->assertMissing('@ideas-generate-result')
        ->assertEnabled('@ideas-generate-submit')
        ->click('@ideas-generate-submit');
    waitForCreateIdeaEditorTestId($page, 'ideas-generate-result', 'Behind the kiln');

    waitForCreateIdeaEditorCondition($page, "!document.querySelector('[data-testid=\"ideas-generate-error\"]')");

    $page->assertSeeIn('@ideas-generate-result', 'Show the firing process.')
        ->assertMissing('@ideas-generate-error')
        ->assertNoJavaScriptErrors();
    expect(Idea::where('workspace_id', $workspace->id)->exists())->toBeFalse();
});

test('the editor footer renders cancel, then create post, then save', function () {
    [$user] = createIdeaEditorSetup();
    $this->actingAs($user);

    $page = visit(route('app.create.ideas.create'));
    waitForCreateIdeaEditorDialog($page);

    expect($page->script(<<<'JS'
        (() => {
            const [cancel, createPost, save] = ['idea-editor-cancel', 'idea-editor-create-post', 'idea-editor-save']
                .map((id) => document.querySelector(`[data-testid="${id}"]`));

            return Boolean(cancel.compareDocumentPosition(createPost) & Node.DOCUMENT_POSITION_FOLLOWING)
                && Boolean(createPost.compareDocumentPosition(save) & Node.DOCUMENT_POSITION_FOLLOWING);
        })()
    JS))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

function recordCreateIdeaEditorInertiaRequests(mixed $page): void
{
    $page->script(<<<'JS'
        (() => {
            window.__inertiaRequests = [];
            const setHeader = XMLHttpRequest.prototype.setRequestHeader;
            const send = XMLHttpRequest.prototype.send;
            XMLHttpRequest.prototype.setRequestHeader = function (name, value) {
                this.__headers = { ...(this.__headers ?? {}), [name.toLowerCase()]: value };
                return setHeader.call(this, name, value);
            };
            XMLHttpRequest.prototype.send = function (body) {
                if (this.__headers?.['x-inertia']) {
                    window.__inertiaRequests.push(this.__headers['x-inertia-partial-data'] ?? 'full');
                }
                return send.call(this, body);
            };
        })();
    JS);
}

test('opening and closing an idea on the board reloads only the editor', function () {
    [$user, $workspace] = createIdeaEditorSetup();
    $idea = createIdeaEditorIdea($workspace, $user, ['title' => 'Board idea']);
    $this->actingAs($user);

    $page = visit(route('app.create.ideas.index'));
    waitForCreateIdeaEditorTestId($page, "idea-card-{$idea->id}");
    recordCreateIdeaEditorInertiaRequests($page);

    $page->click("@idea-card-{$idea->id}");
    waitForCreateIdeaEditorDialog($page);
    $page->click('@idea-editor-cancel');
    waitForCreateIdeaEditorCondition($page, "!document.querySelector('[data-testid=\"idea-editor\"]') && window.__inertiaRequests.length >= 2");

    expect($page->script('window.__inertiaRequests'))->toBe(['editor', 'editor']);
    $page->assertMissing('@idea-editor')
        ->assertNoJavaScriptErrors();
});

test('saving an idea reloads the board with the change', function () {
    [$user, $workspace] = createIdeaEditorSetup();
    $idea = createIdeaEditorIdea($workspace, $user, ['title' => 'Before']);
    $this->actingAs($user);

    $page = visit(route('app.create.ideas.index'));
    waitForCreateIdeaEditorTestId($page, "idea-card-{$idea->id}");
    $page->click("@idea-card-{$idea->id}");
    waitForCreateIdeaEditorDialog($page);
    $page->fill('@idea-editor-title', 'After')
        ->click('@idea-editor-save');

    waitForCreateIdeaEditorCondition($page, "!document.querySelector('[data-testid=\"idea-editor\"]')");
    waitForCreateIdeaEditorTestId($page, "idea-card-{$idea->id}", 'After');

    $page->assertSeeIn("@idea-card-{$idea->id}", 'After')
        ->assertNoJavaScriptErrors();
});

test('opening and closing an idea in the gallery keeps the loaded pages', function () {
    [$user, $workspace] = createIdeaEditorSetup();
    $size = (int) config('app.pagination.default');
    $ideas = collect(range(1, $size + 5))->map(fn (int $i): Idea => createIdeaEditorIdea($workspace, $user, [
        'title' => "Gallery idea {$i}",
        'created_at' => now()->subMinutes($i),
    ]));
    $this->actingAs($user);

    $page = visit(route('app.create.ideas.index', ['view' => 'gallery']));
    waitForCreateIdeaEditorTestId($page, "idea-card-{$ideas->first()->id}");
    $page->script("document.querySelector('[data-testid=\"ideas-gallery\"]').scrollTo(0, 1e6)");
    waitForCreateIdeaEditorCondition($page, "document.querySelectorAll('[data-testid^=\"idea-card-menu-\"]').length > {$size}");

    expect($page->script("document.querySelectorAll('[data-testid^=\"idea-card-menu-\"]').length"))->toBe($size + 5);

    $last = $ideas->last();
    $page->script("document.querySelector('[data-testid=\"idea-card-{$last->id}\"]').click()");
    waitForCreateIdeaEditorDialog($page);
    $page->assertValue('@idea-editor-title', $last->title)
        ->click('@idea-editor-cancel');
    waitForCreateIdeaEditorCondition($page, "!document.querySelector('[data-testid=\"idea-editor\"]')");

    expect($page->script("document.querySelectorAll('[data-testid^=\"idea-card-menu-\"]').length"))->toBe($size + 5);
    $page->assertNoJavaScriptErrors();
});

test('editing an idea image swaps in the edited file and releases the original on save', function () {
    [$user, $workspace] = createIdeaEditorSetup();
    $this->actingAs($user);

    $page = visit(route('app.create.ideas.create'));
    waitForCreateIdeaEditorDialog($page);
    waitForCreateIdeaEditorTestId($page, 'idea-dropzone');
    $page->fill('@idea-editor-title', 'Edited picture');
    selectCreateIdeaEditorFile($page, 'idea-photo.png');
    waitForCreateIdeaEditorTestId($page, 'idea-media-item');
    $page->click('@idea-editor-save');
    waitForCreateIdeaEditorDatabase($page, fn (): bool => Idea::where('title', 'Edited picture')->exists());
    $idea = Idea::where('title', 'Edited picture')->sole();
    $original = $idea->ownedMedia()->sole();

    $page = visit(route('app.create.ideas.show', $idea));
    waitForCreateIdeaEditorDialog($page);
    $fixture = base64_encode((string) file_get_contents(base_path('tests/fixtures/crop-quadrants.png')));
    $page->script(<<<JS
        (() => {
            const fixture = 'data:image/png;base64,{$fixture}';
            const property = Object.getOwnPropertyDescriptor(HTMLImageElement.prototype, 'src');
            Object.defineProperty(HTMLImageElement.prototype, 'src', {
                configurable: true,
                get() { return property.get.call(this); },
                set(value) { return property.set.call(this, String(value).includes('/storage/') ? fixture : value); },
            });
        })();
    JS);
    waitForCreateIdeaEditorTestId($page, 'idea-edit-0');
    $page->assertMissing('@idea-alt-0')
        ->assertMissing('@idea-tag-0')
        ->click('@idea-edit-0');
    waitForCreateIdeaEditorCondition($page, "document.querySelector('[data-testid=\"media-editor-selection\"]')?.getBoundingClientRect().width > 0");
    $page->assertMissing('@media-editor-segments')
        ->click('@media-editor-rotate-right')
        ->click('@media-editor-apply');
    waitForCreateIdeaEditorCondition($page, "!document.querySelector('[data-testid=\"media-editor\"]') && document.querySelector('[data-testid=\"idea-editor-save\"]')?.disabled === false");
    $page->click('@idea-editor-save');

    waitForCreateIdeaEditorDatabase($page, fn (): bool => data_get($idea->refresh()->media, '0.id') !== $original->id);
    $owned = $idea->refresh()->ownedMedia()->sole();

    expect($owned->id)->not->toBe($original->id)
        ->and($owned->path)->not->toBe($original->path)
        ->and($owned->upload_token)->toBeNull()
        ->and(data_get($idea->media, '0.id'))->toBe($owned->id)
        ->and(Media::find($original->id))->toBeNull();
    $page->assertNoJavaScriptErrors();
});

test('the assistant buttons only open the AI sidebar; it closes from its X', function () {
    [$user, $workspace] = createIdeaEditorSetup();
    $idea = createIdeaEditorIdea($workspace, $user, ['title' => 'Saas metrics', 'body' => '']);
    $this->actingAs($user);

    $page = visit(route('app.create.ideas.show', $idea));
    waitForCreateIdeaEditorDialog($page);

    foreach (['idea-editor-ai', 'idea-editor-use-assistant'] as $button) {
        $page->click("@{$button}");
        waitForCreateIdeaEditorTestId($page, 'idea-editor-assistant');
        $page->assertAttribute("@{$button}", 'aria-pressed', 'true')
            ->click("@{$button}");
        $page->script('new Promise((resolve) => setTimeout(resolve, 300))');

        $page->assertVisible('@idea-editor-assistant');

        expect($page->script(<<<'JS'
            (() => {
                const editor = document.querySelector('[data-testid="idea-editor"]').getBoundingClientRect();
                const assistant = document.querySelector('[data-testid="idea-editor-assistant"]').getBoundingClientRect();
                const close = document.querySelector('[data-testid="idea-editor-assistant-close"]').getBoundingClientRect();
                const dialogClose = document.querySelector('[data-testid="idea-editor"] [data-slot="dialog-close"]').getBoundingClientRect();
                return {
                    onTheLeft: Math.round(assistant.left) === Math.round(editor.left),
                    closesApart: close.right <= dialogClose.left,
                };
            })()
        JS))->toBe(['onTheLeft' => true, 'closesApart' => true]);

        $page->click('@idea-editor-assistant-close');
        waitForCreateIdeaEditorCondition($page, '!document.querySelector(\'[data-testid="idea-editor-assistant"]\')');

        $page->assertMissing('@idea-editor-assistant')
            ->assertAttribute("@{$button}", 'aria-pressed', 'false');
    }

    $page->assertNoJavaScriptErrors();
});

test('on a phone the title and close share the first row and the stage and labels triggers share the second', function (string $locale) {
    [$user] = createIdeaEditorSetup();
    $user->update(['locale' => $locale]);
    $this->actingAs($user->fresh());

    $page = visit(route('app.create.ideas.create'))->resize(390, 844);
    waitForCreateIdeaEditorDialog($page);

    expect($page->script(<<<'JS'
        (() => {
            const rect = (id) => document.querySelector(`[data-testid="${id}"]`).getBoundingClientRect();
            const dialog = rect('idea-editor');
            const stage = rect('idea-editor-stage');
            const labels = rect('idea-editor-labels');
            const title = document.querySelector('[data-testid="idea-editor"] [data-slot="dialog-title"]').getBoundingClientRect();
            const close = document.querySelector('[data-testid="idea-editor"] [data-slot="dialog-close"]').getBoundingClientRect();
            return {
                titleAndCloseShareRow: Math.abs((title.top + title.bottom) / 2 - (close.top + close.bottom) / 2) < 12,
                pickersShareRow: Math.abs(stage.top - labels.top) < 2,
                pickersBelowTitle: stage.top >= title.bottom,
                noOverflow: labels.right <= dialog.right && stage.left >= dialog.left && stage.right <= labels.left,
            };
        })()
    JS))->toBe(['titleAndCloseShareRow' => true, 'pickersShareRow' => true, 'pickersBelowTitle' => true, 'noOverflow' => true]);

    $page->assertNoJavaScriptErrors();
})->with(['de']);

test('the generate ideas dialog keeps its footer on screen at 390px in the form and result states', function () {
    [$user] = createIdeaEditorSetup();
    IdeaGenerator::fake([
        ['title' => str_repeat('A very long idea title ', 6), 'body' => str_repeat('Show the whole firing process from raw clay to glazed piece. ', 30)],
    ]);
    $this->actingAs($user);

    $footerOnScreen = <<<'JS'
        (() => {
            const footer = document.querySelector('[data-testid="ideas-generate-cancel"]').parentElement.getBoundingClientRect();

            return footer.top >= 0
                && footer.bottom <= window.innerHeight
                && document.documentElement.scrollWidth <= window.innerWidth;
        })()
    JS;

    $page = visit(route('app.create.ideas.index'))->resize(390, 844);
    waitForCreateIdeaEditorTestId($page, 'ideas-generate');
    $page->click('@ideas-generate');
    waitForCreateIdeaEditorTestId($page, 'ideas-generate-dialog');
    waitForCreateIdeaEditorTestId($page, 'ideas-generate-submit');

    expect($page->script("document.querySelector('[data-testid=\"ideas-generate-dialog\"]').getAttribute('role') === 'dialog' && document.querySelector('[data-testid=\"ideas-generate-cancel\"]').compareDocumentPosition(document.querySelector('[data-testid=\"ideas-generate-submit\"]')) & Node.DOCUMENT_POSITION_FOLLOWING ? true : false"))->toBeTrue();

    expect($page->script($footerOnScreen))->toBeTrue();

    $page->fill('@ideas-generate-audience', 'Home decor lovers')
        ->fill('@ideas-generate-business', 'A handmade ceramics studio')
        ->click('@ideas-generate-submit');
    waitForCreateIdeaEditorTestId($page, 'ideas-generate-result', 'A very long idea title');

    expect($page->script($footerOnScreen))->toBeTrue()
        ->and($page->script("document.querySelector('[data-testid=\"ideas-generate-use\"]').getBoundingClientRect().bottom <= window.innerHeight"))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});
