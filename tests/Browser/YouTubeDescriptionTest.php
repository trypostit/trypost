<?php

declare(strict_types=1);

use App\Dto\MediaItem;
use App\Enums\Post\Status;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Storage;

afterEach(function () {
    Storage::delete(Media::query()->pluck('path')->all());
});

function seedYouTubeDescriptionEditor(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('member'));
    $user->update(['current_workspace_id' => $workspace->id]);
    $asset = Media::factory()->video()->temporaryUpload($workspace)->create([
        'size' => filesize(base_path('tests/fixtures/sample.mp4')),
    ]);
    Storage::put($asset->path, (string) file_get_contents(base_path('tests/fixtures/sample.mp4')));

    $platforms = collect(range(1, 2))->map(function (int $number) use ($workspace, $user, $asset) {
        $account = SocialAccount::factory()->youtube()->create([
            'workspace_id' => $workspace->id,
            'username' => "ytchannel{$number}",
        ]);
        $post = Post::factory()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'content' => 'Short title',
            'status' => Status::Draft,
            'media' => [MediaItem::fromMedia($asset)->toArray()],
        ]);

        return PostPlatform::factory()->youtube()->create([
            'post_id' => $post->id,
            'social_account_id' => $account->id,
            'enabled' => true,
            'meta' => ['description' => "Channel {$number}"],
        ]);
    });
    test()->actingAs($user);

    return [$platforms[0]->post, $platforms];
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

test('youtube description editor counts UTF-8 bytes and saves only its independent post', function (int $width, int $height) {
    [$post, $platforms] = seedYouTubeDescriptionEditor();
    $page = visit(route('app.posts.edit', $post))->resize($width, $height);
    waitForYouTubeElement($page, 'youtube-description-0');
    $page->assertValue('@youtube-description-0', 'Channel 1');

    $page->fill('@youtube-description-0', str_repeat('é', 2500))
        ->assertAttributeMissing('@youtube-description-0', 'aria-invalid');
    $page->fill('@youtube-description-0', str_repeat('é', 2501))
        ->assertAttribute('@youtube-description-0', 'aria-invalid', 'true');

    $description = "First channel description 😀 ação\nhttps://example.com\nif (a < b && c > d) {}";
    $page->fill('@youtube-description-0', $description)
        ->assertAttributeMissing('@youtube-description-0', 'aria-invalid');

    if ($width < 1024) {
        $page->click('@composer-preview-toggle');
    }
    $page->click('details > summary')
        ->assertSeeIn('@youtube-preview-description', 'if (a < b && c > d) {}')
        ->assertNoJavaScriptErrors();
    if ($width < 1024) {
        $page->click('@composer-mobile-compose');
    }

    $page->click('@composer-save-draft')->assertMissing('@post-composer-dialog');
    expect(data_get($platforms[0]->fresh()->meta, 'description'))->toBe($description)
        ->and(data_get($platforms[1]->fresh()->meta, 'description'))->toBe('Channel 2')
        ->and($post->fresh()->content)->toBe('Short title');
})->with([[1280, 900], [375, 812]]);

test('youtube description clearing restores content fallback without changing another post', function (string $description) {
    [$post, $platforms] = seedYouTubeDescriptionEditor();
    $page = visit(route('app.posts.edit', $post))->resize(375, 812);
    waitForYouTubeElement($page, 'youtube-description-0');
    $page->fill('@youtube-description-0', $description)
        ->click('@composer-save-draft')
        ->assertMissing('@post-composer-dialog');

    expect(data_get($platforms[0]->fresh()->meta, 'description'))->toBeNull()
        ->and(data_get($platforms[1]->fresh()->meta, 'description'))->toBe('Channel 2');

    $page = visit(route('app.posts.edit', $post))->resize(375, 812);
    $page->click('@composer-preview-toggle')
        ->click('details > summary')
        ->assertSeeIn('@youtube-preview-description', 'Short title')
        ->assertNoJavaScriptErrors();
})->with([
    'empty description' => [''],
    'whitespace description' => [" \t\n\u{00A0}"],
]);

test('youtube description long preview stays inside the preview card', function (int $width, int $height) {
    [$post, $platforms] = seedYouTubeDescriptionEditor();
    $description = "Full description\n".str_repeat('https://example.com/'.str_repeat('a', 100)."\n", 35);
    $platforms[0]->update(['meta' => ['description' => $description]]);
    $page = visit(route('app.posts.edit', $post))->resize($width, $height);
    if ($width < 1024) {
        $page->click('@composer-preview-toggle');
    }
    $page->click('details > summary')->assertSeeIn('@youtube-preview-description', 'Full description');

    $layout = $page->script(<<<'JS'
        (() => {
            const description = document.querySelector('[data-testid=youtube-preview-description]');
            const card = description.closest('[data-testid=youtube-preview]').getBoundingClientRect();
            const rect = description.getBoundingClientRect();
            return {
                scrolls: description.scrollHeight > description.clientHeight,
                bounded: description.clientHeight <= 128,
                insideCard: rect.top >= card.top && rect.bottom <= card.bottom,
                noOverflow: document.documentElement.scrollWidth <= window.innerWidth,
            };
        })();
    JS);
    expect($layout)->toEqual(['scrolls' => true, 'bounded' => true, 'insideCard' => true, 'noOverflow' => true]);
    $page->assertNoJavaScriptErrors();
})->with([[1280, 900], [375, 812]]);
