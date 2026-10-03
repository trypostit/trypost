<?php

declare(strict_types=1);

use App\Ai\Agents\PostWritingAssistant;
use App\Enums\Ai\PostAssistantMode;
use App\Enums\SocialAccount\Platform;
use App\Enums\User\Locale;

test('each mode renders its own task', function (PostAssistantMode $mode, string $phrase) {
    $instructions = (new PostWritingAssistant($mode, 'Existing caption', Locale::English))->instructions();

    expect($instructions)->toContain($phrase);
})->with([
    'generate' => [PostAssistantMode::Generate, 'Write a new social media caption from the request'],
    'regenerate' => [PostAssistantMode::Regenerate, 'clearly different'],
    'rephrase' => [PostAssistantMode::Rephrase, 'Rephrase the existing caption'],
    'shorten' => [PostAssistantMode::Shorten, 'noticeably shorter'],
    'expand' => [PostAssistantMode::Expand, 'useful detail'],
    'more casual' => [PostAssistantMode::MoreCasual, 'relaxed'],
    'more formal' => [PostAssistantMode::MoreFormal, 'professional'],
]);

test('rewrites keep the caption language and generation names the user language', function () {
    $rephrase = (new PostWritingAssistant(PostAssistantMode::Rephrase, 'Olá mundo', Locale::English))->instructions();
    $generate = (new PostWritingAssistant(PostAssistantMode::Generate, '', Locale::PortugueseBrazil))->instructions();

    expect($rephrase)
        ->toContain('Keep the language of the existing caption')
        ->not->toContain('Write in English (en).')
        ->and($generate)->toContain('Write in Brazilian Portuguese (pt-BR).');
});

test('a platform adds its limits and style, and no platform adds none', function () {
    $withPlatform = (new PostWritingAssistant(PostAssistantMode::Generate, '', Locale::English, Platform::X))->instructions();
    $withoutPlatform = (new PostWritingAssistant(PostAssistantMode::Generate, '', Locale::English))->instructions();

    expect($withPlatform)
        ->toContain('X')
        ->toContain('280 characters')
        ->toContain('220 characters')
        ->toContain('at most two hashtags')
        ->and($withoutPlatform)->not->toContain('Hard limit');
});

test('instructions never carry brand context', function (PostAssistantMode $mode, ?Platform $platform) {
    $instructions = (new PostWritingAssistant($mode, 'Existing caption', Locale::English, $platform, 'Previous'))->instructions();

    expect(strtolower($instructions))->not->toContain('brand');
})->with(PostAssistantMode::cases())->with([null, ...Platform::cases()]);

test('every platform has a style note', function (Platform $platform) {
    $style = trim(view('prompts.post_content._platform_style', ['platform' => $platform->value])->render());

    expect($style)->not->toBe('');
})->with(Platform::cases());

test('the previous suggestion reaches the prompt verbatim', function () {
    $instructions = (new PostWritingAssistant(PostAssistantMode::Regenerate, '', Locale::English, null, 'Sale & more at https://example.com/?a=1&b=2'))->instructions();

    expect($instructions)->toContain('Sale & more at https://example.com/?a=1&b=2');
});
