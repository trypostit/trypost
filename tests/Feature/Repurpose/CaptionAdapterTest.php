<?php

declare(strict_types=1);

use App\Ai\Agents\PostContentShortener;
use App\Enums\SocialAccount\Platform;
use App\Models\AiUsageLog;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Repurpose\CaptionAdapter;
use App\Services\Social\ContentSanitizer;

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

test('ai shortens the caption and the workspace is billed for it', function () {
    config()->set('trypost.self_hosted', true);
    PostContentShortener::fake(['A tight caption that fits.']);

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['account_id' => $user->account_id, 'user_id' => $user->id]);

    $result = app(CaptionAdapter::class)->adapt($workspace, $user, str_repeat('palavra ', 2000), Platform::YouTube);

    expect($result)->toBe('A tight caption that fits.')
        ->and(AiUsageLog::where('workspace_id', $workspace->id)->count())->toBe(1);
});

test('a shortened caption that still overflows falls back to truncation', function () {
    config()->set('trypost.self_hosted', true);
    PostContentShortener::fake([str_repeat('ainda enorme ', 200)]);

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

test('the shortener prompt leaves out brand context the workspace does not have', function () {
    $bare = new PostContentShortener(
        workspace: Workspace::factory()->make(['name' => '', 'brand_voice_traits' => []]),
        platformLabel: 'YouTube Shorts',
        limit: 100,
    );

    expect($bare->instructions())
        ->not->toContain('the brand ""')
        ->not->toContain('Brand voice')
        ->toContain('100 characters')
        ->toContain('95 characters');

    $branded = new PostContentShortener(
        workspace: Workspace::factory()->make(['name' => 'Acme', 'brand_voice_traits' => ['casual']]),
        platformLabel: 'TikTok',
        limit: 2200,
    );

    expect($branded->instructions())
        ->toContain('the brand "Acme"')
        ->toContain('Keep a casual, relaxed register.');
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
