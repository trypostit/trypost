<?php

declare(strict_types=1);

use App\Ai\Templates\ImageCardTemplate;
use App\Ai\Templates\TemplateContext;
use App\Ai\Templates\TweetCardImageTemplate;
use App\Ai\Templates\TweetCardTemplate;
use App\Enums\Ai\ContentStyle;
use App\Models\Workspace;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;

test('image card template exposes its identity', function () {
    $t = new ImageCardTemplate;
    $workspace = Workspace::factory()->create();
    $context = new TemplateContext($workspace, null, 'instagram_feed', 0, false);

    expect($t->style())->toBe(ContentStyle::ImageCard)
        ->and($t->key())->toBe('image_card')
        ->and($t->needsAccount())->toBeFalse()
        ->and($t->appliesBrandVisuals())->toBeTrue()
        ->and($t->promptView($context))->toBe('prompts.post_content.generator')
        ->and($t->supportedFormats())->toBe([]);
});

test('tweet-card templates do not apply brand visuals', function () {
    expect((new TweetCardTemplate)->appliesBrandVisuals())->toBeFalse()
        ->and((new TweetCardImageTemplate)->appliesBrandVisuals())->toBeFalse();
});

test('image card carousel context returns carousel schema shape', function () {
    $t = new ImageCardTemplate;
    $workspace = Workspace::factory()->create();
    $context = new TemplateContext($workspace, null, 'instagram_carousel', 3, true);

    $schema = new JsonSchemaTypeFactory;
    $result = $t->schema($schema, $context);

    expect($result)->toHaveKeys(['caption', 'slides'])
        ->and($t->promptView($context))->toBe('prompts.post_content.generator');
});

test('image card single context returns single schema shape', function () {
    $t = new ImageCardTemplate;
    $workspace = Workspace::factory()->create();
    $context = new TemplateContext($workspace, null, 'instagram_feed', 1, false);

    $schema = new JsonSchemaTypeFactory;
    $result = $t->schema($schema, $context);

    expect($result)->toHaveKeys(['content', 'image_title', 'image_body', 'image_keywords']);
});
