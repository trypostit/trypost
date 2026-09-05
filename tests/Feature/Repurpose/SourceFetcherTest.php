<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;
use App\Services\Repurpose\SourceFetcherFactory;
use Illuminate\Support\Facades\Http;

test('the instagram fetcher returns only videos with their download url and caption', function () {
    $graph = config('trypost.platforms.instagram.graph_api');

    Http::fake([
        "{$graph}/*" => Http::response(['data' => [
            ['id' => 'm1', 'media_type' => 'VIDEO', 'media_url' => 'https://cdn.example.com/v1.mp4', 'caption' => 'Hello', 'permalink' => 'https://instagram.com/p/1', 'timestamp' => '2026-09-01T10:00:00+0000'],
            ['id' => 'm2', 'media_type' => 'IMAGE', 'media_url' => 'https://cdn.example.com/i.jpg', 'caption' => 'Pic', 'permalink' => 'https://instagram.com/p/2', 'timestamp' => '2026-09-01T11:00:00+0000'],
            ['id' => 'm3', 'media_type' => 'VIDEO', 'caption' => 'Copyrighted', 'permalink' => 'https://instagram.com/p/3', 'timestamp' => '2026-09-01T12:00:00+0000'],
        ]]),
    ]);

    $account = SocialAccount::factory()->create(['platform' => Platform::Instagram]);

    $media = app(SourceFetcherFactory::class)->for($account)->fetch($account, null);

    expect($media)->toHaveCount(3)
        ->and($media[0]->id)->toBe('m1')
        ->and($media[0]->isVideo)->toBeTrue()
        ->and($media[0]->downloadUrl)->toBe('https://cdn.example.com/v1.mp4')
        ->and($media[0]->caption)->toBe('Hello')
        ->and($media[0]->permalink)->toBe('https://instagram.com/p/1')
        ->and($media[0]->createdAt?->toDateString())->toBe('2026-09-01')
        ->and($media[1]->isVideo)->toBeFalse()
        ->and($media[2]->isVideo)->toBeTrue()
        ->and($media[2]->downloadUrl)->toBeNull();
});

test('an instagram account connected through facebook uses the facebook graph host', function () {
    $graph = config('trypost.platforms.instagram-facebook.graph_api');

    Http::fake(["{$graph}/*" => Http::response(['data' => []])]);

    $account = SocialAccount::factory()->create(['platform' => Platform::InstagramFacebook]);

    app(SourceFetcherFactory::class)->for($account)->fetch($account, null);

    Http::assertSent(fn ($request) => str_starts_with($request->url(), $graph));
});

test('the facebook fetcher maps page videos', function () {
    $graph = config('trypost.platforms.facebook.graph_api');

    Http::fake([
        "{$graph}/*" => Http::response(['data' => [
            ['id' => 'v1', 'source' => 'https://cdn.example.com/v1.mp4', 'description' => 'Reel', 'permalink_url' => '/watch/1', 'created_time' => '2026-09-02T10:00:00+0000'],
        ]]),
    ]);

    $account = SocialAccount::factory()->create(['platform' => Platform::Facebook]);

    $media = app(SourceFetcherFactory::class)->for($account)->fetch($account, null);

    expect($media)->toHaveCount(1)
        ->and($media[0]->id)->toBe('v1')
        ->and($media[0]->isVideo)->toBeTrue()
        ->and($media[0]->downloadUrl)->toBe('https://cdn.example.com/v1.mp4')
        ->and($media[0]->caption)->toBe('Reel');
});

test('a since timestamp is sent to the api', function () {
    $graph = config('trypost.platforms.instagram.graph_api');
    Http::fake(["{$graph}/*" => Http::response(['data' => []])]);

    $account = SocialAccount::factory()->create(['platform' => Platform::Instagram]);
    $since = now()->subDay();

    app(SourceFetcherFactory::class)->for($account)->fetch($account, $since);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'since='.$since->getTimestamp()));
});

test('a failed response throws so the caller can record it', function () {
    $graph = config('trypost.platforms.instagram.graph_api');
    Http::fake(["{$graph}/*" => Http::response(['error' => ['message' => 'Invalid token']], 401)]);

    $account = SocialAccount::factory()->create(['platform' => Platform::Instagram]);

    expect(fn () => app(SourceFetcherFactory::class)->for($account)->fetch($account, null))
        ->toThrow(RuntimeException::class, 'Invalid token');
});

test('an unsupported platform cannot be a source', function () {
    $account = SocialAccount::factory()->create(['platform' => Platform::TikTok]);

    expect(fn () => app(SourceFetcherFactory::class)->for($account))
        ->toThrow(InvalidArgumentException::class);
});
