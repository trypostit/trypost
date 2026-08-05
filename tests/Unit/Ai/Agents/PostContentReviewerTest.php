<?php

declare(strict_types=1);

use App\Ai\Agents\PostContentReviewer;
use App\Models\Workspace;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;

test('instructions render brand context and language', function () {
    $workspace = Workspace::factory()->make([
        'name' => 'TryPost',
        'brand_voice_traits' => ['friendly', 'concise'],
        'content_language' => 'pt-BR',
    ]);

    $agent = new PostContentReviewer(workspace: $workspace);
    $instructions = $agent->instructions();

    expect($instructions)->toContain('TryPost');
    expect($instructions)->toContain('friendly'); // "Be warm and friendly."
    expect($instructions)->toContain('short'); // "Keep sentences short and objective."
    expect($instructions)->toContain('pt-BR');
});

test('instructions use Ukrainian when the workspace content language is uk', function () {
    $workspace = Workspace::factory()->make([
        'content_language' => 'uk',
    ]);

    expect((new PostContentReviewer(workspace: $workspace))->instructions())
        ->toContain('Output language: uk.');
});

test('schema requires suggestions array with original/suggestion/reason', function () {
    $workspace = Workspace::factory()->make();
    $agent = new PostContentReviewer(workspace: $workspace);

    $schema = $agent->schema(new JsonSchemaTypeFactory);
    expect($schema)->toHaveKey('suggestions');
});
