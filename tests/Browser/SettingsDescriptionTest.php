<?php

declare(strict_types=1);

use App\Enums\User\Locale;
use App\Models\AccessToken;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Webhook;
use App\Models\Workspace;
use App\Models\WorkspaceLabel;
use App\Models\WorkspaceSignature;

function waitForSettingsDescriptionTestId(mixed $page, string $testId): void
{
    $page->script(<<<JS
        (async () => {
            const selector = '[data-testid="{$testId}"]';
            for (let attempt = 0; attempt < 200; attempt++) {
                const element = document.querySelector(selector);
                if (element && element.getBoundingClientRect().height > 0) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);
}

test('a settings page description fits on one line in every language', function (string $route, string $key) {
    config(['trypost.self_hosted' => false]);

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    WorkspaceLabel::factory()->create(['workspace_id' => $workspace->id]);
    WorkspaceSignature::factory()->create(['workspace_id' => $workspace->id]);
    Webhook::factory()->create(['workspace_id' => $workspace->id]);
    $token = $user->createToken('Existing key');
    AccessToken::find($token->token->id)->forceFill(['workspace_id' => $workspace->id])->saveQuietly();

    $this->actingAs($user->fresh());

    $page = visit(route($route));
    waitForSettingsDescriptionTestId($page, 'settings-page-description');

    $translations = collect(Locale::cases())
        ->mapWithKeys(fn (Locale $locale): array => [$locale->value => __($key, [], $locale->value)])
        ->all();

    $json = json_encode($translations, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT);

    $wrapped = $page->script(<<<JS
        (() => {
            const translations = {$json};
            const element = document.querySelector('[data-testid="settings-page-description"]');
            const lineHeight = parseFloat(getComputedStyle(element).lineHeight);
            return Object.entries(translations)
                .filter(([, text]) => {
                    element.textContent = text;
                    return Math.round(element.getBoundingClientRect().height / lineHeight) > 1;
                })
                .map(([locale]) => locale);
        })()
    JS);

    expect($wrapped)->toBe([]);
})->with([
    'channels' => ['app.workspace.channels', 'channels.description'],
    'members' => ['app.members', 'settings.workspace.members_description'],
    'signatures' => ['app.signatures.index', 'signatures.description'],
    'labels' => ['app.labels.index', 'labels.description'],
    'webhooks' => ['app.webhooks.index', 'webhooks.description'],
    'api keys' => ['app.api-keys.index', 'settings.api_keys.description'],
    'mcp' => ['app.mcp.index', 'mcp.subtitle'],
    'account' => ['app.account.edit', 'settings.account.description'],
]);
