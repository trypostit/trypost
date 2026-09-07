<?php

declare(strict_types=1);

use App\Enums\Repurpose\PauseReason;
use App\Enums\Repurpose\Status;
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
function waitForRepurposeHealthTestId(mixed $page, string $testId): void
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

test('a repurpose whose source was deleted explains itself instead of rendering a hole', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $workspace->id]);

    $destination = SocialAccount::factory()->for($workspace)->create(['platform' => Platform::TikTok]);

    // The state the observer leaves behind when the watched account is deleted:
    // the repurpose and its history survive, with no source to point at.
    $repurpose = Repurpose::factory()->create([
        'workspace_id' => $workspace->id,
        'source_social_account_id' => null,
        'status' => Status::Paused,
        'paused_reason' => PauseReason::SourceRemoved,
        'destinations' => [[
            'social_account_id' => $destination->id,
            'content_type' => 'tiktok_video',
            'meta' => ['privacy_level' => 'PUBLIC_TO_EVERYONE'],
        ]],
    ]);

    $this->actingAs($user);

    $page = visit(route('app.repurposes.show', $repurpose));

    waitForRepurposeHealthTestId($page, 'repurpose-health-banner');

    $page->assertSee(__('repurposes.health.source_missing'))
        ->assertSee(__('repurposes.summary.no_source'))
        // getPlatformLogo falls back to LinkedIn for an unknown platform, so
        // without its own state the flow would claim this watches LinkedIn.
        ->assertPresent('@flow-source-missing')
        ->assertNoJavaScriptErrors();
});
