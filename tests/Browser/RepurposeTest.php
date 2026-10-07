<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\Repurpose\ItemReason;
use App\Enums\Repurpose\ItemStatus;
use App\Enums\Repurpose\PublishMode;
use App\Enums\Repurpose\SourceFormat;
use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status as AccountStatus;
use App\Enums\TikTok\PrivacyLevel;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Models\Repurpose;
use App\Models\RepurposeItem;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
});

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

function waitForRepurposeCondition(mixed $page, string $condition): void
{
    $page->script(<<<JS
        (async () => {
            for (let i = 0; i < 100; i++) {
                if ({$condition}) return;
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
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);

    $source = SocialAccount::factory()->for($workspace)->create(['platform' => Platform::Instagram]);
    $destination = SocialAccount::factory()->for($workspace)->create(['platform' => Platform::TikTok]);

    return [$user->fresh(), $workspace, $source, $destination];
}

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

    waitForRepurposeTestId($page, "channel-type-{$facebook->id}-facebook_reel");

    $page->assertVisible("@channel-settings-{$facebook->id}")
        ->assertAttribute("@channel-type-{$facebook->id}-facebook_reel", 'aria-checked', 'true')
        ->assertDontSee('requires_media')
        ->assertDontSee(trans('posts.form.warnings.requires_media'))
        ->assertNoJavaScriptErrors();
});

test('the source account is picked from a searchable list and handed back to the destinations when switched', function () {
    [$user, $workspace, $source] = repurposeOwnerWithAccounts();

    $facebook = SocialAccount::factory()->for($workspace)->create(['platform' => Platform::Facebook]);
    $broken = SocialAccount::factory()->for($workspace)->create([
        'platform' => Platform::Instagram,
        'status' => AccountStatus::TokenExpired,
    ]);

    $repurpose = Repurpose::factory()->create([
        'workspace_id' => $workspace->id,
        'source_social_account_id' => $source->id,
    ]);

    $this->actingAs($user);

    $page = visit(route('app.repurposes.show', $repurpose));

    waitForRepurposeTestId($page, 'source-account-select');

    $page->assertVisible("@channel-{$facebook->id}")
        ->assertMissing("@channel-{$source->id}")
        ->assertAttribute('@repurpose-source-avatar', 'data-platform', Platform::Instagram->value)
        ->click('@source-account-select');

    waitForRepurposeTestId($page, "source-option-{$facebook->id}");

    $page->assertSee($facebook->display_name)
        ->assertVisible("@source-option-disconnected-{$broken->id}")
        ->assertMissing("@source-option-disconnected-{$source->id}")
        ->click("@source-option-{$facebook->id}");

    waitForRepurposeTestId($page, "channel-{$source->id}");

    $page->assertVisible("@channel-{$source->id}")
        ->assertMissing("@channel-{$facebook->id}")
        ->assertAttribute('@repurpose-source-avatar', 'data-platform', Platform::Facebook->value)
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

    waitForRepurposeTestId($page, 'delete-repurpose');

    $page->assertVisible('@delete-repurpose')
        ->assertNoJavaScriptErrors();
});

test('the activity list reads as what happened, never as a database id', function () {
    [$user, $workspace, $source] = repurposeOwnerWithAccounts();

    $repurpose = Repurpose::factory()->active()->create([
        'workspace_id' => $workspace->id,
        'source_social_account_id' => $source->id,
    ]);

    $withoutLink = RepurposeItem::factory()->for($repurpose)->create([
        'status' => ItemStatus::Skipped,
        'reason' => ItemReason::MediaUrlMissing,
        'source_permalink' => null,
        'source_created_at' => now()->subHour(),
    ]);

    $this->actingAs($user);

    $page = visit(route('app.repurposes.show', $repurpose));

    waitForRepurposeTestId($page, 'tab-activity');

    $page->click('@tab-activity');

    waitForRepurposeTestId($page, "repurpose-item-{$withoutLink->id}");

    $page->assertVisible("@repurpose-item-{$withoutLink->id}")
        ->assertDontSee($withoutLink->source_media_id)
        ->assertNoJavaScriptErrors();
});

test('a destination missing its required meta saves anyway but blocks activating', function () {
    [$user, $workspace, $source, $tiktok] = repurposeOwnerWithAccounts();

    $repurpose = Repurpose::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'source_social_account_id' => $source->id,
        'destinations' => [],
    ]);

    $this->actingAs($user);

    $page = visit(route('app.repurposes.show', $repurpose));

    waitForRepurposeTestId($page, 'activate-repurpose');

    $page->assertVisible('@activate-repurpose')
        ->assertMissing('@save-destinations')
        ->click("@channel-{$tiktok->id}");

    waitForRepurposeTestId($page, 'repurpose-saved');

    $saved = $repurpose->fresh()->destinations;

    expect($saved)->toHaveCount(1)
        ->and(data_get($saved, '0.social_account_id'))->toBe($tiktok->id)
        ->and($page->script('document.querySelector(\'[data-testid="activate-repurpose"]\').disabled'))->toBeTrue();

    $page->assertNoJavaScriptErrors();
});

