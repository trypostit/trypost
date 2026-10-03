<?php

declare(strict_types=1);

use App\Ai\Agents\PostContentShortener;
use App\Enums\SocialAccount\Platform;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Repurpose\CaptionAdapter;
use App\Services\Social\ContentSanitizer;
use Laravel\Ai\Prompts\AgentPrompt;

test('a caption that fits is returned untouched', function () {
    $workspace = Workspace::factory()->create();
    $caption = 'Short and sweet';

    expect(app(CaptionAdapter::class)->adapt($workspace, null, $caption, Platform::TikTok))
        ->toBe($caption);
});

test('a caption that does not fit is truncated at a word boundary when ai is unavailable', function () {
    $workspace = Workspace::factory()->create();
    $caption = str_repeat('palavra ', 2000);

    expect(Platform::TikTok->contentOverflow($caption))->toBeGreaterThan(0);

    $result = app(CaptionAdapter::class)->adapt($workspace, null, $caption, Platform::TikTok);

    expect(Platform::TikTok->contentOverflow($result))->toBe(0)
        ->and($result)->not->toEndWith('palavr')
        ->and($result)->toEndWith('palavra');
});

test('truncation respects the tightest limit we support', function () {
    $workspace = Workspace::factory()->create();
    $caption = 'A really long YouTube Short caption that keeps going well past one hundred characters so it has to be cut somewhere sensible.';

    $result = app(CaptionAdapter::class)->adapt($workspace, null, $caption, Platform::YouTube);

    expect(Platform::YouTube->contentOverflow($result))->toBe(0)
        ->and($result)->toStartWith('A really long YouTube Short caption');
});

test('ai shortens the caption', function () {
    config()->set('trypost.self_hosted', true);
    PostContentShortener::fake(['A tight caption that fits.']);

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['account_id' => $user->account_id, 'user_id' => $user->id]);

    $result = app(CaptionAdapter::class)->adapt($workspace, $user, str_repeat('palavra ', 2000), Platform::YouTube);

    expect($result)->toBe('A tight caption that fits.');
    PostContentShortener::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->prompt === str_repeat('palavra ', 2000));
});

test('a shortened caption that still overflows falls back to truncation', function () {
    config()->set('trypost.self_hosted', true);
    PostContentShortener::fake([str_repeat('ainda enorme ', 500)]);

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['account_id' => $user->account_id, 'user_id' => $user->id]);

    $result = app(CaptionAdapter::class)->adapt($workspace, $user, str_repeat('palavra ', 2000), Platform::YouTube);

    expect(Platform::YouTube->contentOverflow($result))->toBe(0)
        ->and($result)->toStartWith('palavra');
});

test('truncation targets the text the publisher sends, not the raw caption', function () {
    config()->set('trypost.platforms.x.defuse_links', true);

    $workspace = Workspace::factory()->create();
    $caption = str_repeat('a.b.c.d.e.f.g.h.com ', 40);

    $result = app(CaptionAdapter::class)->adapt($workspace, null, $caption, Platform::X);
    $sent = app(ContentSanitizer::class)->displayText($result, Platform::X);

    expect(Platform::X->contentOverflow($sent))->toBe(0)
        ->and(mb_strlen($sent))->toBeGreaterThan(200);
});

test('a caption keeps almost the whole allowance when nothing rewrites it', function () {
    $workspace = Workspace::factory()->create();
    $caption = str_repeat('palavra ', 300);

    $result = app(CaptionAdapter::class)->adapt($workspace, null, $caption, Platform::TikTok);

    expect(Platform::TikTok->contentOverflow($result))->toBe(0)
        ->and(mb_strlen($result))->toBeGreaterThan(Platform::TikTok->maxContentLength() - 10);
});

test('truncation keeps the line breaks the caption was written with', function () {
    $workspace = Workspace::factory()->create();
    $caption = "Linha um\nLinha dois\n\n".str_repeat('palavra ', 300);

    $result = app(CaptionAdapter::class)->adapt($workspace, null, $caption, Platform::TikTok);

    expect(Platform::TikTok->contentOverflow($result))->toBe(0)
        ->and($result)->toStartWith("Linha um\nLinha dois\n\n");
});

test('a caption with no word boundary is cut hard rather than emptied', function () {
    $workspace = Workspace::factory()->create();

    $result = app(CaptionAdapter::class)->adapt($workspace, null, str_repeat('a', 500), Platform::YouTube);

    expect($result)->not->toBe('')
        ->and(Platform::YouTube->contentOverflow($result))->toBe(0);
});

