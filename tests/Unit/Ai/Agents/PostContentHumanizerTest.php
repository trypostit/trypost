<?php

declare(strict_types=1);

use App\Ai\Agents\PostContentHumanizer;
use App\Enums\PostPlatform\ContentType;
use App\Models\Workspace;

test('instructions inject the platform character cap so the rewrite cannot drift over it', function () {
    $workspace = Workspace::factory()->make(['content_language' => 'en']);

    $agent = new PostContentHumanizer(
        workspace: $workspace,
        platformContext: ContentType::XPost->value,
    );

    $instructions = $agent->instructions();

    // X caps at 280 chars — the humanizer must be told, mirroring the generator.
    expect($instructions)->toContain('280');
    expect($instructions)->toContain('Hard cap');
});

test('instructions omit the length section when no platform context is given', function () {
    $workspace = Workspace::factory()->make(['content_language' => 'en']);

    $agent = new PostContentHumanizer(workspace: $workspace);

    expect($agent->instructions())->not->toContain('Hard cap');
});

test('instructions still render the AI-tell removal rules', function () {
    $workspace = Workspace::factory()->make(['content_language' => 'en']);

    $agent = new PostContentHumanizer(workspace: $workspace);

    expect($agent->instructions())->toContain('AI-tells');
});

test('instructions use Ukrainian when the workspace content language is uk', function () {
    $workspace = Workspace::factory()->make(['content_language' => 'uk']);

    expect((new PostContentHumanizer(workspace: $workspace))->instructions())
        ->toContain('Output language: uk.');
});
