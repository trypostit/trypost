<?php

declare(strict_types=1);

use Amp\DeferredFuture;
use Amp\TimeoutCancellation;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Enums\User\Locale;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Pest\Browser\Execution;

beforeEach(function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
});

/**
 * @param  array<int, array<string, mixed>>  $media
 * @return array{0: Post, 1: SocialAccount}
 */
function seedLinkCardPreviewPost(
    Platform $platform,
    string $content = 'Draft without a link',
    array $media = [],
): array {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'user_id' => $user->id,
        'account_id' => $user->account_id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('member'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    $account = SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => $platform,
        'scopes' => $platform->requiredPublishScopes(),
    ]);

    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'content' => $content,
        'media' => $media,
    ]);

    PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => $platform,
        'enabled' => true,
        'content_type' => match ($platform) {
            Platform::Facebook => ContentType::FacebookPost,
            Platform::LinkedIn => ContentType::LinkedInPost,
            Platform::Mastodon => ContentType::MastodonPost,
            Platform::Bluesky => ContentType::BlueskyPost,
            Platform::Threads => ContentType::ThreadsPost,
            default => throw new LogicException('Unsupported link-card browser test platform.'),
        },
    ]);

    test()->actingAs($user);

    return [$post, $account];
}

function waitForLinkCardPreviewTestId(mixed $page, string $testId): void
{
    $page->script(<<<JS
        (async () => {
            for (let attempt = 0; attempt < 300; attempt++) {
                const dialog = document.querySelector('[data-testid="post-composer-dialog"]');
                if (dialog?.getAttribute('data-state') === 'open'
                    && dialog.getAnimations().every((animation) => animation.playState !== 'running')
                    && document.querySelector('[data-testid="{$testId}"]')?.getBoundingClientRect().height > 0) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);
}

function waitForLinkCardPreviewText(mixed $page, string $text): void
{
    $encoded = json_encode($text);

    $page->script(<<<JS
        (async () => {
            for (let attempt = 0; attempt < 300; attempt++) {
                if (document.querySelector('[data-testid="link-card-title"]')?.textContent.includes({$encoded})) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);
}

function waitForLinkCardPreviewGone(mixed $page): void
{
    $page->script(<<<'JS'
        (async () => {
            for (let attempt = 0; attempt < 300; attempt++) {
                if (!document.querySelector('[data-testid="link-card"]')) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);
}

test('facebook linkedin and mastodon previews render fetched link cards', function (Platform $platform) {
    $url = "https://93.184.216.34/{$platform->value}";
    Http::fake([$url => Http::response('<meta property="og:title" content="Article card">')]);

    [$post] = seedLinkCardPreviewPost($platform, "Read {$url}");
    $previewRequests = 0;
    Event::listen(RequestHandled::class, function (RequestHandled $event) use (&$previewRequests): void {
        if ($event->request->routeIs('app.posts.link-preview')) {
            $previewRequests++;
        }
    });

    $page = visit(route('app.posts.edit', $post));
    waitForLinkCardPreviewTestId($page, 'composer-preview-frame');
    waitForLinkCardPreviewText($page, 'Article card');

    $page->assertSeeIn('@link-card-title', 'Article card')
        ->assertPresent('@link-card')
        ->assertNoJavaScriptErrors();

    expect($previewRequests)->toBe(1);
    Http::assertSentCount(1);
})->with([
    'Facebook' => Platform::Facebook,
    'LinkedIn' => Platform::LinkedIn,
    'Mastodon' => Platform::Mastodon,
]);

test('removing a url removes its visible card', function () {
    Http::fake(['https://93.184.216.34/*' => Http::response('<meta property="og:title" content="Article card">')]);
    [$post, $account] = seedLinkCardPreviewPost(Platform::LinkedIn, 'https://93.184.216.34/article');

    $page = visit(route('app.posts.edit', $post));
    waitForLinkCardPreviewTestId($page, "composer-caption-{$account->id}");
    waitForLinkCardPreviewText($page, 'Article card');

    $page->assertSeeIn('@link-card-title', 'Article card')
        ->clear("@composer-caption-{$account->id}");
    waitForLinkCardPreviewGone($page);

    $page->assertMissing('@link-card')
        ->assertNoJavaScriptErrors();
});

test('a failed fetch clears the previous card and a later url can recover', function () {
    Http::fake([
        'https://93.184.216.34/article' => Http::response('<meta property="og:title" content="Article card">'),
        'https://93.184.216.34/broken' => Http::response('', 500),
        'https://93.184.216.34/recovered' => Http::response('<meta property="og:title" content="Recovered card">'),
    ]);
    [$post, $account] = seedLinkCardPreviewPost(Platform::LinkedIn, 'https://93.184.216.34/article');

    $page = visit(route('app.posts.edit', $post));
    waitForLinkCardPreviewTestId($page, "composer-caption-{$account->id}");
    waitForLinkCardPreviewText($page, 'Article card');

    $page->assertSeeIn('@link-card-title', 'Article card')
        ->fill("@composer-caption-{$account->id}", 'https://93.184.216.34/broken');

    Execution::instance()->waitForExpectation(fn () => Http::assertSentCount(2));
    waitForLinkCardPreviewGone($page);

    $page->assertMissing('@link-card')
        ->fill("@composer-caption-{$account->id}", 'https://93.184.216.34/recovered');
    waitForLinkCardPreviewText($page, 'Recovered card');

    $page->assertSeeIn('@link-card-title', 'Recovered card')
        ->assertNoJavaScriptErrors();
});

test('a pending response cannot overwrite a newer card or revive a removed url', function (bool $removeUrl) {
    $release = new DeferredFuture;
    $started = false;
    Http::fake(function ($request) use ($release, &$started) {
        if ($request->url() === 'https://93.184.216.34/slow') {
            $started = true;
            $release->getFuture()->await(new TimeoutCancellation(20));

            return Http::response('<meta property="og:title" content="Stale card">');
        }

        return Http::response('<meta property="og:title" content="Current card">');
    });

    try {
        [$post, $account] = seedLinkCardPreviewPost(Platform::LinkedIn, 'https://93.184.216.34/slow');
        $page = visit(route('app.posts.edit', $post));
        waitForLinkCardPreviewTestId($page, "composer-caption-{$account->id}");

        Execution::instance()->waitForExpectation(function () use (&$started) {
            expect($started)->toBeTrue();
        });

        $page->fill("@composer-caption-{$account->id}", $removeUrl ? '' : 'https://93.184.216.34/current');

        if (! $removeUrl) {
            waitForLinkCardPreviewText($page, 'Current card');
            $page->assertSeeIn('@link-card-title', 'Current card');
        }
    } finally {
        if (! $release->isComplete()) {
            $release->complete();
        }
    }

    $page->page()->waitForLoadState('networkidle');
    $page->assertDontSee('Stale card');

    if ($removeUrl) {
        $page->assertMissing('@link-card');
    } else {
        $page->assertSeeIn('@link-card-title', 'Current card');
    }

    $page->assertNoJavaScriptErrors();
})->with(['newer url' => false, 'removed url' => true]);

test('attached media suppresses fetching until it is removed', function () {
    Http::fake(['https://93.184.216.34/*' => Http::response('<meta property="og:title" content="Article card">')]);
    [$post, $account] = seedLinkCardPreviewPost(Platform::LinkedIn, 'https://93.184.216.34/article', [[
        'id' => 'image-1',
        'type' => 'image',
        'mime_type' => 'image/png',
        'path' => 'uploads/image.png',
        'url' => 'data:image/png;base64,'.base64_encode(file_get_contents(__DIR__.'/../fixtures/1x1.png')),
        'size' => 68,
    ]]);

    $page = visit(route('app.posts.edit', $post));
    waitForLinkCardPreviewTestId($page, "composer-{$account->id}-media-item");

    $page->assertPresent("@composer-{$account->id}-media-item")
        ->assertMissing('@link-card');

    Http::assertNothingSent();

    $page->click("@composer-{$account->id}-remove-0");
    waitForLinkCardPreviewText($page, 'Article card');

    $page->assertSeeIn('@link-card-title', 'Article card')
        ->assertNoJavaScriptErrors();

    Http::assertSentCount(1);
});

function waitForLinkCardPreviewMissing(mixed $page, string $testId): void
{
    $page->script(<<<JS
        (async () => {
            for (let attempt = 0; attempt < 300; attempt++) {
                if (!document.querySelector('[data-testid="{$testId}"]')) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);
}

test('the editor link card can be dropped and the post saves without it', function (Platform $platform) {
    $url = 'https://93.184.216.34/article';
    Http::fake([$url => Http::response('<meta property="og:title" content="Article card"><meta property="og:description" content="A great read">')]);
    [$post, $account] = seedLinkCardPreviewPost($platform, "Read {$url}");
    $card = "composer-link-card-{$account->id}";

    $page = visit(route('app.posts.edit', $post));
    waitForLinkCardPreviewTestId($page, $card);

    $page->assertSeeIn("@{$card}-title", 'Article card')
        ->assertPresent("@{$card}-replace")
        ->click("@{$card}-remove");
    waitForLinkCardPreviewMissing($page, $card);

    $page->assertMissing("@{$card}")
        ->assertMissing('@link-card')
        ->assertNoJavaScriptErrors()
        ->click('@composer-save-draft')
        ->assertMissing('@post-composer-dialog');

    expect($post->postPlatforms()->sole()->meta)->toEqual(['link_preview' => false]);
})->with([
    'Facebook' => Platform::Facebook,
    'Bluesky' => Platform::Bluesky,
    'LinkedIn' => Platform::LinkedIn,
]);

test('the threads link card cannot be dropped because threads always shows it', function () {
    $url = 'https://93.184.216.34/article';
    Http::fake([$url => Http::response('<meta property="og:title" content="Article card">')]);
    [$post, $account] = seedLinkCardPreviewPost(Platform::Threads, "Read {$url}");
    $card = "composer-link-card-{$account->id}";

    $page = visit(route('app.posts.edit', $post));
    waitForLinkCardPreviewTestId($page, $card);

    $page->assertPresent("@{$card}-replace")
        ->assertMissing("@{$card}-remove")
        ->assertNoJavaScriptErrors();
});

test('replacing the link card with media attaches the card image', function () {
    $url = 'https://93.184.216.34/article';
    $fake = UploadedFile::fake()->image('card.jpg', 1200, 630);
    $image = file_get_contents($fake->getPathname());
    Http::fake([
        $url => Http::response('<meta property="og:title" content="Article card"><meta property="og:image" content="https://93.184.216.34/card.jpg">'),
        'https://93.184.216.34/card.jpg' => Http::response($image, 200, ['Content-Type' => 'image/jpeg']),
    ]);
    [$post, $account] = seedLinkCardPreviewPost(Platform::Facebook, "Read {$url}");
    $card = "composer-link-card-{$account->id}";

    $page = visit(route('app.posts.edit', $post));
    waitForLinkCardPreviewTestId($page, $card);

    $page->click("@{$card}-replace");
    waitForLinkCardPreviewTestId($page, "composer-{$account->id}-media-item-0");

    $page->assertMissing("@{$card}")
        ->assertNoJavaScriptErrors()
        ->click('@composer-save-draft')
        ->assertMissing('@post-composer-dialog');

    $media = Media::query()->sole();

    expect($media->post_id)->toBe($post->id)
        ->and($post->fresh()->media)->toHaveCount(1)
        ->and(data_get($post->fresh()->media, '0.id'))->toBe($media->id);
});

test('the replace link preview button stays on one line in every language', function () {
    $url = 'https://93.184.216.34/article';
    Http::fake([$url => Http::response('<meta property="og:title" content="Article card">')]);
    [$post, $account] = seedLinkCardPreviewPost(Platform::Facebook, "Read {$url}");
    $user = auth()->user();

    foreach (Locale::cases() as $locale) {
        $user->update(['locale' => $locale]);
        $page = visit(route('app.posts.edit', $post))->resize(1280, 900);
        waitForLinkCardPreviewTestId($page, "composer-link-card-{$account->id}-replace");

        $wraps = $page->script(<<<JS
            (() => {
                const label = document.querySelector('[data-testid="composer-link-card-{$account->id}-replace"] [data-single-line]');
                const lineHeight = parseFloat(getComputedStyle(label).lineHeight) || 20;
                return label.getBoundingClientRect().height > lineHeight * 1.5;
            })()
        JS);

        expect($wraps)->toBeFalse("{$locale->value} wraps");
        $page->assertNoJavaScriptErrors();
    }
});

test('the shared step editor shows no link card and the network card does', function () {
    $url = 'https://93.184.216.34/article';
    Http::fake([$url => Http::response('<meta property="og:title" content="Article card">')]);
    [, $facebook] = seedLinkCardPreviewPost(Platform::Facebook);
    $linkedin = SocialAccount::factory()->create([
        'workspace_id' => $facebook->workspace_id,
        'platform' => Platform::LinkedIn,
        'scopes' => Platform::LinkedIn->requiredPublishScopes(),
    ]);

    $page = visit(route('app.posts.create'));
    waitForLinkCardPreviewTestId($page, 'composer-add-account');

    $page->fill('@composer-base-content', "Read {$url}")
        ->click('@composer-add-account')
        ->click("@composer-account-option-{$facebook->id}")
        ->click("@composer-account-option-{$linkedin->id}")
        ->click("@composer-account-{$facebook->id}");
    waitForLinkCardPreviewText($page, 'Article card');

    $page->assertPresent('@composer-next')
        ->assertPresent('@link-card')
        ->assertMissing("@composer-link-card-{$facebook->id}")
        ->assertMissing("@composer-link-card-{$linkedin->id}")
        ->click('@composer-next');
    waitForLinkCardPreviewTestId($page, "composer-link-card-{$facebook->id}");

    $page->assertSeeIn("@composer-link-card-{$facebook->id}-title", 'Article card')
        ->assertNoJavaScriptErrors();
});

test('a dropped link card comes back when the link changes', function () {
    $url = 'https://93.184.216.34/article';
    $other = 'https://93.184.216.34/other';
    Http::fake([
        $url => Http::response('<meta property="og:title" content="Article card">'),
        $other => Http::response('<meta property="og:title" content="Other card">'),
    ]);
    [$post, $account] = seedLinkCardPreviewPost(Platform::Facebook, "Read {$url}");
    $card = "composer-link-card-{$account->id}";

    $page = visit(route('app.posts.edit', $post));
    waitForLinkCardPreviewTestId($page, $card);

    $page->click("@{$card}-remove");
    waitForLinkCardPreviewMissing($page, $card);

    $page->fill("@composer-caption-{$account->id}", "Read {$other}");
    waitForLinkCardPreviewTestId($page, $card);

    $page->assertSeeIn("@{$card}-title", 'Other card')
        ->assertNoJavaScriptErrors()
        ->click('@composer-save-draft')
        ->assertMissing('@post-composer-dialog');

    expect(data_get($post->postPlatforms()->sole()->meta, 'link_preview'))->toBeNull();
});