test('the shortener prompt carries no brand context', function () {
    $instructions = (new PostContentShortener(platformLabel: 'TikTok', limit: 2200))->instructions();

    expect($instructions)
        ->toContain('2200 characters')
        ->not->toContain('Brand voice')
        ->not->toContain('register')
        ->and(strtolower($instructions))->not->toContain('brand');
});

test('a self-hosted install with no ai configured still gets a caption that fits', function () {
    config()->set('trypost.self_hosted', true);
    config()->set('ai.providers.openai.key', null);
    config()->set('ai.providers.openai.url', 'http://127.0.0.1:9/v1');

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['account_id' => $user->account_id, 'user_id' => $user->id]);

    $result = app(CaptionAdapter::class)->adapt($workspace, $user, str_repeat('palavra ', 300), Platform::YouTube);

    expect($result)->not->toBe('')
        ->and(Platform::YouTube->contentOverflow($result))->toBe(0);
});

test('a single word longer than the limit is cut mid-word rather than emptied', function () {
    $workspace = Workspace::factory()->create();
    $caption = str_repeat('a', 6000);

    $adapted = app(CaptionAdapter::class)->adapt($workspace, null, $caption, Platform::YouTube);

    expect($adapted)->not->toBe('')
        ->and(mb_strlen($adapted))->toBeLessThan(6000)
        ->and(Platform::YouTube->contentOverflow($adapted))->toBe(0);
});

test('a caption of one long word without spaces terminates instead of looping', function () {
    $workspace = Workspace::factory()->create();

    $adapted = app(CaptionAdapter::class)->adapt($workspace, null, str_repeat('x', 500), Platform::X);

    expect(Platform::X->contentOverflow($adapted))->toBe(0)
        ->and($adapted)->not->toBe('');
});

test('newlines and repeated spaces survive truncation', function () {
    $workspace = Workspace::factory()->create();
    $caption = "First line\n\nSecond  line with  gaps ".str_repeat('word ', 100);

    $adapted = app(CaptionAdapter::class)->adapt($workspace, null, $caption, Platform::X);

    expect($adapted)->toStartWith("First line\n\nSecond  line with  gaps")
        ->and(Platform::X->contentOverflow($adapted))->toBe(0);
});

test('two networks sharing a character limit ask the shortener once, not twice', function () {
    config()->set('trypost.self_hosted', true);
    $calls = 0;
    PostContentShortener::fake(function () use (&$calls): string {
        $calls++;

        return 'A tight caption that fits.';
    });

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['account_id' => $user->account_id, 'user_id' => $user->id]);

    $caption = str_repeat('palavra ', 2000);
    $adapter = app(CaptionAdapter::class);

    $threads = $adapter->adapt($workspace, $user, $caption, Platform::Threads);
    $mastodon = $adapter->adapt($workspace, $user, $caption, Platform::Mastodon);

    expect(Platform::Threads->maxContentLength())->toBe(Platform::Mastodon->maxContentLength())
        ->and($threads)->toBe('A tight caption that fits.')
        ->and($mastodon)->toBe('A tight caption that fits.')
        ->and($calls)->toBe(1);
});

test('a tighter limit still gets its own call instead of reusing a longer answer', function () {
    config()->set('trypost.self_hosted', true);
    $calls = 0;
    PostContentShortener::fake(function () use (&$calls): string {
        return ['A tight caption that fits.', 'Short one.'][$calls++];
    });

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['account_id' => $user->account_id, 'user_id' => $user->id]);

    $caption = str_repeat('palavra ', 2000);
    $adapter = app(CaptionAdapter::class);

    $threads = $adapter->adapt($workspace, $user, $caption, Platform::Threads);
    $youtube = $adapter->adapt($workspace, $user, $caption, Platform::YouTube);

    expect($calls)->toBe(2)
        ->and($threads)->toBe('A tight caption that fits.')
        ->and($youtube)->toBe('Short one.');
});

test('an instagram caption keeps only its first five hashtags', function (Platform $platform) {
    $workspace = Workspace::factory()->create();

    expect(app(CaptionAdapter::class)->adapt($workspace, null, 'New video #a #b #c #d #e #f #g', $platform))
        ->toBe('New video #a #b #c #d #e');
})->with([Platform::Instagram, Platform::InstagramFacebook]);

test('a caption for a network without a hashtag cap keeps every hashtag', function () {
    $workspace = Workspace::factory()->create();

    expect(app(CaptionAdapter::class)->adapt($workspace, null, 'New video #a #b #c #d #e #f #g', Platform::TikTok))
        ->toBe('New video #a #b #c #d #e #f #g');
});
