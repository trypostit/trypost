<?php

declare(strict_types=1);

use App\Ai\Agents\PostContentGenerator;
use App\Ai\Agents\PostContentHumanizer;
use App\Enums\Post\CreatedVia;
use App\Enums\PostPlatform\ContentType;
use App\Enums\UserWorkspace\Role;
use App\Jobs\Ai\StreamPostCreation;
use App\Models\GoogleBusinessProfileLocation;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Ai\Image;

beforeEach(function () {
    Bus::fake();
    Storage::fake();
    Image::fake();

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, ['role' => Role::Member->value]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);

    $this->account = SocialAccount::factory()->instagram()->create([
        'workspace_id' => $this->workspace->id,
    ]);
});

function runStreamPostCreation(string $format, SocialAccount $account, int $imageCount, string $template = 'image_card'): void
{
    (new StreamPostCreation(
        userId: $account->workspace->user_id,
        creationId: (string) Str::uuid(),
        workspaceId: $account->workspace_id,
        format: $format,
        socialAccountId: $account->id,
        imageCount: $imageCount,
        prompt: 'Five tips about productivity',
        template: $template,
    ))->handle();
}

test('AI carousel generation stores the post as an instagram feed, never instagram_carousel', function () {
    PostContentGenerator::fake([[
        'caption' => 'Swipe to see the tips',
        'slides' => [
            ['title' => 'Tip 1', 'body' => 'First tip', 'image_keywords' => []],
            ['title' => 'Tip 2', 'body' => 'Second tip', 'image_keywords' => []],
        ],
    ]]);
    PostContentHumanizer::fake([[
        'caption' => 'Swipe to see the tips',
        'slides' => [
            ['title' => 'Tip 1', 'body' => 'First tip'],
            ['title' => 'Tip 2', 'body' => 'Second tip'],
        ],
    ]]);

    runStreamPostCreation('instagram_carousel', $this->account, 2, 'image_card');

    $platform = PostPlatform::where('social_account_id', $this->account->id)->firstOrFail();

    expect($platform->content_type)->toBe(ContentType::InstagramFeed);
    expect($platform->meta['aspect_ratio'] ?? null)->toBe('4:5');
});

test('AI single feed generation stores the post as an instagram feed', function () {
    PostContentGenerator::fake([[
        'content' => 'A single productivity tip',
        'image_title' => 'Tip',
        'image_body' => 'Do less',
        'image_keywords' => [],
    ]]);
    PostContentHumanizer::fake([[
        'content' => 'A single productivity tip',
        'image_title' => 'Tip',
        'image_body' => 'Do less',
    ]]);

    runStreamPostCreation('instagram_feed', $this->account, 0);

    $platform = PostPlatform::where('social_account_id', $this->account->id)->firstOrFail();

    expect($platform->content_type)->toBe(ContentType::InstagramFeed);
});

test('Google Business Profile generation enables only the selected business location', function () {
    PostContentGenerator::fake([[
        'content' => 'A local business update',
        'image_title' => 'Update',
        'image_body' => 'Local news',
        'image_keywords' => [],
    ]]);
    PostContentHumanizer::fake([[
        'content' => 'A local business update',
        'image_title' => 'Update',
        'image_body' => 'Local news',
    ]]);

    $account = SocialAccount::factory()->googleBusinessProfile()->create([
        'workspace_id' => $this->workspace->id,
    ]);
    $selectedLocation = GoogleBusinessProfileLocation::factory()->create([
        'social_account_id' => $account->id,
        'title' => 'Downtown Store',
        'is_selected' => true,
    ]);
    $otherLocation = GoogleBusinessProfileLocation::factory()->create([
        'social_account_id' => $account->id,
        'title' => 'Uptown Store',
        'is_selected' => true,
    ]);

    (new StreamPostCreation(
        userId: $this->user->id,
        creationId: (string) Str::uuid(),
        workspaceId: $this->workspace->id,
        format: ContentType::GoogleBusinessProfileStandard->value,
        socialAccountId: $account->id,
        imageCount: 0,
        prompt: 'Write a local business update',
        googleBusinessProfileLocationId: $selectedLocation->id,
    ))->handle();

    $selectedTarget = PostPlatform::where('google_business_profile_location_id', $selectedLocation->id)->firstOrFail();
    $otherTarget = PostPlatform::where('google_business_profile_location_id', $otherLocation->id)->firstOrFail();

    expect($selectedTarget->enabled)->toBeTrue()
        ->and($selectedTarget->content_type)->toBe(ContentType::GoogleBusinessProfileStandard)
        ->and($otherTarget->enabled)->toBeFalse();
});

