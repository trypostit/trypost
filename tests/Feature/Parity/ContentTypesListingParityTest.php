<?php

declare(strict_types=1);

use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Platform\ListContentTypesTool;

beforeEach(function () {
    ['user' => $this->user, 'workspace' => $this->workspace, 'token' => $this->token] = parityContext();
});

function contentTypesListingApi(object $test): array
{
    return $test->withHeaders(parityApi($test->token))->getJson(route('api.content-types'))->assertOk()->json('platforms');
}

function contentTypesListingPlatform(array $platforms, string $platform): array
{
    return collect($platforms)->firstWhere('platform', $platform);
}

function contentTypesListingType(array $platforms, string $platform, string $type): array
{
    return collect(contentTypesListingPlatform($platforms, $platform)['content_types'])->firstWhere('value', $type);
}

test('api and mcp list exactly the same platforms payload', function () {
    $mcp = null;

    TryPostServer::actingAs($this->user)->tool(ListContentTypesTool::class, [])
        ->assertOk()
        ->assertStructuredContent(function ($json) use (&$mcp) {
            $mcp = $json->toArray()['platforms'];
            $json->etc();
        });

    expect($mcp)->toEqual(contentTypesListingApi($this));
});

test('content types say which ones publish no text', function () {
    $platforms = contentTypesListingApi($this);

    expect(contentTypesListingType($platforms, 'instagram', 'instagram_story')['captionless'])->toBeTrue()
        ->and(contentTypesListingType($platforms, 'instagram', 'instagram_feed')['captionless'])->toBeFalse();
});

test('content types say a document must be the only attachment', function () {
    $platforms = contentTypesListingApi($this);

    expect(contentTypesListingType($platforms, 'linkedin', 'linkedin_post')['document_must_be_alone'])->toBeTrue()
        ->and(contentTypesListingType($platforms, 'x', 'x_post')['document_must_be_alone'])->toBeFalse();
});

test('content types say where thread replies are supported and how many', function () {
    $platforms = contentTypesListingApi($this);

    expect(contentTypesListingType($platforms, 'x', 'x_post'))
        ->toMatchArray(['supports_thread_replies' => true, 'max_thread_replies' => 24])
        ->and(contentTypesListingType($platforms, 'bluesky', 'bluesky_post')['supports_thread_replies'])->toBeTrue()
        ->and(contentTypesListingType($platforms, 'instagram', 'instagram_feed'))
        ->toMatchArray(['supports_thread_replies' => false, 'max_thread_replies' => null]);
});

test('platforms list the meta required to schedule or publish', function () {
    $platforms = contentTypesListingApi($this);

    expect(contentTypesListingPlatform($platforms, 'tiktok')['required_meta'])->toBe(['privacy_level'])
        ->and(contentTypesListingPlatform($platforms, 'pinterest')['required_meta'])->toBe(['board_id'])
        ->and(contentTypesListingPlatform($platforms, 'discord')['required_meta'])->toBe(['channel_id'])
        ->and(contentTypesListingPlatform($platforms, 'linkedin')['required_meta'])->toBe([]);
});

test('platforms list hashtag, alt text and long post limits', function () {
    $platforms = contentTypesListingApi($this);

    expect(contentTypesListingPlatform($platforms, 'instagram'))
        ->toMatchArray(['max_hashtags' => 5])
        ->and(contentTypesListingPlatform($platforms, 'linkedin')['max_hashtags'])->toBeNull()
        ->and(contentTypesListingPlatform($platforms, 'x'))
        ->toMatchArray(['max_content_length' => 280, 'alt_text_max_length' => 1000])
        ->not->toHaveKey('long_post_content_length');
});
