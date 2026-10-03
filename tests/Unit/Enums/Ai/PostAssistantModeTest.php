<?php

declare(strict_types=1);

use App\Enums\Ai\PostAssistantMode;

test('assistant modes are the generate and rewrite actions', function () {
    expect(array_column(PostAssistantMode::cases(), 'value'))
        ->toBe(['generate', 'regenerate', 'rephrase', 'shorten', 'expand', 'more_casual', 'more_formal']);
});

test('only generate modes require a prompt and every other mode requires content', function (PostAssistantMode $mode) {
    $writesFromPrompt = in_array($mode, [PostAssistantMode::Generate, PostAssistantMode::Regenerate], true);

    expect($mode->requiresPrompt())->toBe($writesFromPrompt)
        ->and($mode->requiresContent())->toBe(! $writesFromPrompt);
})->with(PostAssistantMode::cases());
