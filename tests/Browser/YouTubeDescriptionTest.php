<?php

declare(strict_types=1);

use App\Enums\Post\Status;
use App\Enums\UserWorkspace\Role;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\Route;

function seedYouTubeDescriptionEditor(): array
{
    app(Vite::class)->useHotFile(storage_path('framework/testing-youtube.hot'));
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, ['role' => Role::Member->value]);
    $user->update(['current_workspace_id' => $workspace->id]);
    Route::get('/__tests/youtube-description-video', fn () => response()->file(base_path('tests/fixtures/sample.mp4')));
    $post = Post::factory()->create([
        'workspace_id' => $workspace->id, 'user_id' => $user->id, 'content' => 'Short title', 'status' => Status::Draft,
        'media' => [[
            'id' => 'youtube-video', 'type' => 'video', 'path' => 'medias/video.mp4', 'url' => url('/__tests/youtube-description-video'),
            'mime_type' => 'video/mp4', 'original_filename' => 'video.mp4', 'size' => filesize(base_path('tests/fixtures/sample.mp4')),
        ]],
    ]);
    $platforms = collect(range(1, 2))->map(function (int $number) use ($workspace, $post) {
        $account = SocialAccount::factory()->youtube()->create(['workspace_id' => $workspace->id, 'username' => "ytchannel{$number}"]);

        return PostPlatform::factory()->youtube()->create(['post_id' => $post->id, 'social_account_id' => $account->id, 'enabled' => true, 'meta' => ['description' => "Channel {$number}"]]);
    });
    test()->actingAs($user);

    return [$post, $platforms];
}

function waitForYouTubeElement(mixed $page, string $testId): void
{
    $page->script(<<<JS
        (async () => {
            for (let i = 0; i < 100; i++) {
                const el = document.querySelector('[data-testid="{$testId}"]');
                if (el && el.getBoundingClientRect().height > 0) return;
                await new Promise(resolve => setTimeout(resolve, 50));
            }
        })();
    JS);
}

function trackYouTubeAutosave(mixed $page): void
{
    $page->script(<<<'JS'
        (() => {
            window.__youtubeAutosaveDone = false;
            const open = XMLHttpRequest.prototype.open;
            const send = XMLHttpRequest.prototype.send;
            XMLHttpRequest.prototype.open = function (method) {
                this.__youtubeMethod = (method || '').toUpperCase();
                return open.apply(this, arguments);
            };
            XMLHttpRequest.prototype.send = function () {
                this.addEventListener('loadend', () => {
                    if (this.__youtubeMethod === 'PUT') window.__youtubeAutosaveDone = true;
                });
                return send.apply(this, arguments);
            };
        })();
    JS);
}

function waitForYouTubeAutosave(mixed $page): void
{
    $page->script(<<<'JS'
        (async () => {
            for (let i = 0; i < 100 && !window.__youtubeAutosaveDone; i++) {
                await new Promise(resolve => setTimeout(resolve, 100));
            }
        })();
    JS);
    expect($page->script('window.__youtubeAutosaveDone'))->toBeTrue();
}

function assertYouTubePublishState(mixed $page, bool $disabled): void
{
    $label = json_encode(__('posts.edit.post_now'), JSON_THROW_ON_ERROR);
    $want = $disabled ? 'true' : 'false';
    $state = $page->script(<<<JS
        (async () => {
            for (let i = 0; i < 100; i++) {
                const button = Array.from(document.querySelectorAll('button')).find(el =>
                    el.textContent.trim() === {$label} && el.getBoundingClientRect().height > 0);
                if (button && button.disabled === {$want}) return true;
                await new Promise(resolve => setTimeout(resolve, 50));
            }
            return false;
        })();
    JS);
    expect($state)->toBeTrue();
}

