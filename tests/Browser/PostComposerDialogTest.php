<?php

declare(strict_types=1);

use App\Dto\MediaItem;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Enums\UserWorkspace\Role;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceLabel;

test('creating a four account draft keeps composition in the browser until save', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'user_id' => $user->id,
        'account_id' => $user->account_id,
    ]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    $accounts = SocialAccount::factory()->count(4)->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::LinkedIn,
    ]);

    $this->actingAs($user);

    $page = visit(route('app.posts.create'));
    $page->assertVisible('@post-composer-dialog');
    expect(Post::where('workspace_id', $workspace->id)->count())->toBe(0);

    foreach ($accounts as $account) {
        $page->click("@composer-account-{$account->id}");
    }

    $page->fill('@composer-base-content', 'A shared announcement')
        ->click('@composer-next')
        ->assertVisible('@composer-customization');

    expect(Post::where('workspace_id', $workspace->id)->count())->toBe(0);

    $page->click('@composer-save-draft');
    $page->script(<<<'JS'
        (async () => {
            for (let attempt = 0; attempt < 80; attempt++) {
                if (!document.querySelector('[data-testid="post-composer-dialog"]')) return;
                await new Promise((resolve) => setTimeout(resolve, 100));
            }
        })();
    JS);

    expect(Post::where('workspace_id', $workspace->id)->count())->toBe(4);
    expect(Post::where('workspace_id', $workspace->id)->pluck('content')->all())
        ->each->toBe('A shared announcement');
});

test('recovering an empty-target draft retains its caption media and labels', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $account = SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    $asset = Media::factory()->assets()->for($workspace, 'mediable')->create();
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $workspace->id]);
    $legacy = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'status' => PostStatus::Draft,
        'content' => 'Keep this draft',
        'media' => [MediaItem::fromMedia($asset)->toArray()],
    ]);
    $legacy->labels()->attach($label);
    $this->actingAs($user);

    $page = visit(route('app.posts.edit', $legacy));
    $page->assertVisible('@post-composer-dialog')
        ->assertValue('@composer-base-content', 'Keep this draft')
        ->click("@composer-account-{$account->id}")
        ->click('@composer-next')
        ->click('@composer-save-draft');
    $page->script(<<<'JS'
        (async () => {
            for (let attempt = 0; attempt < 80; attempt++) {
                if (!document.querySelector('[data-testid="post-composer-dialog"]')) return;
                await new Promise((resolve) => setTimeout(resolve, 100));
            }
        })();
    JS);

    $recovered = Post::where('workspace_id', $workspace->id)->sole();
    expect($recovered->id)->not->toBe($legacy->id)
        ->and($recovered->content)->toBe('Keep this draft')
        ->and(data_get($recovered->media, '0.id'))->toBe($asset->id)
        ->and($recovered->labels()->sole()->id)->toBe($label->id)
        ->and($recovered->postPlatforms()->sole()->social_account_id)->toBe($account->id);
});

test('a comment deep link and AI editing tools remain available in the edit dialog', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $account = SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'status' => PostStatus::Draft,
        'content' => 'A draft to discuss',
    ]);
    PostPlatform::factory()->create(['post_id' => $post->id, 'social_account_id' => $account->id]);
    $comment = PostComment::factory()->create([
        'post_id' => $post->id,
        'user_id' => $user->id,
        'body' => 'Please review the opening line',
    ]);
    $this->actingAs($user);

    $page = visit(route('app.posts.edit', ['post' => $post, 'tab' => 'comments', 'comment' => $comment->id]));
    $page->assertVisible('@post-composer-dialog')
        ->assertVisible('@composer-comments-panel')
        ->assertSee('Please review the opening line')
        ->click('@composer-back-to-post')
        ->click('@composer-ai-generate')
        ->assertVisible('@composer-ai-generate-dialog');
});

test('account overrides inherit later shared edits and are discarded when an account is removed', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $accounts = SocialAccount::factory()->count(2)->create(['workspace_id' => $workspace->id, 'platform' => Platform::LinkedIn]);
    $firstId = $accounts[0]->id;
    $secondId = $accounts[1]->id;
    $this->actingAs($user);

    $page = visit(route('app.posts.create'));
    $page->click("@composer-account-{$firstId}")
        ->click("@composer-account-{$secondId}")
        ->fill('@composer-base-content', 'Shared first')
        ->click('@composer-next')
        ->fill("@composer-caption-{$firstId}", '')
        ->click('@composer-back')
        ->fill('@composer-base-content', 'Shared second')
        ->click('@composer-next');

    $firstCaption = $page->script("document.querySelector('[data-testid=\"composer-caption-{$firstId}\"]').value");
    $page->click("@composer-expand-{$secondId}");
    $secondCaption = $page->script("document.querySelector('[data-testid=\"composer-caption-{$secondId}\"]').value");
    expect($firstCaption)->toBe('')
        ->and($secondCaption)->toBe('Shared second');

    $page->click('@composer-back')
        ->click("@composer-account-{$firstId}")
        ->click("@composer-account-{$firstId}")
        ->click('@composer-next')
        ->click("@composer-expand-{$firstId}");

    expect($page->script("document.querySelector('[data-testid=\"composer-caption-{$firstId}\"]').value"))
        ->toBe('Shared second');
});

