<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Platform\ListContentTypesTool;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Testing\Fluent\AssertableJson;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, ['role' => Role::Member->value]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
});

test('list content types returns all platforms with constraints', function () {
    $response = TryPostServer::actingAs($this->user)
        ->tool(ListContentTypesTool::class, []);

    $response->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->has('platforms', fn (AssertableJson $platforms) => $platforms
                ->each(fn (AssertableJson $p) => $p
                    ->hasAll([
                        'platform',
                        'label',
                        'max_content_length',
                        'recommended_content_length',
                        'allowed_media_types',
                        'default_content_type',
                        'content_types',
                    ])
                    ->has('content_types', fn (AssertableJson $types) => $types
                        ->each(fn (AssertableJson $type) => $type
                            ->hasAll([
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
                            ])
                        )
                    )
                )
            );
        });
});

test('list content types includes content types per platform', function () {
    $response = TryPostServer::actingAs($this->user)
        ->tool(ListContentTypesTool::class, []);

    $response->assertOk()
        ->assertSee(['linkedin', 'linkedin_post', 'x_post', 'instagram_feed', 'mastodon_post']);
});

test('list content types exposes reel max video durations', function () {
    $response = TryPostServer::actingAs($this->user)
        ->tool(ListContentTypesTool::class, []);

    $response->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->etc();

            $platforms = collect($json->toArray()['platforms']);
            $instagramTypes = collect($platforms->firstWhere('platform', 'instagram')['content_types']);
            $facebookTypes = collect($platforms->firstWhere('platform', 'facebook')['content_types']);
            $pinterestTypes = collect($platforms->firstWhere('platform', 'pinterest')['content_types']);

            expect($instagramTypes->firstWhere('value', 'instagram_reel')['max_video_duration_sec'])->toBe(900);
            expect($instagramTypes->firstWhere('value', 'instagram_reel')['max_video_bytes'])->toBe(300 * 1024 * 1024);
            expect($facebookTypes->firstWhere('value', 'facebook_reel')['max_video_duration_sec'])->toBe(90);
            expect($pinterestTypes->firstWhere('value', 'pinterest_carousel')['min_media_count'])->toBe(2);
        });
});