test('an autosave the backend rejects says so instead of failing quietly', function () {
    [$user, $workspace, $source, $tiktok] = repurposeOwnerWithAccounts();

    $repurpose = Repurpose::factory()->active()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'source_social_account_id' => $source->id,
        'destinations' => [[
            'social_account_id' => $tiktok->id,
            'content_type' => ContentType::TikTokVideo->value,
            'meta' => ['privacy_level' => PrivacyLevel::PublicToEveryone->value],
        ]],
    ]);

    $this->actingAs($user);

    $page = visit(route('app.repurposes.show', $repurpose));

    waitForRepurposeTestId($page, "channel-{$tiktok->id}");

    $page->click("@channel-{$tiktok->id}");

    waitForRepurposeTestId($page, 'destinations-error');

    $page->assertVisible('@destinations-error')
        ->assertSee(trans('repurposes.errors.destinations_required'))
        ->assertNoJavaScriptErrors();

    expect($repurpose->fresh()->destinations)->toHaveCount(1);
});

test('the list shows each repurpose as a row that opens it and the back button returns', function () {
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

    $page = visit(route('app.repurposes.index'));

    waitForRepurposeTestId($page, "repurpose-row-{$repurpose->id}");

    $page->assertVisible('@create-repurpose-button')
        ->assertVisible('@header-icon')
        ->assertScript("document.querySelector('[data-testid=\"repurpose-row-{$repurpose->id}\"]').tagName", 'A')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth + 1', true)
        ->click("@repurpose-row-{$repurpose->id}");

    waitForRepurposeTestId($page, 'repurpose-back');

    $page->assertRoute('app.repurposes.show', ['repurpose' => $repurpose->id])
        ->click('@repurpose-back');

    waitForRepurposeTestId($page, 'repurposes-table');

    $page->assertRoute('app.repurposes.index')
        ->resize(390, 844)
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth + 1', true)
        ->assertNoJavaScriptErrors();
});

test('without any channel the empty state offers to connect one, like the publish page', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['account_id' => $user->account_id, 'user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    $this->actingAs($user->fresh());

    $page = visit(route('app.repurposes.index'));
    waitForRepurposeTestId($page, 'repurposes-empty');

    $page->assertVisible('@repurposes-empty-illustration')
        ->assertSeeIn('@repurposes-empty', __('repurposes.empty.title'))
        ->assertMissing('@repurposes-empty-create')
        ->assertSeeIn('@repurposes-empty-connect', __('channels.connect'))
        ->click('@repurposes-empty-connect');
    waitForRepurposeTestId($page, 'connect-channel-dialog');

    $page->assertVisible('@connect-channel-dialog')
        ->assertNoJavaScriptErrors();
});