test('X character count uses the same link defusing as its preview', function () {
    config()->set('trypost.platforms.x.defuse_links', true);
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $account = SocialAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::X]);
    $this->actingAs($user);

    $page = visit(route('app.posts.create'));
    $page->click("@composer-account-{$account->id}")
        ->fill('@composer-base-content', 'https://example.com/post')
        ->click('@composer-next')
        ->assertVisible('@composer-x-count');

    expect(trim((string) $page->script("document.querySelector('[data-testid=\"composer-x-count\"]').textContent")))
        ->toBe((string) mb_strlen('example(.)com/post'));
    $page->assertSee('example(.)com/post');
});

test('selecting a new image uploads an asset before any post is saved', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $account = SocialAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::LinkedIn]);
    $this->actingAs($user);

    $page = visit(route('app.posts.create'));
    $page->click("@composer-account-{$account->id}")
        ->click('@composer-base-media');

    $base64 = base64_encode((string) file_get_contents(base_path('tests/fixtures/crop-quadrants.png')));
    $page->script(<<<JS
        (async () => {
            const input = document.querySelector('input[type="file"]');
            const bytes = Uint8Array.from(atob('{$base64}'), (character) => character.charCodeAt(0));
            const transfer = new DataTransfer();
            transfer.items.add(new File([bytes], 'new-composer-image.png', { type: 'image/png' }));
            input.files = transfer.files;
            input.dispatchEvent(new Event('change', { bubbles: true }));
            for (let attempt = 0; attempt < 100; attempt++) {
                if (document.querySelector('img[alt="new-composer-image.png"]')) return;
                await new Promise((resolve) => setTimeout(resolve, 100));
            }
        })();
    JS);

    expect(Media::where('mediable_id', $workspace->id)->where('collection', 'assets')->count())->toBe(1)
        ->and(Post::where('workspace_id', $workspace->id)->count())->toBe(0);
});

test('cropping one account creates a separate asset and leaves the other account image intact', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $accounts = SocialAccount::factory()->count(2)->create(['workspace_id' => $workspace->id, 'platform' => Platform::LinkedIn]);
    $this->actingAs($user);

    $page = visit(route('app.posts.create'));
    foreach ($accounts as $account) {
        $page->click("@composer-account-{$account->id}");
    }
    $page->fill('@composer-base-content', 'Image for both')
        ->click('@composer-base-media');

    $base64 = base64_encode((string) file_get_contents(base_path('tests/fixtures/crop-quadrants.png')));
    $page->script(<<<JS
        (async () => {
            const input = document.querySelector('input[type="file"]');
            const bytes = Uint8Array.from(atob('{$base64}'), (character) => character.charCodeAt(0));
            const transfer = new DataTransfer();
            transfer.items.add(new File([bytes], 'crop-for-channel.png', { type: 'image/png' }));
            input.files = transfer.files;
            input.dispatchEvent(new Event('change', { bubbles: true }));
            for (let attempt = 0; attempt < 100; attempt++) {
                if (document.querySelector('img[alt="crop-for-channel.png"]')) return;
                await new Promise((resolve) => setTimeout(resolve, 100));
            }
        })();
    JS);

    $originalId = Media::where('mediable_id', $workspace->id)->where('collection', 'assets')->sole()->id;
    $page->click('img[alt="crop-for-channel.png"]')
        ->click('@media-picker-confirm')
        ->click('@composer-next');

    // The browser test uses Laravel's private local disk, whose unsigned /storage
    // URL cannot be served. Give the crop canvas the uploaded fixture bytes.
    $page->script(<<<JS
        (() => {
            const fixture = 'data:image/png;base64,{$base64}';
            const property = Object.getOwnPropertyDescriptor(HTMLImageElement.prototype, 'src');
            Object.defineProperty(HTMLImageElement.prototype, 'src', {
                configurable: true,
                get() { return property.get.call(this); },
                set(value) { return property.set.call(this, value.includes('/storage/') ? fixture : value); },
            });
        })();
    JS);

    $page->click("@composer-crop-{$accounts[0]->id}-0")
        ->click('@crop-save');

    $page->script(<<<'JS'
        (async () => {
            for (let attempt = 0; attempt < 100; attempt++) {
                const save = document.querySelector('[data-testid="composer-save-draft"]');
                if (save && !save.disabled && !document.querySelector('[data-testid="crop-save"]')) return;
                await new Promise((resolve) => setTimeout(resolve, 100));
            }
        })();
    JS);
    $page->click('@composer-save-draft');

    $posts = Post::where('workspace_id', $workspace->id)->with('postPlatforms')->get()->keyBy(
        fn (Post $post) => $post->postPlatforms->sole()->social_account_id,
    );
    expect($posts)->toHaveCount(2)
        ->and($posts[$accounts[0]->id]->media[0]['id'])->not->toBe($originalId)
        ->and($posts[$accounts[1]->id]->media[0]['id'])->toBe($originalId);
});

