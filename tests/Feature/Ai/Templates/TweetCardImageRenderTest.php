<?php

declare(strict_types=1);

use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Services\Ai\AiImageClient;
use App\Services\Image\PostImagePipeline;
use App\Services\Image\TemplateImageGenerator;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Image;

test('renderTweetCard with image keywords generates an AI image background', function () {
    Storage::fake();

    $minimalPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    Image::fake([base64_encode($minimalPng)]);

    $workspace = Workspace::factory()->create(['brand_color' => '#1d9bf0']);
    $account = SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'display_name' => 'Alan Nicolas',
        'username' => 'oalanicolas',
    ]);

    $result = app(TemplateImageGenerator::class)->renderTweetCard(
        $workspace,
        $account,
        'A punchy tweet about productivity.',
        ['productivity', 'laptop', 'morning'],
    );

    expect($result)->not->toBeNull()
        ->and($result['path'])->toEndWith('.webp')
        ->and(Storage::exists($result['path']))->toBeTrue()
        ->and(data_get($result, 'source_meta.template'))->toBe('tweet_card_image')
        ->and(data_get($result, 'source_meta.keywords'))->toBe(['productivity', 'laptop', 'morning']);

    Image::assertGenerated(fn () => true);
});

test('renderTweetCard with image keywords falls back to solid color when AI returns null', function () {
    Storage::fake();

    $aiImageMock = Mockery::mock(AiImageClient::class);
    $aiImageMock->shouldReceive('generate')->andReturn(null);
    $this->app->instance(AiImageClient::class, $aiImageMock);

    $workspace = Workspace::factory()->create(['brand_color' => '#1d9bf0']);
    $account = SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'display_name' => 'Alan Nicolas',
        'username' => 'oalanicolas',
    ]);

    $result = app(TemplateImageGenerator::class)->renderTweetCard(
        $workspace,
        $account,
        'A punchy tweet about productivity.',
        ['productivity', 'laptop', 'morning'],
    );

    expect($result)->not->toBeNull()
        ->and($result['path'])->toEndWith('.webp')
        ->and(Storage::exists($result['path']))->toBeTrue()
        ->and(data_get($result, 'source_meta.template'))->toBe('tweet_card_image');
});

test('renderTweetCard without image keywords does not call the AI image client', function () {
    Storage::fake();
    Image::fake();

    $workspace = Workspace::factory()->create(['brand_color' => '#1d9bf0']);
    $account = SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'display_name' => 'Test User',
        'username' => 'testuser',
    ]);

    $result = app(TemplateImageGenerator::class)->renderTweetCard(
        $workspace,
        $account,
        'A solid background tweet.',
    );

    expect($result)->not->toBeNull()
        ->and(data_get($result, 'source_meta.template'))->toBe('tweet_card');

    Image::assertNothingGenerated();
});

test('forTweetCard with image keywords delegates keywords to the generator', function () {
    Storage::fake();

    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->create(['workspace_id' => $workspace->id]);

    $mock = Mockery::mock(TemplateImageGenerator::class)->makePartial();
    $mock->shouldReceive('renderTweetCard')
        ->withArgs(fn ($w, $a, $text, $keywords) => $keywords === ['nature', 'forest'])
        ->andReturn(['path' => 'ai-images/tweet_tweet_card_image_fake.webp', 'source_meta' => ['template' => 'tweet_card_image']]);

    $this->app->instance(TemplateImageGenerator::class, $mock);

    Storage::put('ai-images/tweet_tweet_card_image_fake.webp', 'fakecontent');

    $result = app(PostImagePipeline::class)->forTweetCard($workspace, $account, 'some text', ['nature', 'forest']);

    expect($result)->toHaveCount(1);
});

test('forTweetCardCarousel accepts slide arrays with image_keywords', function () {
    Storage::fake();
    Image::fake();

    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->create(['workspace_id' => $workspace->id]);

    $aiImageMock = Mockery::mock(AiImageClient::class);
    $minimalPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    $aiImageMock->shouldReceive('generate')->andReturn(['bytes' => $minimalPng, 'provider' => 'openai', 'model' => 'gpt-image-2']);
    $this->app->instance(AiImageClient::class, $aiImageMock);

    $slides = [
        ['tweet_text' => 'First punchy take.', 'image_keywords' => ['coffee', 'morning']],
        ['tweet_text' => 'Second punchy take.', 'image_keywords' => ['office', 'desk']],
    ];

    $result = app(PostImagePipeline::class)->forTweetCardCarousel($workspace, $account, $slides);

    expect($result)->toHaveCount(2);
});

test('forTweetCardCarousel still accepts plain string slides for backward compat', function () {
    Storage::fake();
    Image::fake();

    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->create(['workspace_id' => $workspace->id]);

    $result = app(PostImagePipeline::class)->forTweetCardCarousel(
        $workspace,
        $account,
        ['First slide text.', 'Second slide text.'],
    );

    expect($result)->toHaveCount(2);

    Image::assertNothingGenerated();
});