test('with a source channel and no repurpose the empty state starts a new one', function () {
    [$user] = repurposeOwnerWithAccounts();
    $this->actingAs($user);

    $page = visit(route('app.repurposes.index'));
    waitForRepurposeTestId($page, 'repurposes-empty');

    $page->assertVisible('@repurposes-empty-illustration')
        ->assertMissing('@repurposes-empty-connect')
        ->click('@repurposes-empty-create');
    waitForRepurposeTestId($page, 'create-repurpose-dialog');

    $page->assertVisible('@create-repurpose-dialog')
        ->click('[data-testid="create-repurpose-dialog"] [data-testid="source-account-select"] button');
    waitForRepurposeTestId($page, 'source-account-option');

    expect($page->script(<<<'JS'
        [...document.querySelectorAll('[data-testid="source-account-option"]')].map((option) => [
            option.querySelector('[data-slot="avatar"]') !== null,
            option.querySelector('[data-slot="avatar"]').offsetHeight,
        ])
    JS))->toBe([[true, 32]]);
    $page->assertNoJavaScriptErrors();
});

test('the edit page reads like channel settings: source avatar, status, tabs and setting rows', function () {
    [$user, $workspace, $source, $destination] = repurposeOwnerWithAccounts();

    $repurpose = Repurpose::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'source_social_account_id' => $source->id,
        'destinations' => [],
    ]);

    $this->actingAs($user);

    $page = visit(route('app.repurposes.show', $repurpose))->resize(1440, 1000);
    waitForRepurposeTestId($page, 'repurpose-source-avatar');

    $page->assertAttribute('@repurpose-source-avatar', 'data-platform', Platform::Instagram->value)
        ->assertSeeIn('@repurpose-status', __("repurposes.status.{$repurpose->status->value}"))
        ->assertAttribute('@tab-configuration', 'aria-selected', 'true')
        ->assertVisible("@channel-row-{$destination->id}")
        ->assertAttribute("@channel-{$destination->id}", 'aria-checked', 'false')
        ->assertMissing('@flow-source-instagram');

    expect($page->script("document.querySelectorAll('[data-testid=\"repurpose-configuration\"] > hr').length"))->toBe(3);

    $page->click('@tab-activity');
    waitForRepurposeTestId($page, 'repurpose-activity-empty');

    $page->assertAttribute('@tab-activity', 'aria-selected', 'true')
        ->assertVisible('@repurposes-empty-illustration')
        ->assertMissing('@repurpose-configuration')
        ->assertNoJavaScriptErrors();
});

test('a destination row switch adds and removes the destination', function () {
    [$user, $workspace, $source] = repurposeOwnerWithAccounts();
    $youtube = SocialAccount::factory()->for($workspace)->create(['platform' => Platform::YouTube]);

    $repurpose = Repurpose::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'source_social_account_id' => $source->id,
        'destinations' => [],
    ]);

    $this->actingAs($user);

    $page = visit(route('app.repurposes.show', $repurpose));
    waitForRepurposeTestId($page, "channel-{$youtube->id}");

    $page->assertMissing("@channel-settings-{$youtube->id}")
        ->click("@channel-{$youtube->id}");
    waitForRepurposeTestId($page, "channel-settings-{$youtube->id}");
    waitForRepurposeTestId($page, 'repurpose-saved');

    $page->assertAttribute("@channel-{$youtube->id}", 'aria-checked', 'true');
    expect(data_get($repurpose->fresh()->destinations, '0.social_account_id'))->toBe($youtube->id);

    $page->click("@channel-{$youtube->id}");
    waitForRepurposeCondition($page, "!document.querySelector('[data-testid=\"channel-settings-{$youtube->id}\"]')");

    $page->assertAttribute("@channel-{$youtube->id}", 'aria-checked', 'false')
        ->assertNoJavaScriptErrors();
});

test('the watched format select and the publishing radio save', function () {
    [$user, $workspace, $source] = repurposeOwnerWithAccounts();

    $repurpose = Repurpose::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'source_social_account_id' => $source->id,
        'source_format' => SourceFormat::Reel,
        'publish_mode' => PublishMode::Publish,
    ]);

    $this->actingAs($user);

    $page = visit(route('app.repurposes.show', $repurpose));
    waitForRepurposeTestId($page, 'source-format-select');

    $page->click('@source-format-select');
    waitForRepurposeTestId($page, 'source-format-option-story');
    $page->click('@source-format-option-story');

    for ($attempt = 0; $attempt < 50 && $repurpose->fresh()->source_format !== SourceFormat::Story; $attempt++) {
        $page->script('new Promise((resolve) => setTimeout(resolve, 100))');
    }

    expect($repurpose->fresh()->source_format)->toBe(SourceFormat::Story);

    $page->assertAttribute('@publish-mode-publish', 'aria-checked', 'true')
        ->click('@publish-mode-draft');

    for ($attempt = 0; $attempt < 50 && $repurpose->fresh()->publish_mode !== PublishMode::Draft; $attempt++) {
        $page->script('new Promise((resolve) => setTimeout(resolve, 100))');
    }

    expect($repurpose->fresh()->publish_mode)->toBe(PublishMode::Draft);
    $page->assertAttribute('@publish-mode-draft', 'aria-checked', 'true')
        ->assertNoJavaScriptErrors();
});

