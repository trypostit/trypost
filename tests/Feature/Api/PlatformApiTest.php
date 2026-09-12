<?php

declare(strict_types=1);

beforeEach(function () {
    $result = createApiTestToken();
    $this->user = $result['user'];
    $this->workspace = $result['workspace'];
    $this->plainToken = $result['plain_token'];
});

it('lists content types per platform', function () {
    $response = $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->getJson(route('api.content-types'))
        ->assertOk()
        ->assertJsonStructure([
            'platforms' => [
                '*' => [
                    'platform',
                    'label',
                    'max_content_length',
                    'recommended_content_length',
                    'allowed_media_types',
                    'default_content_type',
                    'content_types' => [
                        '*' => [
                            'value',
                            'label',
                            'description',
                            'max_media_count',
                            'min_media_count',
                            'requires_media',
                            'accept_images',
                            'accept_videos',
                            'accept_documents',
                            'accepts_gif',
                            'accepts_mov',
                            'forbids_mixed_media',
                            'max_video_duration_sec',
                            'max_image_bytes',
                            'max_video_bytes',
                            'max_document_bytes',
                        ],
                    ],
                ],
            ],
        ]);

    $platforms = collect($response->json('platforms'));
    $instagramTypes = collect($platforms->firstWhere('platform', 'instagram')['content_types']);
    $facebookTypes = collect($platforms->firstWhere('platform', 'facebook')['content_types']);
    $pinterestTypes = collect($platforms->firstWhere('platform', 'pinterest')['content_types']);

    expect($instagramTypes->firstWhere('value', 'instagram_reel')['max_video_duration_sec'])->toBe(900);
    expect($instagramTypes->firstWhere('value', 'instagram_reel')['max_video_bytes'])->toBe(300 * 1024 * 1024);
    expect($instagramTypes->firstWhere('value', 'instagram_reel')['accept_images'])->toBeFalse();
    expect($facebookTypes->firstWhere('value', 'facebook_reel')['max_video_duration_sec'])->toBe(90);
    expect($pinterestTypes->firstWhere('value', 'pinterest_carousel')['min_media_count'])->toBe(2);
    expect($pinterestTypes->firstWhere('value', 'pinterest_pin')['min_media_count'])->toBe(0);
});

it('rejects content-types without auth', function () {
    $this->getJson(route('api.content-types'))->assertUnauthorized();
});
