<?php

declare(strict_types=1);

use App\Ai\Agents\PostContentGenerator;
use App\Enums\Ai\GeneratorFormat;
use App\Enums\Workspace\ContentLanguage;
use App\Models\Workspace;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;

test('instructions use English for the default content language', function () {
    $workspace = Workspace::factory()->make([
        'content_language' => ContentLanguage::DEFAULT->value,
    ]);

    expect((new PostContentGenerator(workspace: $workspace))->instructions())
        ->toContain('Write the output in the language with code: en.');
});

test('instructions use Ukrainian when the workspace content language is uk', function () {
    $workspace = Workspace::factory()->make([
        'content_language' => ContentLanguage::Ukrainian->value,
    ]);

    expect((new PostContentGenerator(workspace: $workspace))->instructions())
        ->toContain('Write the output in the language with code: uk.');
});

test('instructions render brand context', function () {
    $workspace = Workspace::factory()->make([
        'name' => 'TryPost',
        'brand_description' => 'Social media scheduling tool',
        'brand_voice_traits' => ['friendly', 'direct'],
        'content_language' => 'en',
    ]);

    $agent = new PostContentGenerator(workspace: $workspace);
    $instructions = $agent->instructions();

    expect($instructions)->toContain('TryPost');
    expect($instructions)->toContain('friendly'); // "Be warm and friendly."
    expect($instructions)->toContain('direct'); // "Use direct, plain, accessible language."
    expect($instructions)->toContain('en');
});

test('instructions omit brand description and voice when applyBrandVoice is false', function () {
    $workspace = Workspace::factory()->make([
        'name' => 'TryPost',
        'brand_description' => 'A social scheduling tool for founders',
        'brand_voice_traits' => ['first_person', 'transparent'],
        'content_language' => 'en',
    ]);

    $agent = new PostContentGenerator(workspace: $workspace, applyBrandVoice: false);
    $instructions = $agent->instructions();

    expect($instructions)->not->toContain('A social scheduling tool for founders');
    expect($instructions)->not->toContain('first person');
    expect($instructions)->not->toContain('behind-the-scenes');
    // Language still applies — it isn't part of the brand voice.
    expect($instructions)->toContain('en');
});

test('instructions include current_content when provided', function () {
    $workspace = Workspace::factory()->make();
    $agent = new PostContentGenerator(
        workspace: $workspace,
        currentContent: 'Hello world',
    );

    expect($agent->instructions())->toContain('Hello world');
});

test('instructions omit current_content when not provided', function () {
    $workspace = Workspace::factory()->make();
    $agent = new PostContentGenerator(workspace: $workspace);

    expect($agent->instructions())->not->toContain('user already has this content');
});

test('single format schema returns content and image_keywords', function () {
    $workspace = Workspace::factory()->make();
    $agent = new PostContentGenerator(workspace: $workspace, format: GeneratorFormat::Single);

    $schemaFactory = new JsonSchemaTypeFactory;
    $schema = $agent->schema($schemaFactory);

    expect($schema)->toHaveKey('content');
    expect($schema)->toHaveKey('image_keywords');
    expect($schema)->not->toHaveKey('slides');
    expect($schema)->not->toHaveKey('caption');
});

test('carousel format schema returns caption and slides', function () {
    $workspace = Workspace::factory()->make();
    $agent = new PostContentGenerator(workspace: $workspace, format: GeneratorFormat::Carousel, slideCount: 5);

    $schemaFactory = new JsonSchemaTypeFactory;
    $schema = $agent->schema($schemaFactory);

    expect($schema)->toHaveKey('caption');
    expect($schema)->toHaveKey('slides');
    expect($schema)->not->toHaveKey('content');
});

test('carousel slide schema includes role enum', function () {
    $workspace = Workspace::factory()->make();
    $agent = new PostContentGenerator(workspace: $workspace, format: GeneratorFormat::Carousel, slideCount: 4);

    $schemaFactory = new JsonSchemaTypeFactory;
    $schema = $agent->schema($schemaFactory);

    $slidesArray = $schema['slides']->toArray();
    $slideItem = $slidesArray['items'];

    expect($slideItem['properties'])->toHaveKey('role');
    expect($slideItem['properties']['role']['enum'])->toBe(['hook', 'development', 'proof', 'cta']);
    expect($slideItem['required'])->toContain('role');
});

test('carousel instructions mention slide count', function () {
    $workspace = Workspace::factory()->make();
    $agent = new PostContentGenerator(workspace: $workspace, format: GeneratorFormat::Carousel, slideCount: 5);

    $instructions = $agent->instructions();

    expect($instructions)->toContain('5');
    expect($instructions)->toContain('slides');
});

test('carousel instructions describe the roteiro arc with hook, development, proof and cta', function () {
    $workspace = Workspace::factory()->make();
    $agent = new PostContentGenerator(workspace: $workspace, format: GeneratorFormat::Carousel, slideCount: 4);

    $instructions = $agent->instructions();

    expect($instructions)->toContain('hook');
    expect($instructions)->toContain('development');
    expect($instructions)->toContain('proof');
    expect($instructions)->toContain('cta');
    expect($instructions)->toContain('next action');
});

test('single format instructions do not include carousel roteiro section', function () {
    $workspace = Workspace::factory()->make();
    $agent = new PostContentGenerator(workspace: $workspace, format: GeneratorFormat::Single);

    $instructions = $agent->instructions();

    expect($instructions)->not->toContain('Carousel script');
    expect($instructions)->not->toContain('Role distribution by slide count');
});

test('single instructions mention image_keywords', function () {
    $workspace = Workspace::factory()->make();
    $agent = new PostContentGenerator(workspace: $workspace, format: GeneratorFormat::Single);

    $instructions = $agent->instructions();

    expect($instructions)->toContain('image_keywords');
});

test('instructions inject the platform character cap from the shared budget', function () {
    $agent = new PostContentGenerator(
        workspace: Workspace::factory()->make(),
        format: GeneratorFormat::Single,
        platformContext: 'x_post',
    );

    $instructions = $agent->instructions();

    expect($instructions)->toContain('280'); // X hard cap
    expect($instructions)->toContain('Hard cap');
});
