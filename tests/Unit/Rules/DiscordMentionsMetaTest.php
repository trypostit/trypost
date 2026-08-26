<?php

declare(strict_types=1);

use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Rules\DiscordMentionsMeta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $platform
 * @param  list<mixed>  $mentions
 * @return array<string, list<string>>
 */
function runMentionsMetaRule(array $mentions, array $platform): array
{
    $data = [
        'platforms' => [array_merge($platform, ['meta' => ['mentions' => $mentions]])],
    ];
    $validator = Validator::make($data, []);
    $rule = (new DiscordMentionsMeta)->setData($data)->setValidator($validator);

    $rule->validate('platforms.0.meta.mentions', $mentions, function (): void {});

    return $validator->errors()->messages();
}

test('requires a token on each Discord mention', function () {
    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->discord()->create([
        'workspace_id' => $workspace->id,
    ]);

    $errors = runMentionsMetaRule(['@everyone'], [
        'social_account_id' => $account->id,
    ]);

    expect($errors)->toHaveKey('platforms.0.meta.mentions.0.token');
});

test('rejects a non-string Discord mention label', function () {
    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->discord()->create([
        'workspace_id' => $workspace->id,
    ]);

    $errors = runMentionsMetaRule([['token' => '<@1>', 'label' => 123]], [
        'social_account_id' => $account->id,
    ]);

    expect($errors)->toHaveKey('platforms.0.meta.mentions.0.label');
});

test('skips Discord mention shape when the platform is tiktok', function () {
    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->tiktok()->create([
        'workspace_id' => $workspace->id,
    ]);

    $errors = runMentionsMetaRule(['@someone'], [
        'social_account_id' => $account->id,
    ]);

    expect($errors)->toBe([]);
});