test('youtube description editor counts bytes saves independent channels and previews', function (int $width, int $height) {
    [$post, $platforms] = seedYouTubeDescriptionEditor();
    $page = visit(route('app.posts.edit', $post))->resize($width, $height);
    if ($width < 1024) {
        waitForYouTubeElement($page, 'editor-nav-channels');
        $page->click('@editor-nav-channels');
    }
    waitForYouTubeElement($page, 'youtube-settings-toggle-0');
    $page->click('@youtube-settings-toggle-0')->click('@youtube-settings-toggle-1');
    waitForYouTubeElement($page, 'youtube-description-0');
    $initial = $page->script('document.querySelector("[data-testid=youtube-description-0]").value');
    $first = $platforms->first(fn (PostPlatform $row): bool => $row->meta['description'] === $initial);
    $second = $platforms->first(fn (PostPlatform $row): bool => $row->id !== $first->id);
    assertYouTubePublishState($page, false);
    $page->fill('@youtube-description-0', str_repeat('é', 2500));
    assertYouTubePublishState($page, false);
    $page->assertSee('5000 / 5000 bytes')->assertNoJavaScriptErrors();
    $page->assertAttributeMissing('@youtube-description-0', 'aria-invalid');
    $page->fill('@youtube-description-0', str_repeat('😀', 1250));
    assertYouTubePublishState($page, false);
    $page->assertSee('5000 / 5000 bytes')->assertAttributeMissing('@youtube-description-0', 'aria-invalid');
    foreach ([str_repeat('é', 2500).'a', str_repeat('😀', 1250).'a'] as $invalid) {
        $page->fill('@youtube-description-0', $invalid);
        assertYouTubePublishState($page, true);
        $page->assertAttribute('@youtube-description-0', 'aria-invalid', 'true');
    }
    $page->click('@channel-'.$first->id);
    assertYouTubePublishState($page, false);
    $page->click('@channel-'.$first->id);
    assertYouTubePublishState($page, true);
    waitForYouTubeElement($page, 'youtube-settings-toggle-0');
    $page->click('@youtube-settings-toggle-0');
    waitForYouTubeElement($page, 'youtube-description-0');
    trackYouTubeAutosave($page);
    $description = "First channel description 😀 ação\nhttps://example.com\nif (a < b && c > d) {}\n<p>Text about HTML</p>";
    $page->fill('@youtube-description-0', "  {$description}  ")
        ->fill('@youtube-description-1', 'Second channel description');
    $page->assertValue('@youtube-description-0', "  {$description}  ");
    assertYouTubePublishState($page, false);
    $page->assertAttributeMissing('@youtube-description-0', 'aria-invalid');
    waitForYouTubeAutosave($page);
    $page->screenshot(filename: 'youtube-description-settings-'.$width);
    expect(data_get($first->fresh()->meta, 'description'))->toBe($description)
        ->and(data_get($second->fresh()->meta, 'description'))->toBe('Second channel description')
        ->and($post->fresh()->content)->toBe('Short title');
    $page->click($width < 1024 ? '@editor-nav-preview' : '@editor-tab-preview');
    waitForYouTubeElement($page, 'preview-platform-'.$first->id);
    $page->click('@preview-platform-'.$first->id)->click('details > summary');
    $page->assertSeeIn('@youtube-preview-description', 'if (a < b && c > d) {}')
        ->assertSeeIn('@youtube-preview-description', '<p>Text about HTML</p>');
    expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
    $page->assertNoJavaScriptErrors();
    $page = visit(route('app.posts.edit', $post))->resize($width, $height);
    if ($width < 1024) {
        waitForYouTubeElement($page, 'editor-nav-channels');
        $page->click('@editor-nav-channels');
    }
    waitForYouTubeElement($page, 'youtube-settings-toggle-0');
    $page->click('@youtube-settings-toggle-0')->click('@youtube-settings-toggle-1');
    $page->assertValue('@youtube-description-0', $description)
        ->assertValue('@youtube-description-1', 'Second channel description');
})->with([[1280, 900], [375, 812]]);

test('youtube description clearing restores content fallback without changing another channel', function (string $description) {
    [$post, $platforms] = seedYouTubeDescriptionEditor();
    $page = visit(route('app.posts.edit', $post))->resize(375, 812);
    waitForYouTubeElement($page, 'editor-nav-channels');
    $page->click('@editor-nav-channels');
    waitForYouTubeElement($page, 'youtube-settings-toggle-0');
    $page->click('@youtube-settings-toggle-0');
    waitForYouTubeElement($page, 'youtube-description-0');
    $initial = $page->script('document.querySelector("[data-testid=youtube-description-0]").value');
    $first = $platforms->first(fn (PostPlatform $row): bool => $row->meta['description'] === $initial);
    $second = $platforms->first(fn (PostPlatform $row): bool => $row->id !== $first->id);
    trackYouTubeAutosave($page);
    $page->fill('@youtube-description-0', $description);
    waitForYouTubeAutosave($page);
    expect(data_get($first->fresh()->meta, 'description'))->toBeNull()
        ->and($second->fresh()->meta)->toEqual($second->meta);
    $page->click('@editor-nav-preview');
    waitForYouTubeElement($page, 'preview-platform-'.$first->id);
    $page->click('@preview-platform-'.$first->id)->click('details > summary');
    $page->assertSeeIn('@youtube-preview-description', 'Short title')->assertNoJavaScriptErrors();
})->with([
    'empty description' => [''],
    'whitespace description' => [" \t\n\u{00A0}"],
]);

test('youtube description long preview stays above the phone navigation', function (int $width, int $height) {
    [$post, $platforms] = seedYouTubeDescriptionEditor();
    $platform = $platforms->first();
    $description = "Full description\n".str_repeat('https://example.com/'.str_repeat('a', 100)."\n", 35);
    $platform->update(['meta' => ['description' => $description]]);
    $page = visit(route('app.posts.edit', $post))->resize($width, $height);
    if ($width < 1024) {
        waitForYouTubeElement($page, 'editor-nav-preview');
    } else {
        waitForYouTubeElement($page, 'editor-tab-preview');
    }
    $page->click($width < 1024 ? '@editor-nav-preview' : '@editor-tab-preview');
    waitForYouTubeElement($page, 'preview-platform-'.$platform->id);
    $page->click('@preview-platform-'.$platform->id)->click('details > summary');
    $page->assertSeeIn('@youtube-preview-description', 'Full description');
    $layout = $page->script(<<<'JS'
        (() => {
            const description = document.querySelector('[data-testid=youtube-preview-description]');
            const navigation = description.parentElement.parentElement.parentElement.lastElementChild;
            return {
                scrolls: description.scrollHeight > description.clientHeight,
                bounded: description.clientHeight <= 128,
                aboveNavigation: description.getBoundingClientRect().bottom <= navigation.getBoundingClientRect().top,
                noOverflow: document.documentElement.scrollWidth <= window.innerWidth,
            };
        })();
    JS);
    expect($layout)->toEqual(['scrolls' => true, 'bounded' => true, 'aboveNavigation' => true, 'noOverflow' => true]);
    $page->assertNoJavaScriptErrors()->screenshot(filename: 'youtube-description-preview-'.$width);
})->with([[1280, 900], [375, 812]]);