test('the list shows each repurpose source and destinations as channel avatars', function () {
    [$user, $workspace, $source, $destination] = repurposeOwnerWithAccounts();
    $repurpose = Repurpose::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'source_social_account_id' => $source->id,
        'destinations' => [['social_account_id' => $destination->id]],
    ]);
    $this->actingAs($user);

    $page = visit(route('app.repurposes.index'))->resize(1440, 900);
    waitForRepurposeTestId($page, "repurpose-row-{$repurpose->id}");

    $page->assertSeeIn("@repurpose-row-{$repurpose->id}", $source->display_name)
        ->assertSeeIn("@repurpose-row-{$repurpose->id}", __('repurposes.formats.reel'));

    expect($page->script(<<<JS
        (() => {
            const source = document.querySelector('[data-testid="repurpose-source-{$repurpose->id}"]');
            const destinations = document.querySelector('[data-testid="repurpose-destinations-{$repurpose->id}"]');
            return [source.querySelector('[data-slot="avatar"]') !== null, destinations.querySelectorAll('[data-slot="avatar"]').length];
        })()
    JS))->toBe([true, 1]);
    $page->assertNoJavaScriptErrors();
});

test('the destination post types sit centered in their settings panel', function () {
    [$user, $workspace, $source] = repurposeOwnerWithAccounts();
    $facebook = SocialAccount::factory()->for($workspace)->create(['platform' => Platform::Facebook]);
    $repurpose = Repurpose::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'source_social_account_id' => $source->id,
        'destinations' => [['social_account_id' => $facebook->id, 'content_type' => ContentType::FacebookReel->value, 'meta' => []]],
    ]);
    $this->actingAs($user);

    $page = visit(route('app.repurposes.show', $repurpose))->resize(1440, 1000);
    waitForRepurposeTestId($page, "channel-settings-{$facebook->id}");

    expect($page->script(<<<JS
        (() => {
            const panel = document.querySelector('[data-testid="channel-settings-{$facebook->id}"]').getBoundingClientRect();
            const types = document.querySelector('[data-testid="channel-type-{$facebook->id}"]').getBoundingClientRect();
            return Math.abs((types.top - panel.top) - (panel.bottom - types.bottom)) <= 2;
        })()
    JS))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

test('on a phone the header puts the summary and the actions on their own rows', function () {
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

    $page = visit(route('app.repurposes.show', $repurpose))->resize(390, 844);
    waitForRepurposeTestId($page, 'repurpose-summary');
    waitForRepurposeTestId($page, 'repurpose-lifecycle');

    $layout = $page->script(<<<'JS'
        (() => {
            const summary = document.querySelector('[data-testid="repurpose-summary"]').getBoundingClientRect();
            const actions = document.querySelector('[data-testid="repurpose-lifecycle"]').getBoundingClientRect();
            const title = document.querySelector('[data-testid="repurpose-status"]').getBoundingClientRect();
            return { summaryWidth: Math.round(summary.width), summaryBelowTitle: summary.top >= title.bottom - 1, actionsBelow: actions.top >= summary.bottom - 1, overflow: document.documentElement.scrollWidth > window.innerWidth };
        })()
    JS);

    expect($layout['summaryWidth'])->toBeGreaterThan(300)
        ->and($layout['summaryBelowTitle'])->toBeTrue()
        ->and($layout['actionsBelow'])->toBeTrue()
        ->and($layout['overflow'])->toBeFalse();
    $page->assertNoJavaScriptErrors();
});
