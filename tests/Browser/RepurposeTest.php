<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\Repurpose\SourceFormat;
use App\Enums\SocialAccount\Platform;
use App\Enums\UserWorkspace\Role;
use App\Models\Repurpose;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;

/**
 * Wait for a data-testid element to mount and lay out. Pest browser `@`
 * selectors resolve to data-testid, and assertions do not auto-wait on SPA paint.
 */
function waitForRepurposeTestId(mixed $page, string $testId): void
{
    $page->script(<<<JS
        (async () => {
            const sel = '[data-testid="{$testId}"]';
            for (let i = 0; i < 100; i++) {
                const el = document.querySelector(sel);
                if (el && el.getBoundingClientRect().height > 0) return;
                await new Promise((r) => setTimeout(r, 50));
            }
        })();
    JS);
}

function repurposeOwnerWithAccounts(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $workspace->id]);

    $source = SocialAccount::factory()->for($workspace)->create(['platform' => Platform::Instagram]);
    $destination = SocialAccount::factory()->for($workspace)->create(['platform' => Platform::TikTok]);

    return [$user->fresh(), $workspace, $source, $destination];
}

test('the empty state offers the ready-made templates', function () {
    [$user] = repurposeOwnerWithAccounts();

    $this->actingAs($user);

    $page = visit(route('app.repurposes.index'));

    waitForRepurposeTestId($page, 'use-template-instagram_everywhere');

    $page->assertRoute('app.repurposes.index')
        ->assertVisible('@use-template-instagram_everywhere')
        ->assertVisible('@use-template-facebook_everywhere')
        ->assertVisible('@create-repurpose-button')
        ->assertNoJavaScriptErrors();
});

test('using a template opens the dialog with only the matching source account', function () {
    [$user, , $source] = repurposeOwnerWithAccounts();

    $this->actingAs($user);

    $page = visit(route('app.repurposes.index'));

    waitForRepurposeTestId($page, 'use-template-instagram_everywhere');

    $page->click('@use-template-instagram_everywhere');

    waitForRepurposeTestId($page, 'create-repurpose-dialog');

    $page->assertVisible('@create-repurpose-dialog')
        ->assertVisible("@source-account-{$source->id}")
        ->assertVisible('@create-repurpose-submit')
        ->assertNoJavaScriptErrors();
});

test('the edit page shows the watched format, the destinations and the settings tab', function () {
    [$user, $workspace, $source, $destination] = repurposeOwnerWithAccounts();

    $repurpose = Repurpose::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'source_social_account_id' => $source->id,
        'source_format' => SourceFormat::Reel,
        'destinations' => [[
            'social_account_id' => $destination->id,
            'content_type' => ContentType::TikTokVideo->value,
            'meta' => [],
        ]],
    ]);

    $this->actingAs($user);

    $page = visit(route('app.repurposes.show', $repurpose));

    waitForRepurposeTestId($page, 'source-format-select');

    $page->assertRoute('app.repurposes.show', ['repurpose' => $repurpose->id])
        ->assertVisible('@repurpose-summary')
        ->assertVisible('@repurpose-source-card')
        ->assertVisible('@source-format-select')
        ->assertVisible('@destination-picker')
        ->assertVisible("@destination-{$destination->id}")
        ->assertVisible('@tab-settings')
        ->assertNoJavaScriptErrors();
});