test('tweet_card template stores the tweet_text as post content and attaches a media item', function () {
    PostContentGenerator::fake([[
        'tweet_text' => 'Hello world\n\nSecond para.',
    ]]);

    $minimalPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    Image::fake([base64_encode($minimalPng)]);

    (new StreamPostCreation(
        userId: $this->user->id,
        creationId: (string) Str::uuid(),
        workspaceId: $this->workspace->id,
        format: 'x_post',
        socialAccountId: $this->account->id,
        imageCount: 1,
        prompt: 'A punchy take on productivity',
        template: 'tweet_card',
    ))->handle();

    $post = $this->user->currentWorkspace->posts()->latest()->first();

    expect($post->content)->toBe('Hello world\n\nSecond para.')
        ->and($post->media)->toHaveCount(1)
        ->and($post->created_via)->toBe(CreatedVia::Web);

    $platform = PostPlatform::where('social_account_id', $this->account->id)->firstOrFail();

    expect($platform->content_type)->toBe(ContentType::XPost);

    PostContentHumanizer::assertNeverPrompted();
});

test('tweet_card carousel produces caption as content and N media items without calling AI image client', function () {
    PostContentGenerator::fake([[
        'caption' => 'A carousel of punchy takes',
        'slides' => [
            ['tweet_text' => 'First take on productivity.'],
            ['tweet_text' => 'Second take on deep work.'],
        ],
    ]]);

    (new StreamPostCreation(
        userId: $this->user->id,
        creationId: (string) Str::uuid(),
        workspaceId: $this->workspace->id,
        format: 'instagram_carousel',
        socialAccountId: $this->account->id,
        imageCount: 2,
        prompt: 'Punchy productivity takes',
        template: 'tweet_card',
    ))->handle();

    $post = $this->user->currentWorkspace->posts()->latest()->first();

    expect($post->content)->toBe('A carousel of punchy takes')
        ->and($post->media)->toHaveCount(2);

    $platform = PostPlatform::where('social_account_id', $this->account->id)->firstOrFail();

    expect($platform->content_type)->toBe(ContentType::InstagramFeed);

    Image::assertNothingGenerated();

    PostContentHumanizer::assertNeverPrompted();
});

test('tweet_card_image single stores tweet_text as content, attaches media, and calls AI image client', function () {
    PostContentGenerator::fake([[
        'tweet_text' => 'Productivity is a mindset.',
        'image_keywords' => ['laptop', 'desk', 'morning'],
    ]]);

    $minimalPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    Image::fake([base64_encode($minimalPng)]);

    (new StreamPostCreation(
        userId: $this->user->id,
        creationId: (string) Str::uuid(),
        workspaceId: $this->workspace->id,
        format: 'x_post',
        socialAccountId: $this->account->id,
        imageCount: 1,
        prompt: 'A punchy take on productivity',
        template: 'tweet_card_image',
    ))->handle();

    $post = $this->user->currentWorkspace->posts()->latest()->first();

    expect($post->content)->toBe('Productivity is a mindset.')
        ->and($post->media)->toHaveCount(1);

    $platform = PostPlatform::where('social_account_id', $this->account->id)->firstOrFail();
    expect($platform->content_type)->toBe(ContentType::XPost);

    PostContentHumanizer::assertNeverPrompted();

    Image::assertGenerated(fn () => true);
});

test('tweet_card_image carousel stores caption and N media items each with an AI background', function () {
    PostContentGenerator::fake([[
        'caption' => 'Carousel about deep work',
        'slides' => [
            ['tweet_text' => 'First take.', 'image_keywords' => ['coffee', 'focus']],
            ['tweet_text' => 'Second take.', 'image_keywords' => ['notebook', 'pen']],
        ],
    ]]);

    $minimalPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    Image::fake([base64_encode($minimalPng), base64_encode($minimalPng)]);

    (new StreamPostCreation(
        userId: $this->user->id,
        creationId: (string) Str::uuid(),
        workspaceId: $this->workspace->id,
        format: 'instagram_carousel',
        socialAccountId: $this->account->id,
        imageCount: 2,
        prompt: 'Deep work carousel',
        template: 'tweet_card_image',
    ))->handle();

    $post = $this->user->currentWorkspace->posts()->latest()->first();

    expect($post->content)->toBe('Carousel about deep work')
        ->and($post->media)->toHaveCount(2);

    $platform = PostPlatform::where('social_account_id', $this->account->id)->firstOrFail();
    expect($platform->content_type)->toBe(ContentType::InstagramFeed);

    PostContentHumanizer::assertNeverPrompted();

    Image::assertGenerated(fn () => true);
});

test('the humanizer is given the same platform context as the generator so the rewrite honours the character cap', function () {
    PostContentGenerator::fake([[
        'content' => 'A single productivity tip',
        'image_title' => 'Tip',
        'image_body' => 'Do less',
        'image_keywords' => [],
    ]]);
    PostContentHumanizer::fake([[
        'content' => 'A single productivity tip',
        'image_title' => 'Tip',
        'image_body' => 'Do less',
    ]]);

    runStreamPostCreation('instagram_feed', $this->account, 0);

    PostContentGenerator::assertPrompted(fn ($prompt) => $prompt->agent->platformContext === 'instagram_feed');
    PostContentHumanizer::assertPrompted(fn ($prompt) => $prompt->agent->platformContext === 'instagram_feed');
});
