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
        ->assertVisible('@source-account-select')
        ->assertVisible('@create-repurpose-submit');

    $page->click('@source-account-select');

    waitForRepurposeTestId($page, 'source-account-option');

    $page->assertSee($source->display_name)
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
        ->assertVisible('@repurpose-lifecycle')
        ->assertNoJavaScriptErrors();
});

test('a destination is not warned about missing media before there is any', function () {
    [$user, $workspace, $source] = repurposeOwnerWithAccounts();

    $facebook = SocialAccount::factory()->for($workspace)->create(['platform' => Platform::Facebook]);

    $repurpose = Repurpose::factory()->create([
        'workspace_id' => $workspace->id,
        'source_social_account_id' => $source->id,
        'destinations' => [[
            'social_account_id' => $facebook->id,
            'content_type' => ContentType::FacebookReel->value,
            'meta' => [],
        ]],
    ]);

    $this->actingAs($user);

    $page = visit(route('app.repurposes.show', $repurpose));

    waitForRepurposeTestId($page, 'facebook-settings-toggle');

    $page->click('@facebook-settings-toggle');

    usleep(300000);

    $page->assertDontSee('requires_media')
        ->assertDontSee(trans('posts.form.warnings.requires_media'))
        ->assertNoJavaScriptErrors();
});

test('the source account is picked from a searchable list on the edit page', function () {
    [$user, $workspace, $source] = repurposeOwnerWithAccounts();

    config()->set('trypost.allow_multiple_social_accounts', true);

    $other = SocialAccount::factory()->for($workspace)->create(['platform' => Platform::Facebook]);

    $repurpose = Repurpose::factory()->create([
        'workspace_id' => $workspace->id,
        'source_social_account_id' => $source->id,
    ]);

    $this->actingAs($user);

    $page = visit(route('app.repurposes.show', $repurpose));

    waitForRepurposeTestId($page, 'source-account-select');

    $page->click('@source-account-select')
        ->assertSee($other->display_name)
        ->assertNoJavaScriptErrors();
});

test('switching the source hands the old one back to the destinations before saving', function () {
    [$user, $workspace, $source] = repurposeOwnerWithAccounts();

    config()->set('trypost.allow_multiple_social_accounts', true);

    $facebook = SocialAccount::factory()->for($workspace)->create(['platform' => Platform::Facebook]);

    $repurpose = Repurpose::factory()->create([
        'workspace_id' => $workspace->id,
        'source_social_account_id' => $source->id,
    ]);

    $this->actingAs($user);

    $page = visit(route('app.repurposes.show', $repurpose));

    waitForRepurposeTestId($page, 'source-account-select');

    $page->assertVisible("@channel-{$facebook->id}")
        ->assertMissing("@channel-{$source->id}")
        ->assertVisible('@flow-source-instagram')
        ->click('@source-account-select');

    usleep(300000);

    $page->click("@source-option-{$facebook->id}");

    usleep(400000);

    $page->assertVisible("@channel-{$source->id}")
        ->assertMissing("@channel-{$facebook->id}")
        ->assertVisible('@flow-source-facebook')
        ->assertMissing('@flow-source-instagram')
        ->assertNoJavaScriptErrors();
});

test('deleting sits behind the menu instead of on the page', function () {
    [$user, $workspace, $source] = repurposeOwnerWithAccounts();

    $repurpose = Repurpose::factory()->create([
        'workspace_id' => $workspace->id,
        'source_social_account_id' => $source->id,
    ]);

    $this->actingAs($user);

    $page = visit(route('app.repurposes.show', $repurpose));

    waitForRepurposeTestId($page, 'repurpose-menu');

    $page->assertMissing('@delete-repurpose')
        ->click('@repurpose-menu');

    usleep(400000);

    $page->assertVisible('@delete-repurpose')
        ->assertNoJavaScriptErrors();
});