test('animated GIFs and videos do not offer the static image crop action', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $account = SocialAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::X]);
    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'media' => [
            ['id' => fake()->uuid(), 'url' => '/storage/animation.gif', 'path' => 'animation.gif', 'type' => 'image', 'mime_type' => 'image/gif'],
            ['id' => fake()->uuid(), 'url' => '/storage/video.mp4', 'path' => 'video.mp4', 'type' => 'video', 'mime_type' => 'video/mp4'],
        ],
    ]);
    PostPlatform::factory()->create(['post_id' => $post->id, 'social_account_id' => $account->id, 'platform' => Platform::X, 'enabled' => true]);
    $this->actingAs($user);

    visit(route('app.posts.edit', $post))
        ->assertVisible('@composer-customization')
        ->assertMissing("@composer-crop-{$account->id}-0")
        ->assertMissing("@composer-crop-{$account->id}-1");
});

test('failed crop upload keeps the original asset selected', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $account = SocialAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::X]);
    $asset = Media::factory()->assets()->for($workspace, 'mediable')->create(['mime_type' => 'image/png', 'original_filename' => 'original.png']);
    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'content' => 'Keep the original',
        'media' => [['id' => $asset->id, 'path' => $asset->path, 'url' => $asset->url, 'type' => 'image', 'mime_type' => 'image/png', 'original_filename' => 'original.png']],
    ]);
    PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => Platform::X,
        'content_type' => ContentType::XPost,
        'enabled' => true,
    ]);
    $this->actingAs($user);
    $page = visit(route('app.posts.edit', $post));
    $base64 = base64_encode((string) file_get_contents(base_path('tests/fixtures/crop-quadrants.png')));
    $page->script(<<<JS
        (() => {
            const fixture = 'data:image/png;base64,{$base64}';
            const property = Object.getOwnPropertyDescriptor(HTMLImageElement.prototype, 'src');
            Object.defineProperty(HTMLImageElement.prototype, 'src', {
                configurable: true,
                get() { return property.get.call(this); },
                set(value) { return property.set.call(this, value.includes('/storage/') ? fixture : value); },
            });
            const originalFetch = window.fetch;
            window.fetch = (...args) => {
                if (String(args[0]).includes('/assets/chunked')) {
                    window.__cropUploadFailed = true;
                    return Promise.resolve(new Response('failed', { status: 500 }));
                }
                return originalFetch(...args);
            };
        })();
    JS);

    $page->click("@composer-crop-{$account->id}-0")
        ->click('@crop-save');
    $page->script(<<<'JS'
        (async () => {
            for (let attempt = 0; attempt < 100; attempt++) {
                if (window.__cropUploadFailed && document.querySelector('[data-testid="composer-save-draft"]')?.disabled === false) return;
                await new Promise((resolve) => setTimeout(resolve, 100));
            }
        })();
    JS);
    expect($page->script('Boolean(window.__cropUploadFailed)'))->toBeTrue();
    $page->click('@composer-save-draft');

    expect($post->fresh()->media[0]['id'])->toBe($asset->id)
        ->and(Media::where('mediable_id', $workspace->id)->where('collection', 'assets')->count())->toBe(1);
});

test('settled legacy multi-target history appears as one read-only card per target', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $post = Post::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'status' => PostStatus::Published]);
    $accounts = SocialAccount::factory()->count(2)->create(['workspace_id' => $workspace->id, 'platform' => Platform::LinkedIn]);
    foreach ($accounts as $account) {
        PostPlatform::factory()->published()->create(['post_id' => $post->id, 'social_account_id' => $account->id, 'platform' => Platform::LinkedIn, 'enabled' => true]);
    }
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    $page->script(<<<'JS'
        (async () => {
            for (let attempt = 0; attempt < 80; attempt++) {
                if (document.querySelectorAll('[data-testid^="post-card-"]').length === 2) return;
                await new Promise((resolve) => setTimeout(resolve, 100));
            }
        })();
    JS);
    expect($page->script('document.querySelectorAll("[data-testid^=post-card-]").length'))->toBe(2)
        ->and(Post::where('workspace_id', $workspace->id)->count())->toBe(1);
});
