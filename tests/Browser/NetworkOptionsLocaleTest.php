<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Enums\User\Locale;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

function waitForNetworkOptionsTestId(mixed $page, string $testId): void
{
    $page->script(<<<JS
        (async () => {
            for (let i = 0; i < 100; i++) {
                if (document.querySelector('[data-testid="{$testId}"]')?.getBoundingClientRect().height > 0) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);

    waitForWebFonts($page);
}

/**
 * @return array<string, string>
 */
function networkOptionsEnglishKeysByText(): array
{
    $keys = [];

    foreach (glob(lang_path('en/*.php')) ?: [] as $file) {
        $group = basename($file, '.php');

        foreach (Arr::dot(require $file) as $key => $value) {
            if (is_string($value)) {
                $keys[$value] ??= "{$group}.{$key}";
            }
        }
    }

    return $keys;
}

test('network card labels stay on one line in every language', function (Platform $platform, ContentType $contentType, string $readyTestId, array $meta) {
    Http::fake(['*' => Http::response([], 200)]);

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('member'));
    $user->update(['current_workspace_id' => $workspace->id]);
    $account = SocialAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => $platform, 'token_expires_at' => now()->addDays(20)]);
    $post = Post::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'content' => 'Options']);
    PostPlatform::factory()->create(['post_id' => $post->id, 'social_account_id' => $account->id, 'platform' => $platform, 'content_type' => $contentType, 'meta' => $meta]);
    $this->actingAs($user);

    $page = visit(route('app.posts.edit', $post))->resize(1280, 900);
    waitForNetworkOptionsTestId($page, $readyTestId);

    $texts = $page->script("[...document.querySelectorAll('[data-single-line]')].map((el) => el.textContent.trim())");
    expect($texts)->not->toBeEmpty();

    $keysByText = networkOptionsEnglishKeysByText();
    $translations = [];

    foreach ($texts as $index => $text) {
        $key = $keysByText[$text] ?? null;

        if ($key === null) {
            continue;
        }

        foreach (Locale::cases() as $locale) {
            $translations[$index][$locale->value] = __($key, [], $locale->value);
        }
    }

    expect($translations)->not->toBeEmpty();

    $json = json_encode($translations, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT);

    $wrapped = $page->script(<<<JS
        (() => {
            const translations = {$json};
            const elements = [...document.querySelectorAll('[data-single-line]')];
            const failures = [];

            for (const [index, byLocale] of Object.entries(translations)) {
                const el = elements[index];
                const original = el.textContent;
                const lineHeight = parseFloat(getComputedStyle(el).lineHeight) || 20;

                for (const [locale, text] of Object.entries(byLocale)) {
                    el.textContent = text;
                    if (el.getBoundingClientRect().height > lineHeight * 1.5) failures.push(locale + ': ' + text);
                }

                el.textContent = original;
            }

            return failures;
        })()
    JS);

    expect($wrapped)->toBe([]);
    $page->assertNoJavaScriptErrors();
})->with([
    'instagram types' => [Platform::Instagram, ContentType::InstagramFeed, 'composer-customization', []],
    'facebook types' => [Platform::Facebook, ContentType::FacebookPost, 'composer-customization', []],
    'google business types' => [Platform::GoogleBusiness, ContentType::GoogleBusinessPost, 'google-business-topic-STANDARD', []],
    'google business offer' => [Platform::GoogleBusiness, ContentType::GoogleBusinessPost, 'google-business-add-details', ['topic_type' => 'OFFER']],
    'google business event' => [Platform::GoogleBusiness, ContentType::GoogleBusinessPost, 'google-business-start-time', ['topic_type' => 'EVENT', 'event' => ['start_time' => '09:00']]],
    'instagram reel' => [Platform::Instagram, ContentType::InstagramReel, 'instagram-share-to-feed', []],
    'youtube' => [Platform::YouTube, ContentType::YouTubeShort, 'youtube-title', []],
    'mastodon' => [Platform::Mastodon, ContentType::MastodonPost, 'mastodon-content-warning', []],
    'threads' => [Platform::Threads, ContentType::ThreadsPost, 'threads-topic-tag', []],
]);
