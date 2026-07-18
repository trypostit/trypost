<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;

/**
 * Flag when the composer's debounced autosave PUT finishes so the test can wait
 * on the real round-trip instead of a fixed sleep. Inertia v3 issues the update
 * over the built-in XHR client, so the completion is observable via loadend.
 */
function trackAutosave(mixed $page): void
{
    $page->script(<<<'JS'
        (() => {
            window.__autosaveDone = false;
            const open = XMLHttpRequest.prototype.open;
            const send = XMLHttpRequest.prototype.send;
            XMLHttpRequest.prototype.open = function (method) {
                this.__method = (method || '').toUpperCase();
                return open.apply(this, arguments);
            };
            XMLHttpRequest.prototype.send = function () {
                this.addEventListener('loadend', () => {
                    if (this.__method === 'PUT') {
                        window.__autosaveDone = true;
                    }
                });
                return send.apply(this, arguments);
            };
        })();
    JS);
}

/**
 * Poll on the browser side until the autosave PUT has completed, keeping the
 * in-process server pumped while the debounce (1.5s) elapses and the request
 * round-trips.
 */
function waitForAutosave(mixed $page): void
{
    $page->script(<<<'JS'
        (async () => {
            for (let attempt = 0; attempt < 100 && ! window.__autosaveDone; attempt++) {
                await new Promise((resolve) => setTimeout(resolve, 100));
            }
        })();
    JS);
}

/**
 * Poll on the browser side until the lightbox alt-text caption has mounted and
 * laid out. The lightbox is a Radix dialog with an open animation, so the
 * element is not present the instant the thumbnail is clicked; the harness
 * assertions do not auto-wait, so we wait here first.
 */
function waitForLightboxAltText(mixed $page): void
{
    $page->script(<<<'JS'
        (async () => {
            const visible = () => {
                const el = document.querySelector('[data-testid="lightbox-alt-text"]');
                if (! el) return false;
                const rect = el.getBoundingClientRect();
                return rect.width > 0 && rect.height > 0;
            };
            for (let attempt = 0; attempt < 100 && ! visible(); attempt++) {
                await new Promise((resolve) => setTimeout(resolve, 100));
            }
        })();
    JS);
}

/**
 * Poll on the browser side until the alt-text Save button reaches the desired
 * disabled state. The `:disabled` binding is driven by a Vue computed that
 * flushes on nextTick, and these Pest browser assertions do not auto-wait, so
 * we settle the reactive state here before asserting.
 */
function waitForSaveButton(mixed $page, bool $disabled): void
{
    $want = $disabled ? 'true' : 'false';

    $page->script(<<<JS
        (async () => {
            for (let attempt = 0; attempt < 100; attempt++) {
                const el = document.querySelector('[data-testid="alt-text-save"]');
                if (el && el.disabled === {$want}) {
                    return;
                }
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);
}

test('editing alt text on an attached image persists it to the post media', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, ['role' => Role::Member->value]);
    $user->update(['current_workspace_id' => $workspace->id]);

    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'content' => 'hello',
        'media' => [[
            'id' => 'm1',
            'type' => 'image',
            'mime_type' => 'image/png',
            'path' => 'uploads/x.png',
            'url' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
        ]],
    ]);

    $this->actingAs($user);

    $page = visit(route('app.posts.edit', $post));

    trackAutosave($page);

    $page->click('@alt-text-button')
        ->type('@alt-text-input', 'a golden retriever on a beach')
        ->click('@alt-text-save');

    waitForAutosave($page);

    expect($post->fresh()->media[0]['meta']['alt_text'])
        ->toBe('a golden retriever on a beach');

    $page->click('@media-thumbnail');

    waitForLightboxAltText($page);

    $page->assertSeeIn('@lightbox-alt-text', 'a golden retriever on a beach');
});

test('lightbox shows no alt overlay for a non-image even when meta carries alt text', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, ['role' => Role::Member->value]);
    $user->update(['current_workspace_id' => $workspace->id]);

    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'content' => 'hello',
        'media' => [[
            'id' => 'v1',
            'type' => 'video',
            'mime_type' => 'video/mp4',
            'path' => 'uploads/clip.mp4',
            'url' => 'https://cdn.test/clip.mp4',
            'meta' => ['alt_text' => 'alt that must never overlay a video'],
        ]],
    ]);

    $this->actingAs($user);

    $page = visit(route('app.posts.edit', $post));

    $page->click('@media-thumbnail');

    $page->script(<<<'JS'
        (async () => {
            for (let attempt = 0; attempt < 100; attempt++) {
                if (document.querySelector('[data-testid="lightbox-video"]')) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);

    $page->assertPresent('@lightbox-video')
        ->assertMissing('@lightbox-alt-text');
});

test('alt text dialog blocks saving when the description exceeds the limit', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, ['role' => Role::Member->value]);
    $user->update(['current_workspace_id' => $workspace->id]);

    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'content' => 'hello',
        'media' => [[
            'id' => 'm1',
            'type' => 'image',
            'mime_type' => 'image/png',
            'path' => 'uploads/x.png',
            'url' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
        ]],
    ]);

    $this->actingAs($user);

    $page = visit(route('app.posts.edit', $post));

    $page->click('@alt-text-button')
        ->fill('@alt-text-input', str_repeat('a', 2001));
    waitForSaveButton($page, disabled: true);
    $page->assertDisabled('@alt-text-save')
        ->fill('@alt-text-input', 'a short and valid description');
    waitForSaveButton($page, disabled: false);
    $page->assertEnabled('@alt-text-save');

    $page->fill('@alt-text-input', str_repeat('a', 1999).str_repeat(' ', 100));
    waitForSaveButton($page, disabled: false);
    $page->assertEnabled('@alt-text-save');
});

test('alt text dialog counts emoji by code point, not UTF-16 units', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, ['role' => Role::Member->value]);
    $user->update(['current_workspace_id' => $workspace->id]);

    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'content' => 'hello',
        'media' => [[
            'id' => 'm1',
            'type' => 'image',
            'mime_type' => 'image/png',
            'path' => 'uploads/x.png',
            'url' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
        ]],
    ]);

    $this->actingAs($user);

    $page = visit(route('app.posts.edit', $post));

    $page->click('@alt-text-button')
        ->fill('@alt-text-input', str_repeat('😀', 1500));
    waitForSaveButton($page, disabled: false);
    $page->assertEnabled('@alt-text-save');
});
